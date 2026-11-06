<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Enums\MarketOrderKind;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(PathCompiler::class);

beforeEach(fn () => ConfigurableOrderSearch::reset());
afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * @param  list<SearchField>  $fields
 */
function scUse(array $fields, string $driver = 'postgres'): ModelSearchRegistry
{
    config(['model-search.driver' => $driver]);
    ConfigurableOrderSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    new SchemaSync($registry)->apply();

    return $registry;
}

function scOrder(string $signature): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => $signature]);

    return $id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function scMarketOrder(string $order, string $number, array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('market_orders')->insert(['id' => $id, 'order_id' => $order, 'market_order_number' => $number, ...$attributes]);

    return $id;
}

/**
 * @return list<string> Signatures of the orders found, sorted.
 */
function scFind(string $phrase): array
{
    $signatures = array_map(
        static fn ($result): string => $result->model->signature,
        iterator_to_array(ConfigurableOrderSearch::query($phrase)->within(Order::query())->get()),
    );
    sort($signatures);

    return $signatures;
}

function scField(): SearchField
{
    return SearchField::key('marketOrder.market_order_number')->kind('market');
}

describe('postgres driver', function (): void {
    it('indexes only the related rows that match a scalar condition', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', 'order')]);
        $matching = scOrder('SIG-A');
        $other = scOrder('SIG-B');
        scMarketOrder($matching, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($matching, 'MO-INVOICE', ['kind' => 'invoice']);
        scMarketOrder($other, 'MO-OTHER', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])
            ->and(scFind('MO-INVOICE'))->toBe([])
            ->and(scFind('MO-OTHER'))->toBe([]);
    });

    it('takes the value of a backed enum', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', MarketOrderKind::Order)]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($id, 'MO-INVOICE', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])->and(scFind('MO-INVOICE'))->toBe([]);
    });

    it('takes a list of values as any-of', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', [MarketOrderKind::Order, 'credit'])]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($id, 'MO-CREDIT', ['kind' => 'credit']);
        scMarketOrder($id, 'MO-INVOICE', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])
            ->and(scFind('MO-CREDIT'))->toBe(['SIG-A'])
            ->and(scFind('MO-INVOICE'))->toBe([]);
    });

    it('combines several conditions with AND', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', 'order')->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-BOTH', ['kind' => 'order', 'status' => 'created']);
        scMarketOrder($id, 'MO-KIND', ['kind' => 'order', 'status' => 'cancelled']);
        scMarketOrder($id, 'MO-STATUS', ['kind' => 'invoice', 'status' => 'created']);
        msIndex();

        expect(scFind('MO-BOTH'))->toBe(['SIG-A'])
            ->and(scFind('MO-KIND'))->toBe([])
            ->and(scFind('MO-STATUS'))->toBe([]);
    });

    it('drops a row out of the index when its condition column stops matching, and brings it back', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        $marketOrder = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-A']);

        DB::table('market_orders')->where('id', $marketOrder)->update(['status' => 'cancelled']);
        msIndex();
        expect(scFind('MO-1'))->toBe([]);

        DB::table('market_orders')->where('id', $marketOrder)->update(['status' => 'created']);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-A']);
    });

    it('adds a row that starts matching and ignores a new row that does not', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        $late = scMarketOrder($id, 'MO-LATE', ['status' => 'draft']);
        msIndex();
        expect(scFind('MO-LATE'))->toBe([]);

        DB::table('market_orders')->where('id', $late)->update(['status' => 'created']);
        scMarketOrder($id, 'MO-DRAFT', ['status' => 'draft']);
        scMarketOrder($id, 'MO-NEW-OK', ['status' => 'created']);
        msIndex();

        expect(scFind('MO-LATE'))->toBe(['SIG-A'])
            ->and(scFind('MO-DRAFT'))->toBe([])
            ->and(scFind('MO-NEW-OK'))->toBe(['SIG-A']);
    });

    it('restricts a self step (a belongs to relation) and follows its condition column', function (): void {
        scUse([SearchField::key('signature'), SearchField::key('billingAddress.phone')->kind('phone')->where('billingAddress', 'kind', 'billing')]);
        $address = (string) Str::uuid();
        DB::table('addresses')->insert(['id' => $address, 'phone' => '555-0101', 'kind' => 'billing']);
        $id = scOrder('SIG-A');
        DB::table('orders')->where('id', $id)->update(['billing_address_id' => $address]);
        msIndex();
        expect(scFind('555-0101'))->toBe(['SIG-A']);

        DB::table('addresses')->where('id', $address)->update(['kind' => 'shipping']);
        msIndex();
        expect(scFind('555-0101'))->toBe([]);

        DB::table('addresses')->where('id', $address)->update(['kind' => 'billing']);
        msIndex();
        expect(scFind('555-0101'))->toBe(['SIG-A']);
    });

    it('restricts a step in the middle of a longer path by its dotted name', function (): void {
        scUse([SearchField::key('signature'), SearchField::key('parcels.carrierUnits.tracking_number')->kind('tracking')->where('parcels', 'reference', 'main')]);
        $id = scOrder('SIG-A');
        $main = (string) Str::uuid();
        $side = (string) Str::uuid();
        DB::table('parcels')->insert([['id' => $main, 'order_id' => $id, 'reference' => 'main'], ['id' => $side, 'order_id' => $id, 'reference' => 'side']]);
        DB::table('carrier_units')->insert([['parcel_id' => $main, 'tracking_number' => 'TRK-MAIN'], ['parcel_id' => $side, 'tracking_number' => 'TRK-SIDE']]);
        msIndex();

        expect(scFind('TRK-MAIN'))->toBe(['SIG-A'])->and(scFind('TRK-SIDE'))->toBe([]);

        // The parcel is the deeper node's parent: moving it out of the condition removes its units from the index.
        DB::table('parcels')->where('id', $main)->update(['reference' => 'other']);
        msIndex();
        expect(scFind('TRK-MAIN'))->toBe([]);
    });

    it('logs a change for the row that leaves the condition, and nothing for a row that never matched', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        DB::table(TableNames::forConnection()->changes())->delete();

        $never = scMarketOrder($id, 'MO-NEVER', ['status' => 'draft']);
        DB::table('market_orders')->where('id', $never)->update(['market_order_number' => 'MO-NEVER-2']);
        DB::table('market_orders')->where('id', $never)->delete();
        $logged = static fn (): array => DB::table(TableNames::forConnection()->changes())->pluck('node_id')->all();
        expect($logged())->toBe([]);

        $row = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        DB::table(TableNames::forConnection()->changes())->delete();
        DB::table('market_orders')->where('id', $row)->update(['status' => 'cancelled']);
        expect($logged())->toBe([$id]);
    });

    it('renders the condition into the trigger function', function (): void {
        $registry = scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', ['order', "o'k"])]);

        $sql = collect(new SchemaSync($registry)->plan()->triggers)->map(static fn ($trigger): string => $trigger->functionBody)->implode("\n");

        expect($sql)->toContain('"kind" IN (\'order\', \'o\'\'k\')');
    });
});

describe('definition', function (): void {
    it('fingerprints the conditions, so changing one rebuilds the type', function (): void {
        $fingerprint = static function (array $fields): string {
            ConfigurableOrderSearch::$fields = $fields;
            $registry = new ModelSearchRegistry;
            $registry->register(ConfigurableOrderSearch::class);

            return Fingerprint::of($registry->for(Order::class));
        };

        $plain = $fingerprint([scField()]);
        $created = $fingerprint([scField()->where('marketOrder', 'status', 'created')]);
        $cancelled = $fingerprint([scField()->where('marketOrder', 'status', 'cancelled')]);
        $list = $fingerprint([scField()->where('marketOrder', 'status', ['created', 'cancelled'])]);

        expect($created)->not->toBe($plain)
            ->and($cancelled)->not->toBe($created)
            ->and($list)->not->toBe($created)
            ->and($fingerprint([scField()->where('marketOrder', 'status', 'created')]))->toBe($created);
    });

    it('refuses a condition on a relation that is not on the path', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'marketOrder.market_order_number', conditions: scField()->where('parcels', 'reference', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'not a relation segment of its path');
    });

    it('refuses a condition on a field without relations', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'signature', conditions: SearchField::key('signature')->where('marketOrder', 'kind', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'the path has no relations');
    });

    it('refuses a column the step table does not have', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'marketOrder.market_order_number', conditions: scField()->where('marketOrder', 'nope', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'has no such column');
    });

    it('refuses a condition that is not a scalar, an enum or a list of them', function (mixed $value): void {
        expect(static fn () => scField()->where('marketOrder', 'kind', $value))->toThrow(InvalidSearchDefinition::class);
    })->with([
        'an empty list' => [[]],
        'a list with null' => [['a', null]],
        'a nested list' => [[['a']]],
        'a keyed array' => [['a' => 'b']],
    ]);

    it('refuses a column that is not a plain name', function (): void {
        expect(static fn () => scField()->where('marketOrder', 'kind; drop table x', 'a'))->toThrow(InvalidSearchDefinition::class);
    });

    it('refuses a condition on a via field', function (): void {
        expect(static fn () => SearchField::via('billingAddress')->where('billingAddress', 'kind', 'x'))->toThrow(InvalidSearchDefinition::class);
    });

    it('is reported when the definition is registered', function (): void {
        ConfigurableOrderSearch::$fields = [scField()->where('parcels', 'reference', 'x')];
        $registry = new ModelSearchRegistry;
        $registry->register(ConfigurableOrderSearch::class);

        expect(static fn () => $registry->for(Order::class))->toThrow(InvalidSearchDefinition::class);
    });
});

describe('database driver', function (): void {
    it('reads only the rows that match the condition', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')], 'database');
        $id = scOrder('SIG-A');
        $row = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        scMarketOrder($id, 'MO-2', ['status' => 'cancelled']);

        expect(scFind('MO-1'))->toBe(['SIG-A'])->and(scFind('MO-2'))->toBe([]);

        DB::table('market_orders')->where('id', $row)->update(['status' => 'cancelled']);
        expect(scFind('MO-1'))->toBe([]);
    });
});
