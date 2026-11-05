<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Exceptions\InstallRefused;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * The per-type state row: which definition (fingerprint and version) the index was installed for,
 * whether the index has ever been complete, and where a rebuild stands.
 *
 * Installing is explicit and per type, and a rebuild never installs. An install refuses to lower the
 * version, which is how the previous release (still running during a blue/green deploy) is kept
 * from taking a type back, and it does not take over a type whose stored fingerprint differs at the
 * same version. A changed definition resets the rebuild cursor but keeps `ready_at`: the index is
 * served in its old shape while the rebuild replaces it, so there is no window without search.
 *
 * Ownership: a release applies the change log and rebuilds a type only when the type has no state
 * row or the stored fingerprint equals the fingerprint of the release's own definition. Reverting
 * to an older release therefore requires bumping `ModelSearch::version()` and installing again;
 * otherwise no release owns the type and its log rows wait.
 *
 * Flagging a rebuild (the drain's oversized-log switch and the handoff of aged log rows) and
 * re-queueing failed records (`PoisonRecords::requeueDue`) may act on types this release does not
 * own: they only set a flag or enqueue a row. (Two live releases with mixed readers for longer than
 * `drain.foreign_grace_seconds` flag full rebuilds of each other's types: no data loss, wasted work.) Applying changes and rebuilding stay owner-only.
 *
 * `ready_at` and the pending-rebuild flag are cached for a few seconds as one short string, per
 * connection and database; the cache is only a way to spare the
 * database on the hot path, never a source of truth.
 */
final readonly class IndexState
{
    private const int READY_TTL_SECONDS = 10;

    public function __construct(private ?string $connection = null) {}

    /**
     * Whether the type's index has been built completely at least once.
     */
    public function isReady(string $subjectType): bool
    {
        return $this->flags($subjectType)[0];
    }

    /**
     * Whether a rebuild of the type is pending (the type was installed or its definition changed).
     * Searches use the existing index meanwhile, which may miss what the rebuild is yet to write.
     */
    public function isRebuilding(string $subjectType): bool
    {
        return $this->flags($subjectType)[1];
    }

    /**
     * Readiness and rebuild flag as one cached string.
     *
     * @return array{bool, bool}
     */
    private function flags(string $subjectType): array
    {
        $key = $this->cacheKey($subjectType);

        try {
            $cached = $this->cache()->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached) && preg_match('/^[01]:[01]$/', $cached) === 1) {
            return [$cached[0] === '1', $cached[2] === '1'];
        }

        $row = $this->find($subjectType);
        $ready = ($row['ready_at'] ?? null) !== null;
        $rebuilding = ($row['rebuild_cursor'] ?? null) !== null;

        try {
            $this->cache()->put($key, ($ready ? '1' : '0').':'.($rebuilding ? '1' : '0'), self::READY_TTL_SECONDS);
        } catch (Throwable) {
            // The cache only saves a query.
        }

        return [$ready, $rebuilding];
    }

    /**
     * Records that the type is installed for the given definition.
     *
     * @param  int|null  $version  Defaults to the definition's own version.
     *
     * @throws InstallRefused When the stored version is higher, or the same with another fingerprint.
     */
    public function recordInstall(CompiledDefinition $definition, ?int $version = null): InstallOutcome
    {
        $type = $definition->morphAlias;
        $version ??= $definition->version;
        $fingerprint = Fingerprint::of($definition);
        $table = $this->table();

        $outcome = $this->db()->transaction(function () use ($table, $type, $version, $fingerprint): InstallOutcome {
            $db = $this->db();
            $inserted = $db->affectingStatement(
                sprintf('INSERT INTO %s ("subject_type", "definition_fingerprint", "definition_version", "installed_at", "rebuild_cursor", "rebuild_started_at") VALUES (?, ?, ?, clock_timestamp(), \'\', clock_timestamp()) ON CONFLICT ("subject_type") DO NOTHING', $table),
                [$type, $fingerprint, $version],
            );

            if ($inserted === 1) {
                return InstallOutcome::Installed;
            }

            $row = $db->selectOne(sprintf('SELECT "definition_fingerprint", "definition_version" FROM %s WHERE "subject_type" = ? FOR UPDATE', $table), [$type]);

            if ($row === null) {
                throw new \LogicException('The state row vanished while it was being installed.');
            }

            $storedVersion = (int) $row->definition_version;

            if ($storedVersion > $version) {
                throw InstallRefused::downgrade($type, $storedVersion, $version);
            }

            if ($storedVersion === $version) {
                if ($row->definition_fingerprint !== $fingerprint) {
                    throw InstallRefused::foreignFingerprint($type, $version);
                }

                return InstallOutcome::Unchanged;
            }

            $db->update(
                sprintf('UPDATE %s SET "definition_fingerprint" = ?, "definition_version" = ?, "installed_at" = clock_timestamp(), "rebuild_cursor" = \'\', "rebuild_started_at" = clock_timestamp(), "sweep_cursor" = NULL WHERE "subject_type" = ?', $table),
                [$fingerprint, $version, $type],
            );

            return InstallOutcome::Updated;
        });

        $this->forget($type);

        return $outcome;
    }

    /**
     * @return array{subject_type: string, definition_fingerprint: string, definition_version: int, installed_at: string, ready_at: string|null, rebuild_cursor: string|null, rebuild_started_at: string|null, sweep_cursor: string|null}|null
     */
    public function find(string $subjectType): ?array
    {
        $row = $this->db()->selectOne(sprintf('SELECT * FROM %s WHERE "subject_type" = ?', $this->table()), [$subjectType]);

        return $row === null ? null : $this->row((array) $row);
    }

    /**
     * Types that have a rebuild pending, oldest first.
     *
     * @return list<array{subject_type: string, definition_fingerprint: string, definition_version: int, installed_at: string, ready_at: string|null, rebuild_cursor: string|null, rebuild_started_at: string|null, sweep_cursor: string|null}>
     */
    public function pendingRebuilds(): array
    {
        $rows = $this->db()->select(sprintf('SELECT * FROM %s WHERE "rebuild_cursor" IS NOT NULL ORDER BY "rebuild_started_at" NULLS LAST, "subject_type"', $this->table()));

        return array_values(array_map(fn (stdClass $row): array => $this->row((array) $row), $rows));
    }

    /**
     * Sends the given types back to the start of a rebuild. Returns the types that were marked;
     * a type that was never installed is left alone (a rebuild never installs).
     *
     * @param  list<string>  $subjectTypes
     * @return list<string>
     */
    public function markForRebuild(array $subjectTypes): array
    {
        if ($subjectTypes === []) {
            return [];
        }

        $rows = $this->db()->select(
            sprintf('UPDATE %s SET "rebuild_cursor" = \'\', "rebuild_started_at" = clock_timestamp(), "sweep_cursor" = NULL WHERE "subject_type" = ANY(?::text[]) RETURNING "subject_type"', $this->table()),
            [Sql::arrayLiteral($subjectTypes)],
        );

        $marked = array_map(static fn (stdClass $row): string => (string) $row->subject_type, $rows);
        sort($marked);

        foreach ($marked as $type) {
            $this->forget($type);
        }

        return $marked;
    }

    /**
     * The fingerprint each installed type was installed for.
     *
     * @return array<string, string>
     */
    public function fingerprints(): array
    {
        $fingerprints = [];

        foreach ($this->db()->select(sprintf('SELECT "subject_type", "definition_fingerprint" FROM %s', $this->table())) as $row) {
            $fingerprints[(string) $row->subject_type] = (string) $row->definition_fingerprint;
        }

        return $fingerprints;
    }

    public function forget(string $subjectType): void
    {
        try {
            $this->cache()->forget($this->cacheKey($subjectType));
        } catch (Throwable) {
            // See isReady().
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{subject_type: string, definition_fingerprint: string, definition_version: int, installed_at: string, ready_at: string|null, rebuild_cursor: string|null, rebuild_started_at: string|null, sweep_cursor: string|null}
     */
    private function row(array $row): array
    {
        return [
            'subject_type' => (string) $row['subject_type'],
            'definition_fingerprint' => (string) $row['definition_fingerprint'],
            'definition_version' => (int) $row['definition_version'],
            'installed_at' => (string) $row['installed_at'],
            'ready_at' => isset($row['ready_at']) ? (string) $row['ready_at'] : null,
            'rebuild_cursor' => isset($row['rebuild_cursor']) ? (string) $row['rebuild_cursor'] : null,
            'rebuild_started_at' => isset($row['rebuild_started_at']) ? (string) $row['rebuild_started_at'] : null,
            'sweep_cursor' => isset($row['sweep_cursor']) ? (string) $row['sweep_cursor'] : null,
        ];
    }

    private function cacheKey(string $subjectType): string
    {
        $connection = $this->db();

        return sprintf('model-search:state:%s:%s:%s:%s', $connection->getName(), $connection->getDatabaseName(), TableNames::forConnection($this->connection)->prefix(), $subjectType);
    }

    private function cache(): Repository
    {
        $store = config('model-search.cache_store');

        return Cache::store(is_string($store) ? $store : null);
    }

    private function table(): string
    {
        return TableNames::forConnection($this->connection)->quoted(TableNames::forConnection($this->connection)->state());
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
