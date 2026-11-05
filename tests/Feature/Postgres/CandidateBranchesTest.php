<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Drivers\Fragment;
use Happenv\ModelSearch\Postgres\Query\CandidateBranches;
use Happenv\ModelSearch\Postgres\Schema\IndexPlan;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;

covers(CandidateBranches::class);

/**
 * @return list<Fragment>
 */
function cbBranches(string $model, SearchQuery $query, int $pool = 200, int $type = 0): array
{
    return CandidateBranches::for(app(ModelSearchRegistry::class)->for($model), $query, $pool, $type);
}

function cbText(array $branches): string
{
    return implode("\n", array_map(static fn (Fragment $branch): string => $branch->sql, $branches));
}

it('writes the predicates of the partial indexes into the statement, verbatim', function (): void {
    $text = cbText(cbBranches(Order::class, OrderSearch::query('abcd efgh')->within(Order::query())));

    expect($text)->toContain(IndexPlan::GENERIC_KEYS)
        ->and($text)->toContain(IndexPlan::NAME_WORDS)
        ->and($text)->toContain(IndexPlan::STAGE_ZERO);
});

it('builds its predicates from the constants of the plan that creates the indexes', function (): void {
    $created = implode("\n", array_map(
        static fn ($spec): string => $spec->createSql,
        IndexPlan::for([app(ModelSearchRegistry::class)->for(Order::class)], TableNames::forConnection()),
    ));

    foreach ([IndexPlan::GENERIC_KEYS, IndexPlan::NAME_WORDS, IndexPlan::STAGE_ZERO] as $predicate) {
        expect($created)->toContain($predicate);
    }
});

it('never binds a predicate: the type, the pattern and the kinds are the only parameters', function (): void {
    foreach (cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query())) as $branch) {
        expect($branch->bindings)->not->toContain(IndexPlan::GENERIC_KEYS)
            ->and($branch->bindings)->not->toContain(IndexPlan::NAME_WORDS)
            ->and($branch->bindings)->not->toContain(IndexPlan::STAGE_ZERO);
    }
});

it('reads a type with stages twice for prefix and suffix, and once for exact', function (): void {
    $branches = cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query())->withoutStages());
    $staged = cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query()));

    // Without stages: exact, prefix and suffix of the key variant, one word branch.
    expect(substr_count(cbText($branches), ' AND k.'.IndexPlan::STAGE_ZERO))->toBe(0)
        ->and(substr_count(cbText($staged), ' AND k.'.IndexPlan::STAGE_ZERO))->toBe(3)
        ->and(count($staged))->toBe(count($branches) + 3);
});

it('reads a type without stages once per mode', function (): void {
    $branches = cbBranches(Product::class, ProductSearch::query('abcdef')->within(Product::query()));

    expect(cbText($branches))->not->toContain(' AND k.'.IndexPlan::STAGE_ZERO)
        ->and(count($branches))->toBe(4);
});

it('limits every branch to the pool plus one, in the order of the index', function (): void {
    $branches = cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query()), 7);

    foreach ($branches as $branch) {
        expect($branch->sql)->toContain(' LIMIT 8)');
    }

    $ordered = array_filter($branches, static fn (Fragment $branch): bool => str_contains($branch->sql, 'ORDER BY'));

    expect($ordered)->not->toBe([]);

    foreach ($ordered as $branch) {
        expect($branch->sql)->toMatch('/ORDER BY (reverse\(k\.key\)|k\.key) USING ~<~ LIMIT/');
    }
});

it('numbers the branches of a type from its own range', function (): void {
    $first = cbText(cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query())));
    $third = cbText(cbBranches(Order::class, OrderSearch::query('abcdef')->within(Order::query()), 200, 3));

    preg_match_all('/(\d+) AS br/', $first, $firsts);
    preg_match_all('/(\d+) AS br/', $third, $thirds);

    expect(array_unique($firsts[1]))->toBe($firsts[1])
        ->and(min($firsts[1]))->toBe('0')
        ->and(min($thirds[1]))->toBe((string) (3 * CandidateBranches::TYPE_STRIDE));
});

it('skips the name words for a phrase shaped like an identifier and for a modes list without prefix', function (): void {
    $identifier = cbText(cbBranches(Order::class, OrderSearch::query('123456')->within(Order::query())));
    $email = cbText(cbBranches(Order::class, OrderSearch::query('a@example.com')->within(Order::query())));
    $exact = cbText(cbBranches(Order::class, OrderSearch::query('kowalski')->within(Order::query())->using(Mode::Exact)));
    $names = cbText(cbBranches(Order::class, OrderSearch::query('kowalski')->within(Order::query())));

    expect($identifier)->not->toContain(IndexPlan::NAME_WORDS)
        ->and($email)->not->toContain(IndexPlan::NAME_WORDS)
        ->and($exact)->not->toContain(IndexPlan::NAME_WORDS)
        ->and($names)->toContain(IndexPlan::NAME_WORDS);
});

it('reads the longest word and asks for the others in the same record', function (): void {
    $branches = cbBranches(Product::class, ProductSearch::query('jan kowalski')->within(Product::query()));
    $words = array_values(array_filter($branches, static fn (Fragment $branch): bool => str_contains($branch->sql, IndexPlan::NAME_WORDS)));

    expect($words)->toHaveCount(1)
        ->and($words[0]->sql)->toContain('EXISTS (SELECT 1')
        ->and($words[0]->bindings)->toContain('kowalski%')
        ->and($words[0]->bindings)->toContain('jan%');
});

it('escapes like metacharacters and uses the explicit escape character', function (): void {
    $branches = cbBranches(Product::class, ProductSearch::query('ab%cdef')->within(Product::query()));
    $bindings = array_merge(...array_map(static fn (Fragment $branch): array => $branch->bindings, $branches));

    expect($bindings)->toContain('ab\\%cdef%')
        ->and(cbText($branches))->toContain("LIKE ? ESCAPE '\\'");
});

it('keeps the via branch closed without a scope for the parent, and opens it with one', function (): void {
    $closed = cbText(cbBranches(Invoice::class, InvoiceSearch::query('ORD-1')->within(Invoice::query())));
    $open = cbBranches(Invoice::class, InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()->where('state', 'unshipped')]));
    $text = cbText($open);

    expect($closed)->not->toContain('JOIN LATERAL')
        ->and($text)->toContain('JOIN LATERAL')
        ->and($text)->toContain(' LIMIT '.DatabaseDriver::VIA_PARENT_LIMIT.')')
        ->and($text)->toContain(IndexPlan::GENERIC_KEYS);
});

it('filters the parents by the scope of the parent before the limit of parents', function (): void {
    $open = cbBranches(Invoice::class, InvoiceSearch::query('ORD-1')->within(Invoice::query(), [Order::class => Order::query()]));
    $via = array_values(array_filter($open, static fn (Fragment $branch): bool => str_contains($branch->sql, 'JOIN LATERAL')))[0]->sql;

    expect(strpos($via, 'JOIN LATERAL'))->toBeLessThan(strpos($via, ' LIMIT '.DatabaseDriver::VIA_PARENT_LIMIT.')'));
});

it('joins the source column of a field that reads it', function (): void {
    $branches = cbBranches(Order::class, OrderSearch::query('FRESH-1')->within(Order::query()));
    $source = array_values(array_filter($branches, static fn (Fragment $branch): bool => str_contains($branch->sql, 'source_variants')));

    expect($source)->toHaveCount(1)
        ->and($source[0]->sql)->toContain('true AS src');
});

it('builds nothing for a phrase below every minimum', function (): void {
    expect(cbBranches(Product::class, ProductSearch::query('')->within(Product::query())))->toBe([]);
});
