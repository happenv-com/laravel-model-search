<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Facades\ModelSearches;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Illuminate\Support\Facades\DB;

covers(CompiledDefinition::class);

describe('watched tables', function (): void {
    it('lists root watched columns with stage columns, soft deletes and BelongsTo foreign keys', function (): void {
        $orders = collect(ModelSearches::for(Order::class)->watchedTables())->firstWhere('table', 'orders');
        $products = collect(ModelSearches::for(Product::class)->watchedTables())->firstWhere('table', 'products');

        expect($orders->shape)->toBe('self')
            ->and($orders->parentNodeType)->toBe('order')
            ->and($orders->columns)->toContain('signature', 'external_number', 'customer_email', 'customer_name', 'state', 'purchased_at', 'billing_address_id', 'shipping_address_id')
            ->and($orders->columns)->not->toContain('deleted_at')
            ->and($products->columns)->toContain('deleted_at', 'sku');
    });

    it('merges the tables of every path of the definition', function (): void {
        $tables = ModelSearches::for(Order::class)->watchedTables();
        $addresses = array_values(array_filter($tables, static fn ($table): bool => $table->table === 'addresses'));

        expect($addresses)->toHaveCount(1)
            ->and($addresses[0]->columns)->toBe(['name', 'phone']);
    });
});

describe('stage sql', function (): void {
    it('assigns the stage of every root in SQL', function (): void {
        $open = Order::create(['signature' => 'A', 'state' => 'unshipped']);
        $shipped = Order::create(['signature' => 'B', 'state' => 'shipped']);

        $query = ModelSearches::for(Order::class)->stageSql([$open->id, $shipped->id]);
        $stages = collect(DB::select($query['sql'], $query['bindings']))->pluck('stage', 'root_id')->all();

        expect($stages)->toBe([$open->id => 0, $shipped->id => 32767])
            ->and($query['sql'])->not->toContain($open->id);
    });

    it('puts every root in the all stage when the definition has no stages', function (): void {
        $product = Product::create(['sku' => 'X']);

        $query = ModelSearches::for(Product::class)->stageSql([$product->id]);

        expect(collect(DB::select($query['sql'], $query['bindings']))->pluck('stage', 'root_id')->all())->toBe([(string) $product->id => 32767]);
    });
});

describe('stage sql bindings', function (): void {
    it('does not bind more than one id parameter however many roots are staged', function (): void {
        $few = ModelSearches::for(Order::class)->stageSql(['a']);
        $many = ModelSearches::for(Order::class)->stageSql(array_map(strval(...), range(1, 1500)));

        expect(count($many['bindings']))->toBe(count($few['bindings']))
            ->and(substr_count($many['sql'], '?'))->toBe(count($many['bindings']))
            ->and($many['sql'])->toContain('unnest(?::uuid[])');
    });
});
