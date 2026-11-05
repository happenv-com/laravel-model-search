<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
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
        static fn (IndexSpec $spec): string => $spec->family,
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
    $always = ['keys_key', 'keys_reverse', 'entries_names'];

    expect(plannedIndexNames([Invoice::class]))->toBe($always)
        ->and(plannedIndexNames([Product::class]))->toBe([...$always, 'keys_name_word'])
        ->and(plannedIndexNames([Order::class]))->toBe([
            ...$always,
            'keys_name_word',
            'keys_key_s0',
            'keys_reverse_s0',
            'keys_name_word_s0',
        ]);
});

it('keeps definitions apart: stage-zero name words need one definition with names and stages', function (): void {
    // Invoice: no names, no stages. Order: both. Product: names only.
    expect(plannedIndexNames([Invoice::class, Product::class]))->not->toContain('keys_key_s0')
        ->and(plannedIndexNames([Invoice::class, Order::class]))->toContain('keys_name_word_s0');
});

it('leads the key indexes with the partition column when a definition is partitioned', function (): void {
    config(['model-search.partition_column' => 'tenant_id']);

    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);
    $specs = IndexPlan::for($registry->all(), TableNames::forConnection());

    foreach ($specs as $spec) {
        if (str_starts_with($spec->family, 'keys_')) {
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

    $byName = array_column(array_map(static fn (IndexSpec $s): array => [$s->family, $s->createSql], $specs), 1, 0);

    expect($byName['keys_key'])->toEndWith('WHERE '.IndexPlan::GENERIC_KEYS)
        ->and($byName['keys_name_word'])->toEndWith('WHERE '.IndexPlan::NAME_WORDS)
        ->and($byName['keys_key_s0'])->toEndWith('WHERE '.IndexPlan::GENERIC_KEYS.' AND '.IndexPlan::STAGE_ZERO)
        ->and($byName['keys_name_word_s0'])->toEndWith('WHERE '.IndexPlan::NAME_WORDS.' AND '.IndexPlan::STAGE_ZERO);
});

it('index predicates are literal and match the query predicates', function (): void {
    // The query branches build their predicate from these constants (IndexPlan::withStageZero for
    // the stage-zero ones); the indexes the sync created must carry the same predicate.
    $expected = [
        'keys_key' => IndexPlan::GENERIC_KEYS,
        'keys_reverse' => IndexPlan::GENERIC_KEYS,
        'keys_name_word' => IndexPlan::NAME_WORDS,
        'keys_key_s0' => IndexPlan::withStageZero(IndexPlan::GENERIC_KEYS),
        'keys_reverse_s0' => IndexPlan::withStageZero(IndexPlan::GENERIC_KEYS),
        'keys_name_word_s0' => IndexPlan::withStageZero(IndexPlan::NAME_WORDS),
    ];

    $names = [];

    foreach (IndexPlan::for(app(ModelSearchRegistry::class)->all(), TableNames::forConnection()) as $spec) {
        $names[$spec->family] = $spec->name;
    }

    foreach ($expected as $family => $predicate) {
        expect(liveIndexPredicate($names[$family]))->toBe(normalisedPredicate($predicate), $family);
    }
});

it('puts a short hash of the definition in every index name, so a changed definition is a new name', function (): void {
    $registry = app(ModelSearchRegistry::class);
    $plain = IndexPlan::for($registry->all(), TableNames::forConnection());
    config(['model-search.partition_column' => 'tenant_id']);
    $partitioned = IndexPlan::for($registry->all(), TableNames::forConnection());

    expect($plain[0]->name)->toMatch('/^model_search_keys_key_[0-9a-f]{8}_idx$/')
        ->and($partitioned[0]->name)->not->toBe($plain[0]->name)
        // the trigram index does not depend on the partition: same definition, same name
        ->and($partitioned[2]->name)->toBe($plain[2]->name);
});

it('builds STAGE_ZERO from the all-stage constant of the compiled definition', function (): void {
    expect(IndexPlan::STAGE_ZERO)->toBe('stage < '.CompiledDefinition::ALL_STAGE);
});

it('creates the names index as a gin trigram index without a pending list', function (): void {
    $name = array_values(array_filter(IndexPlan::for(app(ModelSearchRegistry::class)->all(), TableNames::forConnection()), static fn (IndexSpec $spec): bool => $spec->family === 'entries_names'))[0]->name;
    $definition = (string) DB::selectOne('SELECT pg_get_indexdef(to_regclass(?)) AS definition', [$name])->definition;

    expect($definition)->toContain('USING gin (names gin_trgm_ops)')->toContain('fastupdate=off');
});
