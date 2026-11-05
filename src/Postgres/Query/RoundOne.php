<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Drivers\Fragment;
use Happenv\ModelSearch\Drivers\Hit;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\SearchQuery;

/**
 * Round one of a palette: ONE statement for every type of the call.
 *
 * 1. the candidates, all the branches of all types ({@see CandidateBranches}) in one `UNION ALL`;
 * 2. a record found by several routes is kept once, with the best route (level, then the weight of its
 *    field, then the exact word before the prefix, then the index over the source table, then the
 *    first branch); the other routes become its alternatives, and a record is in the first stage
 *    when any of its rows is. The records are then joined with the host query of their type by the
 *    primary key, which is the authorization;
 * 3. the stage selection: a record passes when its best hit is exact (every stage), or it is in the
 *    first stage, or the type has no non-exact hit in the first stage after authorization and no
 *    first-stage branch ran into the end of its pool (an exhausted pool says nothing about what
 *    authorization would have left, so the type must not fall to "all stages" on its word);
 * 4. the ranking: per type, level, field weight, exact word before prefix, `sortAt` newest first, the
 *    key last so the order is total;
 * 5. the result: the rows of the best `limit` records of each type, and one flag row per type with
 *    the counts that {@see TypeRound} keeps.
 *
 * The statement is a plain function of its targets: it holds the phrase only as bound parameters.
 *
 * @internal
 */
final class RoundOne
{
    /**
     * @param  array<string, array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}>  $targets  By morph alias.
     * @return array{sql: string, bindings: list<mixed>}|null Null when no branch of any type survives the phrase.
     */
    public static function sql(array $targets): ?array
    {
        $pool = self::pool();
        $branches = [];
        $types = [];
        $position = 0;

        foreach ($targets as $alias => $target) {
            $own = CandidateBranches::for($target['definition'], $target['query'], $pool, $position);
            array_push($branches, ...$own);
            $types[$position] = [
                'alias' => $alias,
                'definition' => $target['definition'],
                'query' => $target['query'],
                'host' => $target['host'],
                'branches' => $own !== [],
                'staged' => CandidateBranches::isStaged($target['definition'], $target['query']),
            ];
            $position++;
        }

        if ($branches === []) {
            return null;
        }

        $candidates = Fragment::join($branches, ' UNION ALL ');
        $weights = self::weights($types);
        $entries = TableNames::forConnection(Sql::connectionName(new ($types[0]['definition']->modelClass)))->quoted(TableNames::forConnection(Sql::connectionName(new ($types[0]['definition']->modelClass)))->entries());

        $authorized = [];
        $authorizedBindings = [];

        foreach ($types as $position => $type) {
            if (! $type['branches']) {
                continue;
            }

            $host = $type['host']->sql();
            $authorized[] = sprintf(
                'SELECT d.* FROM dd d JOIN (%s) h ON h.%s = d.subject_id::%s WHERE d.ti = %d',
                $host['sql'],
                Sql::ident(HostQuery::KEY_ALIAS),
                $type['definition']->resolvedKeySqlType(),
                $position,
            );
            array_push($authorizedBindings, ...$host['bindings']);
        }

        $values = [];
        $valueBindings = [];

        foreach ($types as $position => $type) {
            $values[] = sprintf('(%d, CAST(? AS text), %s, %d)', $position, $type['staged'] ? 'true' : 'false', $type['query']->maxResults());
            $valueBindings[] = $type['definition']->morphAlias;
        }

        $sql = sprintf(
            <<<'SQL'
WITH cand AS MATERIALIZED (
  SELECT u.*, %1$s AS w, count(*) OVER (PARTITION BY u.br) > %2$d AS is_full
  FROM (%3$s) AS u
),
dd AS (
  SELECT DISTINCT ON (c.ti, c.subject_id) c.ti, c.subject_id, c.level, c.kind, c.value, c.via, c.sim, c.w,
    bool_or(c.s0) OVER p AS in_s0,
    jsonb_agg(jsonb_build_object('kind', c.kind, 'level', c.level, 'value', c.value, 'via', c.via)) OVER p AS alts
  FROM cand c
  WINDOW p AS (PARTITION BY c.ti, c.subject_id)
  ORDER BY c.ti, c.subject_id, c.level, c.w DESC, c.sim DESC, c.src, c.kind, c.br
),
fl AS (
  SELECT c.ti,
    bool_or(c.is_full AND c.bk = %4$d) AS s0_full,
    bool_or(c.is_full AND c.bk IN (%5$d, %4$d)) AS any_full,
    bool_or(c.is_full AND c.bk = %6$d) AS all_full,
    bool_or(c.ptr) AS via_full
  FROM cand c GROUP BY c.ti
),
ty(ti, alias, staged, lim) AS (VALUES %7$s),
au AS (%8$s),
ranked AS (
  SELECT a.ti, a.subject_id, a.level, a.kind, a.value, a.via, a.alts, a.in_s0,
    row_number() OVER (PARTITION BY a.ti ORDER BY a.level, a.w DESC, a.sim DESC, e.sort_at DESC NULLS LAST, a.subject_id) AS rn,
    count(*) OVER (PARTITION BY a.ti) AS n_pass
  FROM (SELECT x.*, count(*) FILTER (WHERE x.level %% %9$d > 0 AND x.in_s0) OVER (PARTITION BY x.ti) AS ne_s0 FROM au x) a
  JOIN ty ON ty.ti = a.ti
  JOIN fl ON fl.ti = a.ti
  LEFT JOIN %10$s e ON e.subject_type = ty.alias AND e.subject_id = a.subject_id
  WHERE NOT ty.staged OR a.level %% %9$d = 0 OR a.in_s0 OR (a.ne_s0 = 0 AND NOT fl.s0_full)
)
SELECT 'row' AS rt, r.ti, r.subject_id, r.level, r.kind, r.value, r.via, r.alts::text AS alts, r.in_s0, CAST(r.rn AS integer) AS rn,
  CAST(NULL AS bigint) AS n_pass, CAST(NULL AS bigint) AS ne_s0, CAST(NULL AS boolean) AS s0_full, CAST(NULL AS boolean) AS any_full, CAST(NULL AS boolean) AS all_full, CAST(NULL AS boolean) AS via_full
FROM ranked r JOIN ty ON ty.ti = r.ti WHERE r.rn <= ty.lim
UNION ALL
SELECT 'flag', ty.ti, CAST(NULL AS text), CAST(NULL AS integer), CAST(NULL AS text), CAST(NULL AS text), CAST(NULL AS text), CAST(NULL AS text), CAST(NULL AS boolean), CAST(NULL AS integer),
  (SELECT count(*) FROM ranked r WHERE r.ti = ty.ti),
  (SELECT count(*) FROM au x WHERE x.ti = ty.ti AND x.level %% %9$d > 0 AND x.in_s0),
  coalesce(f.s0_full, false), coalesce(f.any_full, false), coalesce(f.all_full, false), coalesce(f.via_full, false)
FROM ty LEFT JOIN fl f ON f.ti = ty.ti
SQL,
            $weights->sql,
            $pool,
            $candidates->sql,
            CandidateBranches::STAGE_ZERO,
            CandidateBranches::PLAIN,
            CandidateBranches::ALL_STAGES,
            implode(', ', $values),
            implode(' UNION ALL ', $authorized),
            Hit::VIA_OFFSET,
            $entries,
        );

        return [
            'sql' => $sql,
            'bindings' => [...$weights->bindings, ...$candidates->bindings, ...$valueBindings, ...$authorizedBindings],
        ];
    }

    /**
     * Reads the rows of the statement.
     *
     * @param  list<object>  $rows
     * @param  list<string>  $aliases  The aliases of the targets, in the order they were given to {@see self::sql()}.
     * @param  array<string, int>  $limits  The limit of each alias.
     * @return array<string, TypeRound>
     */
    public static function read(array $rows, array $aliases, array $limits): array
    {
        $ranked = [];
        $flags = [];

        foreach ($rows as $row) {
            $columns = (array) $row;
            $position = self::int($columns['ti'] ?? null);

            if (($columns['rt'] ?? null) === 'flag') {
                $flags[$position] = $columns;

                continue;
            }

            $ranked[$position][self::int($columns['rn'] ?? null)] = $columns;
        }

        $rounds = [];

        foreach ($aliases as $position => $alias) {
            $flag = $flags[$position] ?? [];
            $own = $ranked[$position] ?? [];
            ksort($own);

            $rounds[$alias] = new TypeRound(
                array_values(array_map(self::candidate(...), $own)),
                self::int($flag['n_pass'] ?? null),
                self::int($flag['ne_s0'] ?? null),
                self::bool($flag['s0_full'] ?? null),
                self::bool($flag['any_full'] ?? null),
                self::bool($flag['all_full'] ?? null),
                self::bool($flag['via_full'] ?? null),
                $limits[$alias] ?? 0,
            );
        }

        return $rounds;
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private static function candidate(array $columns): CandidateRow
    {
        $value = $columns['value'] ?? null;
        $via = $columns['via'] ?? null;
        $kind = $columns['kind'] ?? '';
        $subject = $columns['subject_id'] ?? '';
        $level = self::int($columns['level'] ?? null);
        $routes = [];
        $best = [(string) (is_scalar($kind) ? $kind : ''), is_string($via) ? $via : null];

        $decoded = json_decode(is_string($columns['alts'] ?? null) ? $columns['alts'] : '[]', true);

        // One alternative per field (and relation): the strongest way that field matched. The same
        // field matching in a weaker mode too is not another way to find the record.
        foreach (is_array($decoded) ? $decoded : [] as $route) {
            if (! is_array($route)) {
                continue;
            }

            $routeKind = is_scalar($route['kind'] ?? null) ? (string) $route['kind'] : '';
            $routeLevel = self::int($route['level'] ?? null);
            $routeVia = is_string($route['via'] ?? null) ? $route['via'] : null;
            $identity = json_encode([$routeKind, $routeVia]);

            if ([$routeKind, $routeVia] === $best || (isset($routes[$identity]) && $routes[$identity]['level'] <= $routeLevel)) {
                continue;
            }

            $routes[$identity] = [
                'kind' => $routeKind,
                'level' => $routeLevel,
                'value' => is_string($route['value'] ?? null) ? $route['value'] : null,
                'via' => $routeVia,
            ];
        }

        $alternatives = array_values($routes);
        usort($alternatives, static fn (array $a, array $b): int => [$a['level'], $a['kind']] <=> [$b['level'], $b['kind']]);

        return new CandidateRow(
            is_scalar($subject) ? (string) $subject : '',
            is_scalar($kind) ? (string) $kind : '',
            $level,
            is_string($value) ? $value : null,
            is_string($via) ? $via : null,
            self::bool($columns['in_s0'] ?? null),
            $alternatives,
        );
    }

    /**
     * `CASE ti WHEN … THEN CASE kind WHEN … THEN weight … END …`: the weight of a field by type and
     * kind, with the kind and the weight bound. The name words weigh what the best of the name
     * fields that match by prefix weighs.
     *
     * @param  array<int, array{definition: CompiledDefinition, branches: bool}>  $types
     */
    private static function weights(array $types): Fragment
    {
        $sql = 'CASE u.ti';
        $bindings = [];

        foreach ($types as $position => $type) {
            $weights = [];
            $words = null;

            foreach ($type['definition']->fields as $field) {
                $weights[$field->kind] = $field->weight;

                if ($field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true)) {
                    $words = max($words ?? $field->weight, $field->weight);
                }
            }

            if ($words !== null) {
                $weights[SearchField::RESERVED_KIND] = $words;
            }

            $cases = '';

            foreach ($weights as $kind => $weight) {
                $cases .= ' WHEN CAST(? AS text) THEN CAST(? AS integer)';
                $bindings[] = (string) $kind;
                $bindings[] = $weight;
            }

            $sql .= sprintf(' WHEN %d THEN (CASE u.kind%s ELSE 0 END)', $position, $cases);
        }

        return new Fragment($sql.' ELSE 0 END', $bindings);
    }

    private static function pool(): int
    {
        $pool = config('model-search.pool');

        return is_int($pool) && $pool > 0 ? $pool : 200;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 't', 1, '1'], true);
    }
}
