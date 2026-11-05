<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Drivers;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\SearchTooBroad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Narrows a table query to a list of keys, the way every driver's `apply()` does.
 *
 * The keys travel as ONE array parameter and join the table through `JOIN unnest(…)`, so a top-level
 * `orWhere` of the table query cannot widen the result, and an empty list yields no rows. The lookup
 * that produced the keys is capped by `apply.max_ids`. The cap counts rows per branch, not distinct
 * records, and hits outside the viewer's scope count toward it (R22).
 *
 * @internal
 */
final class IdNarrowing
{
    /**
     * The most keys a lookup may return before the phrase is called too broad.
     */
    public static function maxIds(): int
    {
        $max = config('model-search.apply.max_ids');

        return is_int($max) && $max > 0 ? $max : 10000;
    }

    /**
     * What to pass as `LIMIT` for the lookup: one more than the cap, so overflow is detectable.
     */
    public static function lookupLimit(): int
    {
        return self::maxIds() + 1;
    }

    /**
     * @param  list<string>  $ids
     *
     * @throws SearchTooBroad
     */
    public static function assertWithinCap(array $ids): void
    {
        if (count($ids) > self::maxIds()) {
            throw self::tooBroad();
        }
    }

    /**
     * The refusal for a `via` path that found more parents in scope than it follows: following only some would leave records out.
     */
    public static function tooManyParents(): SearchTooBroad
    {
        return new SearchTooBroad(sprintf('The search matches more than %d parent records in scope; ask for a longer phrase.', DatabaseDriver::VIA_PARENT_LIMIT));
    }

    /**
     * The refusal for a lookup that reached the cap. It never carries the phrase.
     */
    public static function tooBroad(): SearchTooBroad
    {
        return new SearchTooBroad(sprintf('The search matches more than %d records; ask for a longer phrase.', self::maxIds()));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $tableQuery
     * @param  list<string>  $ids
     * @param  string  $keyType  The catalog type of the table's key column.
     */
    public static function narrow(Builder $tableQuery, array $ids, string $keyType): void
    {
        $list = $tableQuery->getQuery()->newQuery()->fromRaw(self::unnest($keyType), [Sql::arrayLiteral($ids)])->select('search_ids.search_id');
        $alias = 'search_ids_'.count($tableQuery->getQuery()->joins ?? []);

        if ($tableQuery->getQuery()->columns === null) {
            // Without explicit columns the join would add its own to every model.
            $from = $tableQuery->getQuery()->from;
            $from = is_string($from) ? (preg_split('/\s+as\s+/i', $from)[1] ?? $from) : $tableQuery->getModel()->getTable();
            $tableQuery->select($from.'.*');
        }

        $tableQuery->joinSub($list, $alias, $tableQuery->getModel()->getQualifiedKeyName(), '=', $alias.'.search_id');
    }

    /**
     * Refuses a key type a list of keys cannot narrow by; called when a definition compiles.
     *
     * @throws InvalidSearchDefinition
     */
    public static function assertKeyType(string $keyType): void
    {
        self::unnest($keyType);
    }

    /**
     * @return literal-string
     */
    private static function unnest(string $keyType): string
    {
        return match ($keyType) {
            'uuid' => 'unnest(?::uuid[]) AS "search_ids"("search_id")',
            'int8' => 'unnest(?::int8[]) AS "search_ids"("search_id")',
            'int4' => 'unnest(?::int4[]) AS "search_ids"("search_id")',
            'int2' => 'unnest(?::int2[]) AS "search_ids"("search_id")',
            'text' => 'unnest(?::text[]) AS "search_ids"("search_id")',
            'varchar' => 'unnest(?::varchar[]) AS "search_ids"("search_id")',
            'bpchar' => 'unnest(?::bpchar[]) AS "search_ids"("search_id")',
            default => throw new InvalidSearchDefinition(sprintf('Tables keyed by "%s" cannot be narrowed to a list of keys.', $keyType)),
        };
    }
}
