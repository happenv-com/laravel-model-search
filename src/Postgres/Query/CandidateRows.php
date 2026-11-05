<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Query;

use Closure;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\CompiledField;
use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Drivers\Fragment;
use Happenv\ModelSearch\Drivers\Hit;
use Happenv\ModelSearch\Query\SearchQuery;
use Illuminate\Support\Collection;

/**
 * `candidates()` on the postgres driver: the raw rows of round one, read from the index.
 *
 * The branches of {@see CandidateBranches} with no host join, no scope, no second round and no
 * hydration: one row per record and matching field (the best mode of it), at most `pool` of them, the
 * best mode first. Nothing here is authorized, so a value is returned only for a field that has no
 * `revealUsing()` rule (there is no model to hand to the rule); the name words answer for all the
 * name fields that match by prefix.
 *
 * @internal
 */
final class CandidateRows
{
    /**
     * @return array{sql: string, bindings: list<mixed>}|null Null when no branch survives the phrase.
     */
    public static function sql(CompiledDefinition $definition, SearchQuery $query): ?array
    {
        $pool = RoundPlan::first()->pool;
        $branches = CandidateBranches::for($definition, $query, $pool);

        if ($branches === []) {
            return null;
        }

        $union = Fragment::join($branches, ' UNION ALL ');

        return [
            'sql' => sprintf(
                'SELECT d.subject_id, d.level, d.kind, d.value, d.via, d.s0 FROM (SELECT DISTINCT ON (u.subject_id, u.kind) u.subject_id, u.level, u.kind, u.value, u.via, u.s0 FROM (%1$s) AS u ORDER BY u.subject_id, u.kind, u.level, u.s0 DESC) AS d ORDER BY d.level, d.subject_id, d.kind LIMIT %2$d',
                $union->sql,
                $pool,
            ),
            'bindings' => $union->bindings,
        ];
    }

    /**
     * @param  list<object>  $rows
     * @return Collection<int, array{type: string, id: string, mode: Mode, kind: string, value: string|null, stage: string|null}>
     */
    public static function read(CompiledDefinition $definition, SearchQuery $query, array $rows): Collection
    {
        $stage = $definition->constrainedStage();
        $stage = $query->stagesEnabled() && $stage instanceof SearchStage ? $stage : null;
        $named = array_find($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true));
        $candidates = new Collection;

        foreach ($rows as $row) {
            $columns = (array) $row;
            $kind = is_scalar($columns['kind'] ?? null) ? (string) $columns['kind'] : '';
            $value = is_string($columns['value'] ?? null) ? $columns['value'] : null;
            $level = is_numeric($columns['level'] ?? null) ? (int) $columns['level'] : 0;
            $subject = is_scalar($columns['subject_id'] ?? null) ? (string) $columns['subject_id'] : '';
            $inStage = in_array($columns['s0'] ?? null, [true, 't', 1, '1'], true);

            if ($kind === SearchField::RESERVED_KIND) {
                $hidden = array_filter($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true) && $field->reveal instanceof Closure) !== [];
                $kind = $named instanceof CompiledField ? $named->kind : $kind;
            } else {
                $field = array_find($definition->fields, static fn (CompiledField $field): bool => $field->kind === $kind);
                $hidden = $field instanceof CompiledField && $field->reveal instanceof Closure;
            }

            $candidates->push([
                'type' => $definition->morphAlias,
                'id' => $subject,
                'mode' => Mode::fromRank($level % Hit::VIA_OFFSET),
                'kind' => $kind,
                'value' => $hidden ? null : $value,
                'stage' => $stage instanceof SearchStage ? ($inStage ? $stage->key : SearchStage::ALL) : null,
            ]);
        }

        return $candidates;
    }
}
