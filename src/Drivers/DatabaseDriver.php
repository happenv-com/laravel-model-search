<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Closure;
use Happenv\ModelSearch\Compilation\ColumnTypes;
use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Exceptions\SearchFailed;
use Happenv\ModelSearch\Exceptions\SearchTimedOut;
use Happenv\ModelSearch\Normalization\LikeEscaper;
use Happenv\ModelSearch\Normalization\Normalizer;
use Happenv\ModelSearch\Normalization\PhraseShape;
use Happenv\ModelSearch\Normalization\QueryVariants;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\RevealPolicy;
use Happenv\ModelSearch\Query\SearchMatch;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResult;
use Happenv\ModelSearch\Query\SearchResults;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Serves a definition straight from its source tables, with no index.
 *
 * It is the driver for small tables, for tests, and for the time before an index is ready. It is
 * not equivalent to the indexed driver:
 *
 * - there is no typo tolerance: `similar()` finds the words of the phrase inside names (`ILIKE
 *   '%word%'`) and nothing else;
 * - values are folded for case and key separators in SQL, not for diacritics, so a name is also
 *   looked up in the form it was typed and in the viewer's locale variants;
 * - a custom normalizer cannot run in SQL, so a field that has one is compared case-insensitively
 *   against the normalizer's output and must store that form;
 * - `via` fields are not served: a related record needs a scope of its own, which a single
 *   `within()` cannot carry, so the driver stays closed rather than guess;
 * - cost depends on the indexes of the application, every search scans.
 *
 * Statements are single SQL statements whose search phrase is a bound parameter; on PostgreSQL
 * they run in a transaction with a literal `statement_timeout`, which is restored when the search
 * runs inside the caller's transaction.
 */
final readonly class DatabaseDriver implements Driver
{
    /** Key values with separators removed and case folded; the form the index stores. */
    private const string KEY_FOLD = <<<'SQL'
lower(regexp_replace(c."value", '[\s\-_.\/#+,:;]+', '', 'g'))
SQL;

    /** A name with apostrophes removed and word separators turned into spaces, led by a space. */
    private const string NAME_FORM = <<<'SQL'
' ' || regexp_replace(regexp_replace(c."value", '[''’`]+', '', 'g'), '[\s\-_.\/#+,:;]+', ' ', 'g')
SQL;

    private const string POSTGRES_TIMEOUT_STATE = '57014';

    public function __construct(private CompiledDefinition $definition) {}

    #[\Override]
    public function get(SearchQuery $query): SearchResults
    {
        $hits = $this->hits($query, forTable: false, fuzzy: false);

        if (! $hits instanceof Fragment) {
            return new SearchResults;
        }

        $stage = $query->stagesEnabled() ? $this->definition->constrainedStage() : null;
        $host = $query->scope();

        try {
            [$rows, $restricted] = $this->run($this->timeout('search'), function () use ($query, $hits, $host, $stage): array {
                $restricted = $stage instanceof SearchStage;
                $rows = $this->select($this->rowsSql($query, $hits, $host, $stage, $restricted));

                if ($restricted && array_all($rows, static fn (Hit $hit): bool => $hit->level === 0)) {
                    $restricted = false;
                    $rows = $this->select($this->rowsSql($query, $hits, $host, $stage, false));
                }

                return [$rows, $restricted];
            });
        } catch (SearchTimedOut) {
            return new SearchResults(timedOut: true);
        }

        $settled = $stage instanceof SearchStage ? ($restricted ? $stage->key : SearchStage::ALL) : null;

        return new SearchResults($this->results($rows, $host, $stage), false, $settled);
    }

    #[\Override]
    public function similar(SearchQuery $query): SearchResults
    {
        $hits = $this->hits($query, forTable: false, fuzzy: true);

        if (! $hits instanceof Fragment) {
            return new SearchResults;
        }

        $stage = $this->definition->constrainedStage();
        $host = $query->scope();

        try {
            $rows = $this->run($this->timeout('similar'), fn (): array => $this->select($this->rowsSql($query, $hits, $host, $stage, false)));
        } catch (SearchTimedOut) {
            return new SearchResults(timedOut: true);
        }

        return new SearchResults($this->results($rows, $host, $stage));
    }

    #[\Override]
    public function apply(SearchQuery $query, Builder $tableQuery): Builder
    {
        $hits = $this->hits($query, forTable: true, fuzzy: false);

        if (! $hits instanceof Fragment) {
            $tableQuery->whereRaw('1 = 0');

            return $tableQuery;
        }

        $rows = $this->run($this->timeout('search'), fn (): array => $this->select($this->idsSql($query, $hits)));
        $ids = array_values(array_unique(array_map(static fn (Hit $hit): string => $hit->rootId, $rows)));

        if ($ids === []) {
            $tableQuery->whereRaw('1 = 0');

            return $tableQuery;
        }

        $tableQuery->whereIn($tableQuery->getModel()->getQualifiedKeyName(), $ids);

        return $tableQuery;
    }

    #[\Override]
    public function candidates(SearchQuery $query): Collection
    {
        $hits = $this->hits($query, forTable: false, fuzzy: false);

        if (! $hits instanceof Fragment) {
            return new Collection;
        }

        $rows = $this->run($this->timeout('search'), fn (): array => $this->select($this->candidatesSql($query, $hits)));
        $stage = $this->definition->constrainedStage();

        return new Collection(array_map(fn (Hit $hit): array => [
            'type' => $this->definition->morphAlias,
            'id' => $hit->rootId,
            'mode' => Mode::fromRank($hit->level),
            'kind' => $hit->kind,
            'value' => $hit->value,
            'stage' => $this->stageOf($hit, $stage),
        ], $rows));
    }

    /**
     * The union of everything that matches the phrase, one row per matching field and record:
     * `(root_id, kind, level, weight, value, root_key)`, the key once as text and once typed.
     * Null when nothing can match.
     */
    private function hits(SearchQuery $query, bool $forTable, bool $fuzzy): ?Fragment
    {
        $phrase = $query->phraseText();
        $locale = $query->viewerLocale();
        $branches = [];

        if (! $fuzzy) {
            foreach ($this->definition->fields as $field) {
                $modes = $this->keyModes($field, $query);

                if ($modes !== [] && $field->compiledPath !== null) {
                    $branch = $this->keyBranch($field, $modes, $phrase, $locale, $forTable);

                    if ($branch instanceof Fragment) {
                        $branches[] = $branch;
                    }
                }
            }
        }

        if (! PhraseShape::isIdentifierLike($phrase)) {
            $names = array_values(array_filter(
                $this->definition->fields,
                fn (CompiledField $field): bool => $this->isNameField($field, $query, $fuzzy),
            ));
            $sets = $this->wordSets($phrase, $locale, $this->minLength($fuzzy ? Mode::Fuzzy : Mode::Prefix, $forTable));

            if ($names !== [] && $sets !== []) {
                $branches[] = $this->nameBranch($names, $sets, $fuzzy);
            }
        }

        if ($branches === []) {
            return null;
        }

        $union = Fragment::join(array_map(static fn (Fragment $branch): Fragment => new Fragment('('.$branch->sql.')', $branch->bindings), $branches), ' UNION ALL ');

        return new Fragment(
            sprintf('SELECT u."root_id", u."kind", u."level", u."weight", u."value", u."root_id"::%s AS "root_key" FROM (%s) AS u', $this->keyType(), $union->sql),
            $union->bindings,
        );
    }

    /**
     * @return list<Mode>
     */
    private function keyModes(CompiledField $field, SearchQuery $query): array
    {
        if ($field->type !== FieldType::Key || ! $this->isChosen($field, $query)) {
            return [];
        }

        return array_values(array_filter(
            [Mode::Exact, Mode::Prefix, Mode::Suffix, Mode::Infix],
            static fn (Mode $mode): bool => in_array($mode, $field->modes, true) && ($query->onlyModes() === [] || in_array($mode, $query->onlyModes(), true)),
        ));
    }

    private function isNameField(CompiledField $field, SearchQuery $query, bool $fuzzy): bool
    {
        if ($field->type !== FieldType::Name || ! $field->compiledPath instanceof CompiledPath || ! $this->isChosen($field, $query)) {
            return false;
        }

        $mode = $fuzzy ? Mode::Fuzzy : Mode::Prefix;

        return in_array($mode, $field->modes, true) && ($fuzzy || $query->onlyModes() === [] || in_array($mode, $query->onlyModes(), true));
    }

    private function isChosen(CompiledField $field, SearchQuery $query): bool
    {
        return $query->onlyKinds() === [] || in_array($field->kind, $query->onlyKinds(), true);
    }

    private function minLength(Mode $mode, bool $forTable): int
    {
        return match ($mode) {
            Mode::Exact => 1,
            Mode::Prefix => $forTable ? 4 : 3,
            Mode::Suffix, Mode::Infix => 6,
            Mode::Fuzzy => 3,
            Mode::FullText => PHP_INT_MAX,
        };
    }

    /**
     * @param  list<Mode>  $modes
     */
    private function keyBranch(CompiledField $field, array $modes, string $phrase, ?string $locale, bool $forTable): ?Fragment
    {
        $custom = $field->normalizer?->normalize($phrase);
        $variants = $field->normalizer instanceof Normalizer
            ? ($custom === null || $custom === '' ? [] : [$custom])
            : QueryVariants::keysFor($phrase, $locale);
        $cases = [];
        $bindings = [];

        foreach ($modes as $mode) {
            $usable = array_values(array_filter($variants, fn (string $variant): bool => mb_strlen($variant) >= $this->minLength($mode, $forTable)));

            if ($usable === []) {
                continue;
            }

            [$condition, $values] = match ($mode) {
                Mode::Exact => ['t."f" = ANY(?::text[])', $usable],
                Mode::Prefix => ['t."f" LIKE ANY(?::text[])', array_map(static fn (string $v): string => LikeEscaper::escape($v).'%', $usable)],
                Mode::Suffix => ['t."f" LIKE ANY(?::text[])', array_map(static fn (string $v): string => '%'.LikeEscaper::escape($v), $usable)],
                default => ['t."f" LIKE ANY(?::text[])', array_map(static fn (string $v): string => '%'.LikeEscaper::escape($v).'%', $usable)],
            };
            $cases[] = sprintf('WHEN %s THEN %d', $condition, $mode->rank());
            $bindings[] = Sql::arrayLiteral($values);
        }

        if ($cases === [] || ! $field->compiledPath instanceof CompiledPath) {
            return null;
        }

        $contribution = Fragment::from($field->compiledPath->contributionSql(null));
        $fold = $field->normalizer instanceof Normalizer ? 'lower(c."value")' : self::KEY_FOLD;

        return new Fragment(
            sprintf(
                'SELECT x."root_id", x."kind", x."level", x."weight", x."value" FROM (SELECT t."root_id", CAST(? AS text) AS "kind", CAST(? AS integer) AS "weight", t."value", CASE %s END AS "level" FROM (SELECT c."root_id", c."value", %s AS "f" FROM (%s) AS c) AS t) AS x WHERE x."level" IS NOT NULL',
                implode(' ', $cases),
                $fold,
                $contribution->sql,
            ),
            [$field->kind, $field->weight, ...$bindings, ...$contribution->bindings],
        );
    }

    /**
     * Every way the phrase can be read as a list of name words: the neutral and locale forms, and
     * the form as typed. A set qualifies when its longest word reaches the minimum length.
     *
     * @return list<list<string>>
     */
    private function wordSets(string $phrase, ?string $locale, int $minLength): array
    {
        $typed = preg_split('/[\s\-_.\/#+,:;]+/u', str_replace(["'", '’', '`'], '', mb_strtolower(trim($phrase))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sets = [$typed];

        foreach (QueryVariants::for($phrase, $locale) as $variant) {
            $sets[] = preg_split('/[\s\-]+/u', $variant, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $kept = [];

        foreach ($sets as $words) {
            $words = array_values(array_unique($words));

            if ($words !== [] && max(array_map(mb_strlen(...), $words)) >= $minLength) {
                $kept[implode("\n", $words)] = $words;
            }
        }

        return array_values($kept);
    }

    /**
     * @param  list<CompiledField>  $fields
     * @param  list<list<string>>  $sets
     */
    private function nameBranch(array $fields, array $sets, bool $fuzzy): Fragment
    {
        $pattern = static fn (string $word): string => ($fuzzy ? '%' : '% ').LikeEscaper::escape($word).'%';
        $columns = [];
        $conditions = [];
        $picks = [];

        foreach ($sets as $words) {
            $indexes = [];
            $longest = '';

            foreach ($words as $word) {
                $columns[$pattern($word)] ??= count($columns);
                $indexes[] = $columns[$pattern($word)];
                $longest = mb_strlen($word) > mb_strlen($longest) ? $word : $longest;
            }

            $conditions[] = '('.implode(' AND ', array_map(static fn (int $index): string => sprintf('bool_or(m."w%d")', $index), array_values(array_unique($indexes)))).')';
            $picks[$pattern($longest)] = true;
        }

        $selects = [];
        $bindings = [];

        foreach (array_keys($columns) as $index => $text) {
            $selects[] = sprintf('(n."s" ILIKE ?) AS "w%d"', $index);
            $bindings[] = $text;
        }

        $pick = implode(' OR ', array_map(static fn (): string => 'n."s" ILIKE ?', array_keys($picks)));
        array_push($bindings, ...array_map(strval(...), array_keys($picks)));

        $unions = [];

        foreach ($fields as $field) {
            $contribution = Fragment::from($field->compiledPath?->contributionSql(null) ?? ['sql' => 'SELECT NULL::text AS "root_id", NULL::text AS "value" WHERE false', 'bindings' => []]);
            $unions[] = new Fragment(
                sprintf(
                    'SELECT c."root_id", CAST(? AS text) AS "kind", CAST(? AS integer) AS "weight", c."value", %s AS "s" FROM (%s) AS c',
                    self::NAME_FORM,
                    $contribution->sql,
                ),
                [$field->kind, $field->weight, ...$contribution->bindings],
            );
        }

        $union = Fragment::join($unions, ' UNION ALL ');

        return new Fragment(
            sprintf(
                'SELECT m."root_id", (array_agg(m."kind" ORDER BY m."kind", m."value") FILTER (WHERE m."pick"))[1] AS "kind", %d AS "level", max(m."weight") FILTER (WHERE m."pick") AS "weight", (array_agg(m."value" ORDER BY m."kind", m."value") FILTER (WHERE m."pick"))[1] AS "value" FROM (SELECT n."root_id", n."kind", n."weight", n."value", %s, (%s) AS "pick" FROM (%s) AS n) AS m GROUP BY m."root_id" HAVING %s',
                ($fuzzy ? Mode::Fuzzy : Mode::Prefix)->rank(),
                implode(', ', $selects),
                $pick,
                $union->sql,
                implode(' OR ', $conditions),
            ),
            [...$bindings, ...$union->bindings],
        );
    }

    /**
     * The best hit of every record that is in scope, ranked and limited.
     *
     * @param  SearchStage|null  $stage  The constrained stage whose membership is reported.
     * @param  bool  $restricted  Keep only exact hits and hits of records in that stage.
     */
    private function rowsSql(SearchQuery $query, Fragment $hits, HostQuery $host, ?SearchStage $stage, bool $restricted): Fragment
    {
        $model = new ($this->definition->modelClass);
        $inStage = $this->inStage($stage);
        $restriction = $stage instanceof SearchStage && $restricted ? $this->inStage($stage, 'EXISTS') : null;
        $hostSql = $host->sql();
        $partition = $query->partitionFilter();
        $sortAt = $this->definition->sortAt === null ? 'CAST(NULL AS timestamp)' : 'r.'.Sql::ident($this->definition->sortAt);

        $where = [];
        $bindings = [...$inStage->bindings, ...$hits->bindings, ...$hostSql['bindings']];

        if ($partition !== null) {
            $where[] = sprintf('r.%s = ?', Sql::ident($partition['column']));
            $bindings[] = $partition['value'];
        }

        if ($restriction instanceof Fragment) {
            $where[] = sprintf('(h."level" = 0 OR %s)', $restriction->sql);
            array_push($bindings, ...$restriction->bindings);
        }

        return new Fragment(
            sprintf(
                'SELECT ranked."root_id", ranked."kind", ranked."level", ranked."weight", ranked."value", ranked."in_stage" FROM (SELECT DISTINCT ON (h."root_id") h."root_id", h."kind", h."level", h."weight", h."value", %1$s AS "sort_at", %2$s AS "in_stage" FROM (%3$s) AS h JOIN (%4$s) AS "host" ON "host".%5$s = h."root_key" JOIN %6$s AS r ON r.%7$s = h."root_key"%8$s ORDER BY h."root_id", h."level", h."weight" DESC, h."kind") AS ranked ORDER BY ranked."level", ranked."weight" DESC, ranked."sort_at" DESC NULLS LAST, ranked."root_id" LIMIT %9$d',
                $sortAt,
                $inStage->sql,
                $hits->sql,
                $hostSql['sql'],
                Sql::ident(HostQuery::KEY_ALIAS),
                Sql::table($model),
                Sql::ident($model->getKeyName()),
                $where === [] ? '' : ' WHERE '.implode(' AND ', $where),
                $query->maxResults(),
            ),
            $bindings,
        );
    }

    /**
     * The key of every record that matches, in scope and in the partition.
     */
    private function idsSql(SearchQuery $query, Fragment $hits): Fragment
    {
        $model = new ($this->definition->modelClass);
        $hostSql = $query->scope()->sql();
        $partition = $query->partitionFilter();
        $bindings = [...$hits->bindings, ...$hostSql['bindings']];
        $where = '';

        if ($partition !== null) {
            $where = sprintf(' WHERE r.%s = ?', Sql::ident($partition['column']));
            $bindings[] = $partition['value'];
        }

        return new Fragment(
            sprintf(
                'SELECT DISTINCT h."root_id" FROM (%1$s) AS h JOIN (%2$s) AS "host" ON "host".%3$s = h."root_key" JOIN %4$s AS r ON r.%5$s = h."root_key"%6$s',
                $hits->sql,
                $hostSql['sql'],
                Sql::ident(HostQuery::KEY_ALIAS),
                Sql::table($model),
                Sql::ident($model->getKeyName()),
                $where,
            ),
            $bindings,
        );
    }

    private function candidatesSql(SearchQuery $query, Fragment $hits): Fragment
    {
        $model = new ($this->definition->modelClass);
        $inStage = $this->inStage($this->definition->constrainedStage());
        $partition = $query->partitionFilter();
        $bindings = [...$inStage->bindings, ...$hits->bindings];
        $where = '';

        if ($partition !== null) {
            $where = sprintf(' WHERE r.%s = ?', Sql::ident($partition['column']));
            $bindings[] = $partition['value'];
        }

        $pool = config('model-search.pool');

        return new Fragment(
            sprintf(
                'SELECT h."root_id", h."kind", h."level", h."weight", h."value", %1$s AS "in_stage" FROM (%2$s) AS h JOIN %3$s AS r ON r.%4$s = h."root_key"%5$s ORDER BY h."level", h."weight" DESC, h."root_id", h."kind" LIMIT %6$d',
                $inStage->sql,
                $hits->sql,
                Sql::table($model),
                Sql::ident($model->getKeyName()),
                $where,
                is_int($pool) && $pool > 0 ? $pool : 200,
            ),
            $bindings,
        );
    }

    /**
     * `CASE WHEN EXISTS (…) THEN 1 ELSE 0 END` for the hit's record, or, with `EXISTS`, the bare condition.
     */
    private function inStage(?SearchStage $stage, string $form = 'CASE'): Fragment
    {
        if (! $stage instanceof SearchStage) {
            return new Fragment('0');
        }

        $exists = $this->definition->stageExists($stage, 'h.root_key');

        return $form === 'EXISTS'
            ? Fragment::from($exists)
            : new Fragment(sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $exists['sql']), $exists['bindings']);
    }

    /**
     * @param  list<Hit>  $rows
     * @return list<SearchResult>
     */
    private function results(array $rows, HostQuery $host, ?SearchStage $stage): array
    {
        $models = $host->hydrate(array_map(static fn (Hit $hit): string => $hit->rootId, $rows))
            ->keyBy(static fn (Model $model): string => (string) $model->getKey());
        $reveal = new RevealPolicy($this->definition);
        $viewer = Auth::user();
        $results = [];

        foreach ($rows as $hit) {
            $model = $models->get($hit->rootId);

            if (! $model instanceof Model) {
                continue;
            }

            $field = array_find($this->definition->fields, static fn (CompiledField $field): bool => $field->kind === $hit->kind);
            $match = new SearchMatch(
                kind: $hit->kind,
                label: $field?->label instanceof Closure ? ($field->label)() : Str::headline($hit->kind),
                value: $hit->value,
                mode: Mode::fromRank($hit->level),
                stage: $this->stageOf($hit, $stage),
            );

            $results[] = new SearchResult($model, $reveal->apply($match, $model, $viewer));
        }

        return $results;
    }

    private function stageOf(Hit $hit, ?SearchStage $stage): ?string
    {
        if (! $stage instanceof SearchStage) {
            return null;
        }

        return $hit->inStage ? $stage->key : SearchStage::ALL;
    }

    private function keyType(): string
    {
        $model = new ($this->definition->modelClass);

        return $this->definition->keySqlType ?? new ColumnTypes()->of($model, $model->getTable(), $model->getKeyName());
    }

    private function connection(): Connection
    {
        return (new ($this->definition->modelClass))->getConnection();
    }

    /**
     * @return list<Hit>
     */
    private function select(Fragment $fragment): array
    {
        return array_values(array_map(Hit::fromRow(...), $this->connection()->select($fragment->sql, $fragment->bindings)));
    }

    /**
     * @param  'search'|'similar'  $setting
     */
    private function timeout(string $setting): int
    {
        $milliseconds = config('model-search.'.$setting.'.timeout_ms');

        return is_int($milliseconds) && $milliseconds > 0 ? $milliseconds : ($setting === 'search' ? 300 : 1000);
    }

    /**
     * Runs the work in one place that owns the statement timeout and the handling of failures.
     *
     * A statement that runs out of time raises {@see SearchTimedOut}. Any other database failure is
     * rethrown as {@see SearchFailed} without the original, whose message carries the SQL and with
     * it the phrase.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function run(int $timeoutMs, Closure $work): mixed
    {
        $connection = $this->connection();

        try {
            if ($connection->getDriverName() !== 'pgsql') {
                return $work();
            }

            $nested = $connection->transactionLevel() > 0;
            $previous = $nested ? $this->currentTimeout($connection) : null;

            try {
                return $connection->transaction(static function () use ($connection, $timeoutMs, $work): mixed {
                    $connection->statement('SET LOCAL statement_timeout = '.$timeoutMs);

                    return $work();
                });
            } finally {
                if ($previous !== null) {
                    $connection->select("select set_config('statement_timeout', ?, true)", [$previous]);
                }
            }
        } catch (QueryException $exception) {
            $state = (string) $exception->getCode();

            throw $state === self::POSTGRES_TIMEOUT_STATE
                ? new SearchTimedOut('The search ran out of time.')
                : new SearchFailed(sprintf('The search statement failed (SQLSTATE %s).', $state));
        }
    }

    private function currentTimeout(Connection $connection): string
    {
        $row = $connection->selectOne("select current_setting('statement_timeout') as value");

        return is_object($row) && isset($row->value) && is_string($row->value) ? $row->value : '0';
    }
}
