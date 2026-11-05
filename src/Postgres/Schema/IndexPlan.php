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
 * branches build their WHERE clause from the very same text.
 */
final class IndexPlan
{
    /** Predicate of the generic key indexes; every key branch repeats it verbatim. */
    public const string GENERIC_KEYS = "kind <> 'name_word'";

    /** Predicate of the name-word index; every name-word branch repeats it verbatim. */
    public const string NAME_WORDS = "kind = 'name_word'";

    /** Extra predicate of the stage-zero indexes: rows of a constrained stage only. */
    public const string STAGE_ZERO = 'stage < 32767';

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
            self::keyIndex($prefix.'keys_key_idx', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::GENERIC_KEYS),
            self::keyIndex($prefix.'keys_reverse_idx', $keys, $lead.'reverse("key") text_pattern_ops, "subject_type"', self::GENERIC_KEYS),
            new IndexSpec(
                $prefix.'entries_names_gin',
                $names->entries(),
                sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin ("names" gin_trgm_ops) WITH (fastupdate = off)', Sql::ident($prefix.'entries_names_gin'), Sql::ident($names->entries())),
            ),
        ];

        if ($hasNames) {
            $indexes[] = self::keyIndex($prefix.'keys_name_word_idx', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::NAME_WORDS);
        }

        if ($hasStages) {
            $indexes[] = self::keyIndex($prefix.'keys_key_s0_idx', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::withStageZero(self::GENERIC_KEYS));
            $indexes[] = self::keyIndex($prefix.'keys_reverse_s0_idx', $keys, $lead.'reverse("key") text_pattern_ops, "subject_type"', self::withStageZero(self::GENERIC_KEYS));

            if ($namedStaged) {
                $indexes[] = self::keyIndex($prefix.'keys_name_word_s0_idx', $keys, $lead.'"key" text_pattern_ops, "subject_type"', self::withStageZero(self::NAME_WORDS));
            }
        }

        return $indexes;
    }

    private static function keyIndex(string $name, string $table, string $columns, string $predicate): IndexSpec
    {
        return new IndexSpec(
            $name,
            $table,
            sprintf('CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s) WHERE %s', Sql::ident($name), Sql::ident($table), $columns, $predicate),
        );
    }
}
