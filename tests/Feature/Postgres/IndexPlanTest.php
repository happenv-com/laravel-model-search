<?php

declare(strict_types=1);

use Happenv\ModelSearch\Postgres\Schema\IndexPlan;
use Happenv\ModelSearch\Postgres\Schema\IndexSpec;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\DB;

covers(IndexPlan::class);

/**
 * @param  list<class-string>  $models
 * @return list<string>
 */
function plannedIndexNames(array $models): array
{
    $registry = app(ModelSearchRegistry::class);

    return array_map(
        static fn (IndexSpec $spec): string => $spec->name,
        IndexPlan::for(array_map(static fn (string $model) => $registry->for($model), $models), TableNames::forConnection()),
    );
}

/**
 * The partial-index predicate as PostgreSQL stores it, reduced to the form the constants are
 * written in: PostgreSQL re-renders `kind <> 'name_word'` as `(kind <> 'name_word'::text)` and a
 * conjunction as `((a) AND (b))`, so casts, parentheses and spacing are dropped on both sides.
 */
function normalisedPredicate(string $text): string
{
    $text = str_replace('::text', '', $text);
    $text = str_replace(['(', ')'], '', $text);

    return strtolower((string) preg_replace('/\s+/', ' ', trim($text)));
}

function liveIndexPredicate(string $index): string
{
    $definition = (string) DB::selectOne('SELECT pg_get_indexdef(to_regclass(?)) AS definition', [$index])?->definition;

    return normalisedPredicate(substr($definition, strpos($definition, ' WHERE ') + strlen(' WHERE ')));
}

it('plans name-word and stage-zero indexes only when a definition needs them', function (): void {
    $always = ['model_search_keys_key_idx', 'model_search_keys_reverse_idx', 'model_search_entries_names_gin'];

    expect(plannedIndexNames([Invoice::class]))->toBe($always)
        ->and(plannedIndexNames([Product::class]))->toBe([...$always, 'model_search_keys_name_word_idx'])
        ->and(plannedIndexNames([Order::class]))->toBe([
            ...$always,
            'model_search_keys_name_word_idx',
            'model_search_keys_key_s0_idx',
            'model_search_keys_reverse_s0_idx',
            'model_search_keys_name_word_s0_idx',
        ]);
});

it('keeps definitions apart: stage-zero name words need one definition with names and stages', function (): void {
    // Invoice: no names, no stages. Order: both. Product: names only.
    expect(plannedIndexNames([Invoice::class, Product::class]))->not->toContain('model_search_keys_key_s0_idx')
        ->and(plannedIndexNames([Invoice::class, Order::class]))->toContain('model_search_keys_name_word_s0_idx');
});

it('leads the key indexes with the partition column when a definition is partitioned', function (): void {
    config(['model-search.partition_column' => 'tenant_id']);

    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);
    $specs = IndexPlan::for($registry->all(), TableNames::forConnection());

    foreach ($specs as $spec) {
        if (str_starts_with($spec->name, 'model_search_keys')) {
            expect($spec->createSql)->toContain('("partition", ');
        }
    }

    expect($specs[2]->createSql)->toContain('gin ("names" gin_trgm_ops) WITH (fastupdate = off)')
        ->and($specs[2]->createSql)->not->toContain('"partition"');
});

it('writes every key index as a raw concurrent create with the literal predicate', function (): void {
    $specs = IndexPlan::for(app(ModelSearchRegistry::class)->all(), TableNames::forConnection());

    foreach ($specs as $spec) {
        expect($spec->createSql)->toStartWith('CREATE INDEX CONCURRENTLY IF NOT EXISTS ');
    }

    $byName = array_column(array_map(static fn (IndexSpec $s): array => [$s->name, $s->createSql], $specs), 1, 0);

    expect($byName['model_search_keys_key_idx'])->toEndWith('WHERE '.IndexPlan::GENERIC_KEYS)
        ->and($byName['model_search_keys_name_word_idx'])->toEndWith('WHERE '.IndexPlan::NAME_WORDS)
        ->and($byName['model_search_keys_key_s0_idx'])->toEndWith('WHERE '.IndexPlan::GENERIC_KEYS.' AND '.IndexPlan::STAGE_ZERO)
        ->and($byName['model_search_keys_name_word_s0_idx'])->toEndWith('WHERE '.IndexPlan::NAME_WORDS.' AND '.IndexPlan::STAGE_ZERO);
});

it('index predicates are literal and match the query predicates', function (): void {
    // The query branches build their predicate from these constants (IndexPlan::withStageZero for
    // the stage-zero ones); the indexes the sync created must carry the same predicate.
    $expected = [
        'model_search_keys_key_idx' => IndexPlan::GENERIC_KEYS,
        'model_search_keys_reverse_idx' => IndexPlan::GENERIC_KEYS,
        'model_search_keys_name_word_idx' => IndexPlan::NAME_WORDS,
        'model_search_keys_key_s0_idx' => IndexPlan::withStageZero(IndexPlan::GENERIC_KEYS),
        'model_search_keys_reverse_s0_idx' => IndexPlan::withStageZero(IndexPlan::GENERIC_KEYS),
        'model_search_keys_name_word_s0_idx' => IndexPlan::withStageZero(IndexPlan::NAME_WORDS),
    ];

    foreach ($expected as $index => $predicate) {
        expect(liveIndexPredicate($index))->toBe(normalisedPredicate($predicate), $index);
    }
});

it('creates the names index as a gin trigram index without a pending list', function (): void {
    $definition = (string) DB::selectOne('SELECT pg_get_indexdef(to_regclass(?)) AS definition', ['model_search_entries_names_gin'])->definition;

    expect($definition)->toContain('USING gin (names gin_trgm_ops)')->toContain('fastupdate=off');
});
