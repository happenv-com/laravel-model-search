<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\Sql;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Postgres\Query\RoundOne;
use Happenv\ModelSearch\Postgres\Query\RoundTwo;
use Happenv\ModelSearch\Postgres\Query\RoundTwoPhase;
use Happenv\ModelSearch\Postgres\Query\SimilarQuery;
use Happenv\ModelSearch\Postgres\Schema\IndexPlan;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Session\SearchSession;
use Happenv\ModelSearch\Query\HostQuery;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

covers(RoundOne::class);

/*
 * The shape of the plans is the contract of the query: a planner that quietly turns a branch into a
 * sequential scan, a hash join over the host or a parallel gather does not fail any other test, it only
 * makes the search slow on a large table. The assertions are strict on purpose: when the planner will
 * not satisfy one, that is a finding to report, not a reason to loosen the test.
 */

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres']);
});

/**
 * About 20 000 orders with their keys, name words and entries, inserted by SQL; 2.5% are in the first stage.
 */
function psSeed(): void
{
    DB::statement("insert into orders (id, signature, external_number, customer_name, state, created_at, updated_at) select gen_random_uuid(), 'ABC-' || g, 'N-' || g, 'Jan Kowalski' || g, case when g % 40 = 0 then 'unshipped' else 'shipped' end, now(), now() from generate_series(1, 20000) g");
    DB::statement("insert into model_search_entries (subject_type, subject_id, stage, names, sort_at, indexed_at) select 'order', id::text, case when state = 'unshipped' then 0 else 32767 end, lower(customer_name), purchased_at, now() from orders");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'signature', lower(replace(signature, '-', '')), signature, case when state = 'unshipped' then 0 else 32767 end from orders");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'external_number', lower(replace(external_number, '-', '')), external_number, case when state = 'unshipped' then 0 else 32767 end from orders");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'name_word', 'jan', customer_name, case when state = 'unshipped' then 0 else 32767 end from orders");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'name_word', lower(substr(customer_name, 5)), customer_name, case when state = 'unshipped' then 0 else 32767 end from orders");
    // Orders whose number starts and ends alike, so that the prefix and the suffix of one phrase both match thousands of keys, each with an invoice.
    DB::statement("insert into orders (id, signature, state, created_at, updated_at) select gen_random_uuid(), 'ZZZZ-' || g || '-ZZZZ', case when g % 40 = 0 then 'unshipped' else 'shipped' end, now(), now() from generate_series(1, 4000) g");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'order', id::text, 'signature', lower(replace(signature, '-', '')), signature, 32767 from orders where signature like 'ZZZZ-%'");
    DB::statement("insert into invoices (id, number, order_id, created_at, updated_at) select gen_random_uuid(), 'FV-' || row_number() over (), id, now(), now() from orders where signature like 'ZZZZ-%'");
    DB::statement("insert into model_search_entries (subject_type, subject_id, stage, names, indexed_at) select 'invoice', id::text, 32767, '', now() from invoices");
    DB::statement("insert into model_search_keys (subject_type, subject_id, kind, key, value, stage) select 'invoice', id::text, 'number', lower(replace(number, '-', '')), number, 32767 from invoices");
    // Names that few records share: the trigram index is worth using only for a selective phrase.
    DB::statement("update model_search_entries set names = 'zz' || substr(md5(subject_id), 1, 12) where subject_type = 'order'");
    DB::statement("update model_search_entries set names = 'jan kowalski' where ctid in (select ctid from model_search_entries where subject_type = 'order' limit 15)");
    DB::statement('analyze orders');
    DB::statement('analyze invoices');
    DB::statement('analyze model_search_keys');
    DB::statement('analyze model_search_entries');
}

/**
 * Runs the assertions of one plan and names the plan in the message of the first one that fails.
 *
 * @param  Closure(): void  $assertions
 */
function psCheck(string $label, Closure $assertions): void
{
    try {
        $assertions();
    } catch (ExpectationFailedException $failure) {
        throw new ExpectationFailedException('['.$label.'] '.$failure->getMessage(), $failure->getComparisonFailure(), $failure);
    }
}

/**
 * The two viewers of the palette: one who sees everything and one with a scope.
 *
 * @return array<string, Closure(): Builder<Order>>
 */
function psViewers(): array
{
    return [
        'full' => static fn () => Order::query(),
        'scoped' => static fn () => Order::query()->where('state', 'shipped'),
    ];
}

/**
 * @return array{order: array{definition: CompiledDefinition, query: SearchQuery, host: HostQuery}}
 */
function psTargets(SearchQuery $query): array
{
    return ['order' => ['definition' => app(ModelSearchRegistry::class)->for(Order::class), 'query' => $query, 'host' => $query->scope()]];
}

/**
 * The plan of a statement as the search session would run it: the same settings, a real parameter list.
 *
 * @param  array{sql: string, bindings: list<mixed>}  $statement
 * @return array<string, mixed>
 */
function psPlan(array $statement): array
{
    $rows = resolve(SearchSession::class)->run(Sql::connectionName(new Order), 5000, static fn (Connection $db): array => $db->select('EXPLAIN (FORMAT JSON) '.$statement['sql'], $statement['bindings']));
    $json = (array) $rows[0];
    $decoded = json_decode((string) current($json), true);

    return $decoded[0]['Plan'];
}

/**
 * Every node of a plan with its ancestors, outermost first.
 *
 * @param  array<string, mixed>  $plan
 * @param  list<array<string, mixed>>  $ancestors
 * @return list<array{node: array<string, mixed>, ancestors: list<array<string, mixed>>}>
 */
function psWalk(array $plan, array $ancestors = []): array
{
    $nodes = [['node' => $plan, 'ancestors' => $ancestors]];

    foreach ($plan['Plans'] ?? [] as $child) {
        array_push($nodes, ...psWalk($child, [...$ancestors, $plan]));
    }

    return $nodes;
}

/**
 * @param  array<string, mixed>  $node
 */
function psOn(array $node, string $table): bool
{
    return ($node['Relation Name'] ?? null) === $table;
}

/**
 * Asserts the shape every statement of the palette must keep.
 *
 * @param  array<string, mixed>  $plan
 * @return array{keyScans: int, stageZeroScans: int, hostScans: int, primaryScans: int}
 */
function psAssertShape(array $plan, bool $invoices = false): array
{
    $names = TableNames::forConnection();
    $nodes = psWalk($plan);
    $keyScans = 0;
    $stageZeroScans = 0;
    $hostScans = 0;
    $primaryScans = 0;
    // The other words of a name phrase are looked up by the primary key of the keys, one row per candidate.
    $families = ['keys_key', 'keys_reverse', 'keys_name_word', 'keys_pkey'];

    foreach ($nodes as ['node' => $node, 'ancestors' => $ancestors]) {
        $type = (string) $node['Node Type'];

        expect($type)->not->toStartWith('Gather');

        if (psOn($node, $names->keys()) || psOn($node, $names->entries())) {
            expect($type)->not->toBe('Seq Scan');
        }

        if (psOn($node, $names->keys())) {
            $keyScans++;
            $index = (string) ($node['Index Name'] ?? '');

            expect($type)->toBeIn(['Index Scan', 'Index Only Scan'])
                ->and(array_filter($families, static fn (string $family): bool => str_contains($index, $family)))->not->toBeEmpty();

            // Up through nested loops only: a sort, hash or aggregate between the scan and its limit ends the early stop.
            $chain = array_reverse($ancestors);
            $limited = false;

            foreach ($chain as $ancestor) {
                if ($ancestor['Node Type'] === 'Limit') {
                    $limited = true;
                    break;
                }

                if ($ancestor['Node Type'] !== 'Nested Loop') {
                    break;
                }
            }

            expect($limited)->toBeTrue("the scan of {$index} is not under a Limit through nested loops only");

            if (str_contains($index, '_s0_')) {
                $stageZeroScans++;
                $limit = array_find(array_reverse($ancestors), static fn (array $ancestor): bool => $ancestor['Node Type'] === 'Limit');
                $under = $limit === null ? [] : array_map(static fn (array $entry): array => $entry['node'], psWalk($limit));

                foreach ($under as $below) {
                    expect(psOn($below, $names->entries()))->toBeFalse('a stage-zero branch reads the entries');
                }
            }
        }

        if (psOn($node, 'orders') || ($invoices && psOn($node, 'invoices'))) {
            $hostScans++;
            // A lateral lookup of the host (round two) sits under its own limit, possibly memoized: the join is the first ancestor that is none of those wrappers.
            $joined = $node;
            $joinAncestors = $ancestors;

            while ($joinAncestors !== [] && in_array($joinAncestors[array_key_last($joinAncestors)]['Node Type'], ['Limit', 'Subquery Scan', 'Memoize'], true)) {
                $joined = array_pop($joinAncestors);
            }

            $parent = $joinAncestors === [] ? null : $joinAncestors[array_key_last($joinAncestors)];

            // The join with the host is by the primary key; the fixture's exactOnSource column is read through its own index.
            expect($type)->toBeIn(['Index Scan', 'Index Only Scan'])
                ->and((string) ($node['Index Name'] ?? ''))->toBeIn(['orders_pkey', 'orders_signature_index', 'invoices_pkey', 'invoices_order_id_index'])
                ->and($parent['Node Type'] ?? null)->toBe('Nested Loop')
                ->and($joined['Parent Relationship'] ?? null)->toBeIn(['Inner', 'SubPlan']);

            if (($node['Index Name'] ?? '') === 'orders_pkey') {
                $primaryScans++;
            }
        }
    }

    return ['keyScans' => $keyScans, 'stageZeroScans' => $stageZeroScans, 'hostScans' => $hostScans, 'primaryScans' => $primaryScans];
}

it('keeps the shape of every statement: index scans under limits, nested loops over the host, no sequential scan, no gather', function (): void {
    // The data is seeded ONCE; every plan below is checked against it, for the viewer who sees everything and the one with a scope.
    psSeed();

    foreach (psViewers() as $viewer => $host) {
        foreach (['a prefix of a key' => 'abc12', 'a full number' => 'abc-1234', 'a suffix' => '12345', 'one name word' => 'kowalski12', 'a name prefix' => 'kowal', 'two name words' => 'jan kowal'] as $label => $phrase) {
            psCheck("round one, {$label}, {$viewer}", function () use ($host, $phrase): void {
                $shape = psAssertShape(psPlan(RoundOne::sql(psTargets(OrderSearch::query($phrase)->within($host())))));

                expect($shape['keyScans'])->toBeGreaterThan(0)
                    ->and($shape['primaryScans'])->toBeGreaterThan(0);
            });
        }

        psCheck("round one, stage zero, {$viewer}", function () use ($host): void {
            $shape = psAssertShape(psPlan(RoundOne::sql(psTargets(OrderSearch::query('abc12')->within($host())))));

            expect($shape['stageZeroScans'])->toBeGreaterThan(0);
        });

        psCheck("round one, two name words, early stop, {$viewer}", function () use ($host): void {
            $plan = psPlan(RoundOne::sql(psTargets(OrderSearch::query('jan kowal')->within($host())->withoutStages())));
            $nodes = psWalk($plan);
            $words = array_values(array_filter($nodes, static fn (array $entry): bool => str_contains((string) ($entry['node']['Index Name'] ?? ''), 'keys_name_word')));
            $others = array_values(array_filter($nodes, static fn (array $entry): bool => str_contains((string) ($entry['node']['Index Name'] ?? ''), 'keys_pkey')));
            $joins = array_filter($nodes, static fn (array $entry): bool => str_contains((string) $entry['node']['Node Type'], 'Semi') || ($entry['node']['Join Type'] ?? '') === 'Semi');
            $main = $words[0] ?? null;
            $other = $others[0] ?? null;
            $loops = $other === null ? [] : array_values(array_filter($other['ancestors'], static fn (array $ancestor): bool => $ancestor['Node Type'] === 'Nested Loop'));

            expect($main)->not->toBeNull()
                ->and($other)->not->toBeNull()
                ->and($joins)->toBe([])
                ->and($main['ancestors'][array_key_last($main['ancestors'])]['Node Type'])->toBe('Nested Loop')
                ->and($main['ancestors'][array_key_last($main['ancestors']) - 1]['Node Type'])->toBe('Limit')
                ->and($other['ancestors'][array_key_last($other['ancestors'])]['Node Type'])->toBe('Limit')
                ->and($other['ancestors'][array_key_last($other['ancestors'])]['Plan Rows'])->toBeLessThanOrEqual(1)
                ->and($loops)->not->toBeEmpty()
                ->and((string) $loops[array_key_last($loops)]['Plans'][0]['Index Name'])->toContain('keys_name_word');
            psAssertShape($plan);
        });

        foreach (['stage zero' => RoundTwoPhase::StageZero, 'stage zero then all' => RoundTwoPhase::StageZeroThenAll, 'all stages' => RoundTwoPhase::AllStages, 'a type without stages' => RoundTwoPhase::Unstaged] as $label => $phase) {
            foreach (['abc12', 'jan kowal', '12345'] as $phrase) {
                psCheck("round two, {$label}, '{$phrase}', {$viewer}", function () use ($host, $phase, $phrase): void {
                    $query = OrderSearch::query($phrase)->within($host());
                    $query = $phase === RoundTwoPhase::Unstaged ? $query->withoutStages() : $query;
                    $statement = RoundTwo::sql(psTargets($query), ['order' => $phase]);

                    expect($statement)->not->toBeNull();

                    $shape = psAssertShape(psPlan($statement));

                    expect($shape['keyScans'])->toBeGreaterThan(0)
                        ->and($shape['primaryScans'])->toBeGreaterThan(0);
                });
            }
        }

        // Note: with a SELECTIVE suffix (fewer expected matches than the limit) the planner may choose a bitmap scan with a sort for a parent
        // candidate branch, which is correct there (no early stop to lose). It did not reproduce at 500 000 parents with a selective suffix
        // (reviewer's probe); the phrase below is broad in prefix and in suffix, so the strict assertions are about the case that matters.
        foreach (['round one' => false, 'round two' => true] as $round => $second) {
            psCheck("via, {$round}, {$viewer}", function () use ($host, $second): void {
                $query = InvoiceSearch::query('zzzz')->within(Invoice::query(), [Order::class => $host()]);
                $targets = ['invoice' => ['definition' => app(ModelSearchRegistry::class)->for(Invoice::class), 'query' => $query, 'host' => $query->scope()]];
                $statement = $second ? RoundTwo::sql($targets, ['invoice' => RoundTwoPhase::Unstaged]) : RoundOne::sql($targets);
                $plan = psPlan($statement);
                $nodes = psWalk($plan);
                $parents = array_filter($nodes, static fn (array $entry): bool => psOn($entry['node'], 'orders'));
                $children = array_filter($nodes, static fn (array $entry): bool => psOn($entry['node'], 'invoices'));

                psAssertShape($plan, invoices: true);

                expect($parents)->not->toBeEmpty()
                    ->and($children)->not->toBeEmpty()
                    ->and(array_filter($nodes, static fn (array $entry): bool => $entry['node']['Node Type'] === 'Seq Scan' && in_array($entry['node']['Relation Name'] ?? '', ['orders', 'invoices'], true)))->toBe([]);
            });
        }
    }

    psCheck('similar', function (): void {
        $statement = SimilarQuery::sql(app(ModelSearchRegistry::class)->for(Order::class), OrderSearch::query('kowalsky')->within(Order::query()));

        expect($statement)->not->toBeNull();

        $nodes = psWalk(psPlan($statement));
        $names = array_filter($nodes, static fn (array $entry): bool => str_contains((string) ($entry['node']['Index Name'] ?? ''), 'entries_names'));
        $hosts = array_values(array_filter($nodes, static fn (array $entry): bool => psOn($entry['node'], 'orders')));

        expect($names)->not->toBeEmpty()
            ->and($hosts)->not->toBeEmpty()
            ->and(array_filter($nodes, static fn (array $entry): bool => $entry['node']['Node Type'] === 'Seq Scan' && psOn($entry['node'], TableNames::forConnection()->entries())))->toBe([])
            ->and(array_filter($nodes, static fn (array $entry): bool => str_starts_with((string) $entry['node']['Node Type'], 'Gather')))->toBe([]);

        foreach ($hosts as ['node' => $node, 'ancestors' => $ancestors]) {
            expect($node['Node Type'])->toBeIn(['Index Scan', 'Index Only Scan'])
                ->and($node['Index Name'])->toBe('orders_pkey')
                ->and($ancestors[array_key_last($ancestors)]['Node Type'])->toBe('Nested Loop')
                ->and($node['Parent Relationship'])->toBe('Inner');
        }
    });
});

it('uses the predicates of the index plan in every branch', function (): void {
    $query = OrderSearch::query('abc12')->within(Order::query());
    $sql = RoundOne::sql(psTargets($query))['sql'];

    expect($sql)->toContain('k.'.IndexPlan::GENERIC_KEYS)
        ->and($sql)->toContain('k.'.IndexPlan::STAGE_ZERO);
});
