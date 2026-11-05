<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;

/**
 * The indexes the registered definitions need, as raw `CREATE INDEX CONCURRENTLY IF NOT EXISTS`.
 *
 * The key space is split in two partial index families (generic keys and name words) so that an
 * ordinary prefix lookup never walks the name-word rows. The planner uses a partial index only
 * when the query repeats its predicate, so the predicates are public constants: the query
 * branches build their WHERE clause from the very same text. Queries must match an index by its
 * predicate, never by its name: the name carries a hash of the definition, so a changed definition
 * is built beside the old index and the old one is removed only by `model-search:sync --prune`.
 *
 * The name-word stage-zero index is planned only when some definition has both names and stages
 * (decision R11): a definition without names never queries name words, so the index would be dead
 * weight on a table that is already the largest one.
 */
final class IndexPlan
{
    /** Predicate of the generic key indexes; every key branch repeats it verbatim. */
    public const string GENERIC_KEYS = "kind <> 'name_word'";

    /** Predicate of the name-word index; every name-word branch repeats it verbatim. */
    public const string NAME_WORDS = "kind = 'name_word'";

    /** Extra predicate of the stage-zero indexes: rows of a constrained stage only. */
    public const string STAGE_ZERO = 'stage < '.CompiledDefinition::ALL_STAGE;

    /**
     * The predicate of a stage-zero branch: the base predicate and the stage predicate.
     */
    public static function withStageZero(string $predicate): string
    {
        return $predicate.' AND '.self::STAGE_ZERO;
    }

    /**
     * @param  iterable<CompiledDefinition>  $definitions
     * @return list<IndexSpec>
     */
    public static function for(iterable $definitions, TableNames $names): array
    {
        $hasNames = false;
        $hasStages = false;
        $namedStaged = false;
        $partitioned = false;
        $partitionConfig = config('model-search.partition_column');

        foreach ($definitions as $definition) {
            $hasNames = $hasNames || $definition->hasNames();
            $hasStages = $hasStages || $definition->hasStages();
            $namedStaged = $namedStaged || ($definition->hasNames() && $definition->hasStages());
            $partitioned = $partitioned || ($definition->partition ?? $partitionConfig) !== null;
        }

        $keys = $names->keys();
        $lead = $partitioned ? '"partition", ' : '';
        $prefix = $names->prefix();

        $indexes = [
            self::keyIndex($prefix, 'keys_key', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::GENERIC_KEYS),
            self::keyIndex($prefix, 'keys_reverse', $keys, $lead.'reverse("key") text_pattern_ops, "subject_type"', self::GENERIC_KEYS),
            self::index($prefix, 'entries_names', $names->entries(), 'USING gin ("names" gin_trgm_ops) WITH (fastupdate = off)'),
        ];

        if ($hasNames) {
            $indexes[] = self::keyIndex($prefix, 'keys_name_word', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::NAME_WORDS);
        }

        if ($hasStages) {
            $indexes[] = self::keyIndex($prefix, 'keys_key_s0', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::withStageZero(self::GENERIC_KEYS));
            $indexes[] = self::keyIndex($prefix, 'keys_reverse_s0', $keys, $lead.'reverse("key") text_pattern_ops, "subject_type"', self::withStageZero(self::GENERIC_KEYS));

            if ($namedStaged) {
                $indexes[] = self::keyIndex($prefix, 'keys_name_word_s0', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::withStageZero(self::NAME_WORDS));
            }
        }

        return $indexes;
    }

    private static function keyIndex(string $prefix, string $family, string $table, string $columns, string $predicate): IndexSpec
    {
        return self::index($prefix, $family, $table, sprintf('(%s) WHERE %s', $columns, $predicate));
    }

    /**
     * @param  string  $definition  Everything after `ON <table>`; the name carries its hash.
     */
    private static function index(string $prefix, string $family, string $table, string $definition): IndexSpec
    {
        $name = $prefix.$family.'_'.substr(hash('sha256', $table.'|'.$definition), 0, 8).'_idx';

        return new IndexSpec(
            $name,
            $family,
            $table,
            sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s', Sql::ident($name), Sql::ident($table), $definition),
        );
    }
}
