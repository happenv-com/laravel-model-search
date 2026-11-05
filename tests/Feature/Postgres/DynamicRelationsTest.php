<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Compilation\RelationNames;
use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\UnsupportedRelation;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Address;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicInvoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicOrder;
use Happenv\ModelSearch\Tests\Fixtures\Models\MarketOrder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\DynamicInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\DynamicOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

covers(RelationNames::class);

beforeEach(function (): void {
    // What a modular extension does in its provider: relations a model does not declare.
    DynamicOrder::resolveRelationUsing('dynamicChild', static fn (DynamicOrder $order) => $order->hasOne(MarketOrder::class, 'order_id'));
    DynamicOrder::resolveRelationUsing('dynamicBilling', static fn (DynamicOrder $order) => $order->belongsTo(Address::class, 'billing_address_id'));
    DynamicOrder::resolveRelationUsing('dynamicSubject', static fn (DynamicOrder $order) => $order->morphTo('subject'));
    DynamicOrder::resolveRelationUsing('brokenRelation', static fn (): string => 'not a relation');
    DynamicOrder::resolveRelationUsing('parcels', static fn (DynamicOrder $order) => $order->belongsTo(Address::class, 'billing_address_id'));
    DynamicInvoice::resolveRelationUsing('dynamicOrder', static fn (DynamicInvoice $invoice) => $invoice->belongsTo(Order::class, 'order_id'));
});

/**
 * Registers the definitions of the test, installs their triggers and indexes what exists.
 *
 * @param  list<class-string>  $definitions
 */
function drUse(array $definitions): void
{
    $registry = new ModelSearchRegistry;

    foreach ($definitions as $definition) {
        $registry->register($definition);
    }

    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    new SchemaSync($registry)->apply();
}

/**
 * @return list<string>
 */
function drOrders(string $phrase): array
{
    return array_map(
        static fn ($result): string => $result->model->signature,
        iterator_to_array(DynamicOrderSearch::query($phrase)->within(DynamicOrder::query())->get()),
    );
}

describe('the shared rule', function (): void {
    it('knows declared methods and registered resolvers', function (): void {
        $order = new DynamicOrder;

        expect(RelationNames::exists($order, 'billingAddress'))->toBeTrue()
            ->and(RelationNames::exists($order, 'dynamicChild'))->toBeTrue()
            ->and(RelationNames::exists($order, 'nothingHere'))->toBeFalse()
            ->and(RelationNames::find($order, 'dynamicChild'))->toBeInstanceOf(HasOne::class)
            ->and(RelationNames::find($order, 'brokenRelation'))->toBeNull()
            ->and(RelationNames::find($order, 'nothingHere'))->toBeNull();
    });

    it('does not see a resolver registered on a sibling class', function (): void {
        expect(RelationNames::exists(new Order, 'dynamicChild'))->toBeFalse();
    });
});

describe('path compilation', function (): void {
    it('follows a registered HasOne and BelongsTo like a declared relation', function (): void {
        $child = new PathCompiler()->compile(DynamicOrder::class, 'dynamicChild.market_order_number');
        $parent = new PathCompiler()->compile(DynamicOrder::class, 'dynamicBilling.phone');

        expect($child->tables()[0]->table)->toBe('market_orders')
            ->and($child->tables()[0]->shape)->toBe('child')
            ->and($parent->tables()[0]->table)->toBe('addresses')
            ->and($parent->tables()[0]->shape)->toBe('self');
    });

    it('keeps a declared method ahead of a resolver of the same name', function (): void {
        $path = new PathCompiler()->compile(DynamicOrder::class, 'parcels.reference');

        expect($path->tables()[0]->table)->toBe('parcels');
    });

    it('still refuses an unknown name', function (): void {
        new PathCompiler()->compile(DynamicOrder::class, 'nothingHere.phone');
    })->throws(InvalidFieldPath::class, 'is not a relation of');

    it('refuses a resolver that does not return a relation', function (): void {
        new PathCompiler()->compile(DynamicOrder::class, 'brokenRelation.phone');
    })->throws(InvalidFieldPath::class, 'does not return a relation');

    it('still refuses a MorphTo, registered or declared', function (): void {
        new PathCompiler()->compile(DynamicOrder::class, 'dynamicSubject.id');
    })->throws(UnsupportedRelation::class, 'MorphTo');

    it('accepts a registered BelongsTo as a via relation and refuses an unknown one', function (): void {
        new PathCompiler()->assertRelation(DynamicInvoice::class, 'dynamicOrder');

        expect(static fn () => new PathCompiler()->assertRelation(DynamicInvoice::class, 'missing'))->toThrow(InvalidFieldPath::class);
    });
});

describe('searching through a registered relation', function (): void {
    it('finds a key held by a registered HasOne after a drain and follows the child table', function (): void {
        drUse([DynamicOrderSearch::class]);
        $order = DynamicOrder::create(['signature' => 'DYN-1']);
        MarketOrder::create(['order_id' => $order->id, 'market_order_number' => 'MO-7001']);
        msIndex();

        expect(drOrders('MO-7001'))->toBe(['DYN-1']);

        DB::table('market_orders')->where('order_id', $order->id)->update(['market_order_number' => 'MO-7002']);
        msIndex();

        expect(drOrders('MO-7001'))->toBe([])
            ->and(drOrders('MO-7002'))->toBe(['DYN-1']);
    });

    it('finds an invoice through a parent reached by a registered BelongsTo', function (): void {
        drUse([DynamicInvoiceSearch::class, OrderSearch::class]);
        $order = Order::create(['signature' => 'PAR-1']);
        DynamicInvoice::create(['number' => 'FV-D1', 'order_id' => $order->id]);
        DynamicInvoice::create(['number' => 'FV-D2']);
        msIndex();

        $results = DynamicInvoiceSearch::query('PAR-1')->within(DynamicInvoice::query(), [Order::class => Order::query()])->get();

        expect(array_map(static fn ($result): string => $result->model->number, iterator_to_array($results)))->toBe(['FV-D1']);
    });

    it('is found by the database driver too', function (): void {
        config(['model-search.driver' => 'database']);
        drUse([DynamicOrderSearch::class]);
        $order = DynamicOrder::create(['signature' => 'DYN-2']);
        MarketOrder::create(['order_id' => $order->id, 'market_order_number' => 'MO-7100']);

        expect(drOrders('MO-7100'))->toBe(['DYN-2']);
    });

    it('finds an invoice through a registered parent with the database driver', function (): void {
        config(['model-search.driver' => 'database']);
        drUse([DynamicInvoiceSearch::class, OrderSearch::class]);
        $order = Order::create(['signature' => 'PAR-2']);
        DynamicInvoice::create(['number' => 'FV-D3', 'order_id' => $order->id]);

        $results = DynamicInvoiceSearch::query('PAR-2')->within(DynamicInvoice::query(), [Order::class => Order::query()])->get();

        expect(array_map(static fn ($result): string => $result->model->number, iterator_to_array($results)))->toBe(['FV-D3']);
    });
});
