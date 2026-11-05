<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Session;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Events\SearchPrivacyUnsafe;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Decides, before every search, whether the server could write the search phrase to its logs.
 *
 * A phrase is sent as a bound parameter, but a statement can still reach a log with it: the
 * statement log, a slow-statement log, a sampled one, `auto_explain`, the debug printers. The
 * gate reads those settings and says which of them break the rule, and how long a search may run
 * so that no "slow statement" log can fire for it. An unsafe server makes a search refuse
 * (`privacy: refuse`, the default) or run and report (`privacy: warn`).
 *
 * The reading is cached for a minute as one JSON string in the package cache store.
 * `log_min_error_statement` is not judged here: whether the error of a timed out search is logged
 * with its parameters is settled in each search transaction ({@see SearchSession}).
 *
 * @internal
 */
final class PrivacyGate
{
    private const int TTL_SECONDS = 60;

    private const string EPOCH_KEY = 'model-search:privacy:epoch';

    private const array SETTINGS = [
        'log_statement',
        'log_min_duration_statement',
        'log_min_duration_sample',
        'log_statement_sample_rate',
        'log_transaction_sample_rate',
        'debug_print_parse',
        'debug_print_rewritten',
        'debug_print_plan',
        'auto_explain.log_min_duration',
    ];

    /**
     * Whether an unsafe server stops the search (the default, and the answer to an unknown value).
     */
    public function refuses(): bool
    {
        return config('model-search.privacy') !== 'warn';
    }

    /**
     * The verdict for the connection's server, from the cache when it is under a minute old. A fresh
     * unsafe reading is announced with {@see SearchPrivacyUnsafe}.
     */
    public function verdict(Connection $connection): PrivacyVerdict
    {
        [$verdict, $fresh] = $this->read($connection);

        if ($fresh) {
            $this->announce($verdict);
        }

        return $verdict;
    }

    /**
     * Like {@see self::verdict()}, but announces nothing: the caller dispatches the event itself once
     * it is outside the transaction it read the settings in (a listener may write to the database).
     *
     * @return array{PrivacyVerdict, bool} The verdict, and whether it was read from the server just now.
     */
    public function read(Connection $connection): array
    {
        $key = $this->key($connection);

        try {
            $cached = PrivacyVerdict::fromScalar($this->cache()->get($key));
        } catch (Throwable) {
            $cached = null;
        }

        if ($cached instanceof PrivacyVerdict) {
            return [$cached, false];
        }

        $verdict = $this->inspect($connection);

        try {
            $this->cache()->put($key, $verdict->toScalar(), self::TTL_SECONDS);
        } catch (Throwable) {
            // The cache only saves a query.
        }

        return [$verdict, true];
    }

    /**
     * Dispatches {@see SearchPrivacyUnsafe} for an unsafe verdict that was just read.
     */
    public function announce(PrivacyVerdict $verdict): void
    {
        if (! $verdict->isSafe()) {
            Event::dispatch(new SearchPrivacyUnsafe($verdict->violations, $this->refuses()));
        }
    }

    /**
     * Reads the settings from the server now, without the cache.
     */
    public function inspect(Connection $connection): PrivacyVerdict
    {
        // Inside a transaction a failed read must not abort it: read under a savepoint of its own.
        $pdo = $connection->getPdo();
        $inTransaction = $pdo->inTransaction();
        $open = $inTransaction ? ['SAVEPOINT model_search_settings'] : [];
        $release = $inTransaction ? ['RELEASE SAVEPOINT model_search_settings'] : [];
        $undo = $inTransaction ? ['ROLLBACK TO SAVEPOINT model_search_settings', 'RELEASE SAVEPOINT model_search_settings'] : [];

        try {
            array_map($pdo->exec(...), $open);
            $rows = $connection->select('SELECT "name", "setting", "vartype" FROM pg_settings WHERE "name" = ANY(?::text[])', [Sql::arrayLiteral(self::SETTINGS)]);
            array_map($pdo->exec(...), $release);
        } catch (QueryException) {
            array_map($pdo->exec(...), $undo);

            return new PrivacyVerdict(['the server settings could not be read, so whether a phrase could be logged is unknown']);
        }

        $settings = [];

        foreach ($rows as $row) {
            $settings[(string) $row->name] = ['setting' => (string) $row->setting, 'vartype' => (string) $row->vartype];
        }

        return self::evaluate($settings);
    }

    /**
     * The rules, on settings already read.
     *
     * @param  array<string, array{setting: string, vartype: string}>  $settings  By setting name, in the unit `pg_settings` reports (milliseconds).
     */
    public static function evaluate(array $settings): PrivacyVerdict
    {
        $violations = [];
        $thresholds = [];

        if (($settings['log_statement']['setting'] ?? 'none') === 'all') {
            $violations[] = 'log_statement is "all": every statement is logged with its parameters';
        }

        $sampling = (float) ($settings['log_statement_sample_rate']['setting'] ?? 0) > 0;
        // An unloaded module leaves no typed setting behind, only an untyped placeholder at most.
        $autoExplain = ($settings['auto_explain.log_min_duration']['vartype'] ?? '') === 'integer';

        foreach ([
            'log_min_duration_statement' => true,
            'auto_explain.log_min_duration' => $autoExplain,
            'log_min_duration_sample' => $sampling,
        ] as $name => $active) {
            $value = isset($settings[$name]) && $active ? (int) $settings[$name]['setting'] : -1;

            if ($value < 0) {
                continue;
            }

            if ($value === 0) {
                $violations[] = sprintf('%s is 0: every statement is logged with its parameters', $name);
            } elseif ($value <= PrivacyVerdict::MARGIN_MS) {
                $violations[] = sprintf('%s is %d ms: no search can be stopped %d ms before it', $name, $value, PrivacyVerdict::MARGIN_MS);
            } else {
                $thresholds[] = $value;
            }
        }

        if ((float) ($settings['log_transaction_sample_rate']['setting'] ?? 0) > 0) {
            $violations[] = 'log_transaction_sample_rate is above 0: every statement of a sampled transaction is logged';
        }

        foreach (['debug_print_parse', 'debug_print_rewritten', 'debug_print_plan'] as $name) {
            if (($settings[$name]['setting'] ?? 'off') === 'on') {
                $violations[] = sprintf('%s is on: statements are printed to the log', $name);
            }
        }

        return new PrivacyVerdict($violations, $thresholds === [] ? null : min($thresholds) - PrivacyVerdict::MARGIN_MS);
    }

    /**
     * Forgets every cached verdict, so the next search reads the server again.
     */
    public function flush(): void
    {
        try {
            $this->cache()->put(self::EPOCH_KEY, bin2hex(random_bytes(6)), 86400 * 30);
        } catch (Throwable) {
            // The verdicts expire by themselves within a minute.
        }
    }

    private function key(Connection $connection): string
    {
        try {
            $epoch = $this->cache()->get(self::EPOCH_KEY);
        } catch (Throwable) {
            $epoch = null;
        }

        return sprintf('model-search:privacy:%s:%s:%s', is_string($epoch) ? $epoch : '0', $connection->getName(), $connection->getDatabaseName());
    }

    private function cache(): Repository
    {
        $store = config('model-search.cache_store');

        return Cache::store(is_string($store) ? $store : null);
    }
}
