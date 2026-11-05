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
use Happenv\ModelSearch\Drivers\Hit;
use Happenv\ModelSearch\Query\RevealPolicy;
use Happenv\ModelSearch\Query\SearchMatch;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Query\SearchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns the ranked rows of a round into results: the models are loaded by the host query, by key
 * only (no phrase, nothing but ids, on the host's own connection, so eager loads and after-query
 * callbacks of the host run), in the order of the round, and every match passes the reveal rules.
 *
 * A record that left the host's scope between the round and the load is dropped.
 *
 * @internal
 */
final class Hydrator
{
    /**
     * @return list<SearchResult>
     */
    public static function results(SearchQuery $query, CompiledDefinition $definition, TypeRound $round): array
    {
        if ($round->rows === []) {
            return [];
        }

        $models = $query->scope()->hydrate(array_map(static fn (CandidateRow $row): string => $row->subjectId, $round->rows))
            ->keyBy(static fn (Model $model): string => (string) $model->getKey());
        $stage = $query->stagesEnabled() ? $definition->constrainedStage() : null;
        $reveal = new RevealPolicy($definition);
        $viewer = $query->viewer();
        $results = [];

        foreach ($round->rows as $row) {
            $model = $models->get($row->subjectId);

            if (! $model instanceof Model) {
                continue;
            }

            $match = new SearchMatch(
                kind: $row->kind,
                label: self::label($definition, $row->kind),
                value: $row->value,
                mode: $row->mode(),
                stage: self::stage($stage, $row->inStage),
                via: $row->via,
                alternatives: array_map(
                    static fn (array $route): SearchMatch => new SearchMatch(
                        kind: $route['kind'],
                        label: self::label($definition, $route['kind']),
                        value: $route['value'],
                        mode: Mode::fromRank($route['level'] % Hit::VIA_OFFSET),
                        stage: self::stage($stage, $row->inStage),
                        via: $route['via'],
                    ),
                    $row->alternatives,
                ),
            );

            $results[] = new SearchResult($model, self::named($reveal->apply($match, $model, $viewer), $definition));
        }

        return $results;
    }

    /**
     * The name words belong to the name fields that match by prefix: reported under the first of
     * them (the reveal rules have already been applied to the word). `name_word` stays inside.
     */
    private static function named(SearchMatch $match, CompiledDefinition $definition): SearchMatch
    {
        $first = array_find($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true));
        $rename = static fn (SearchMatch $item): SearchMatch => $item->kind === SearchField::RESERVED_KIND && $first instanceof CompiledField
            ? new SearchMatch($first->kind, $item->label, $item->value, $item->mode, $item->stage, $item->via, $item->alternatives)
            : $item;

        return $rename($match)->withAlternatives(array_map($rename, $match->alternatives));
    }

    /**
     * The stage a type's results come from: the first stage when it answered with something besides
     * exact hits, otherwise all stages; null when stages do not apply.
     */
    public static function settledStage(SearchQuery $query, CompiledDefinition $definition, TypeRound $round): ?string
    {
        $stage = $query->stagesEnabled() ? $definition->constrainedStage() : null;

        if (! $stage instanceof SearchStage) {
            return null;
        }

        return $round->answeredByStageZero() ? $stage->key : SearchStage::ALL;
    }

    private static function stage(?SearchStage $stage, bool $inStage): ?string
    {
        if (! $stage instanceof SearchStage) {
            return null;
        }

        return $inStage ? $stage->key : SearchStage::ALL;
    }

    /**
     * The label of the field a kind belongs to. The name words belong to the name fields that match
     * by prefix; the first of them names the match.
     */
    private static function label(CompiledDefinition $definition, string $kind): string
    {
        $field = $kind === SearchField::RESERVED_KIND
            ? array_find($definition->fields, static fn (CompiledField $field): bool => $field->type === FieldType::Name && in_array(Mode::Prefix, $field->modes, true))
            : array_find($definition->fields, static fn (CompiledField $field): bool => $field->kind === $kind);

        return $field?->label instanceof Closure ? ($field->label)() : Str::headline($kind);
    }
}
