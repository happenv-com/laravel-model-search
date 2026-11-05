<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Drivers\Fragment;
use Happenv\ModelSearch\Drivers\IdNarrowing;
use Happenv\ModelSearch\Exceptions\SearchTooBroad;
use Happenv\ModelSearch\Query\SearchQuery;

/**
 * The keys behind `apply()` on the postgres driver: every record that matches the phrase, resolved on
 * the search connection so the phrase never reaches the table query, which travels on the host's
 * connection ({@see IdNarrowing} puts the list into it as one array parameter).
 *
 * One statement: the branches of {@see CandidateBranches} for a table, with no pool and no stages,
 *
 * - the keys of the definition (`Exact`, and `Prefix`/`Suffix` from four characters);
 * - the name words (`Prefix`, from four characters); `Fuzzy` is never read here (typos are for `similar()`);
 * - the source column of an `exactOnSource` field;
 * - `via`, whose parents are filtered by the scope of the PARENT before the cap of parents per path.
 *
 * The scope of the viewer is not applied (the table query applies its own). Every branch is cut at
 * `apply.max_ids + 1` rows, and a branch that reaches it, or a union of more than `max_ids` records,
 * is too broad: {@see SearchTooBroad}. So is a `via` path that found more parents in the parent's
 * scope than its cap of 50: a list that left out records would be a silently partial table.
 *
 * The cap counts ROWS per branch, not distinct records (a record with several keys of one branch
 * uses several rows), and hits outside the viewer's scope count toward it too: a wide phrase can be
 * too broad for a viewer who has few records in it. Known narrowing of v1 (R22).
 *
 * @internal
 */
final class ApplyIds
{
    /**
     * @return array{sql: string, bindings: list<mixed>}|null Null when no branch survives the phrase.
     */
    public static function sql(CompiledDefinition $definition, SearchQuery $query): ?array
    {
        $max = IdNarrowing::maxIds();
        $branches = CandidateBranches::for($definition, $query, $max, table: true);

        if ($branches === []) {
            return null;
        }

        $union = Fragment::join($branches, ' UNION ALL ');

        // One row says whether the list is too broad (a branch at its cap, or a `via` path with more parents than its cap), the others are the keys.
        return [
            'sql' => sprintf(
                'WITH u AS MATERIALIZED (SELECT x.subject_id, x.br, x.ptr FROM (%1$s) AS x) SELECT CAST(NULL AS text) AS subject_id, (SELECT count(*) FROM (SELECT br FROM u GROUP BY br HAVING count(*) > %2$d) AS g) AS broad, (SELECT count(*) FROM u WHERE ptr) AS parents UNION ALL (SELECT DISTINCT u.subject_id, 0, 0 FROM u WHERE u.subject_id IS NOT NULL LIMIT %3$d)',
                $union->sql,
                $max,
                $max + 1,
            ),
            'bindings' => $union->bindings,
        ];
    }

    /**
     * The distinct keys of the statement's rows.
     *
     * @param  list<object>  $rows
     * @return list<string>
     *
     * @throws SearchTooBroad
     */
    public static function read(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $columns = (array) $row;

            if (is_numeric($columns['parents'] ?? null) && (int) $columns['parents'] > 0) {
                throw IdNarrowing::tooManyParents();
            }

            if (is_numeric($columns['broad'] ?? null) && (int) $columns['broad'] > 0) {
                throw IdNarrowing::tooBroad();
            }

            $id = $columns['subject_id'] ?? null;

            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }

        $ids = array_values(array_unique($ids));
        IdNarrowing::assertWithinCap($ids);

        return $ids;
    }
}
