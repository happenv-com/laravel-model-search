<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

covers(PathCompiler::class);

describe('a relation that carries query conditions', function (): void {
    it('is refused with a message that names the model, the relation and the field path', function (string $relation, string $column): void {
        $path = $relation.'.'.$column;

        expect(static fn () => new PathCompiler()->compile(Order::class, $path))
            ->toThrow(InvalidSearchDefinition::class, Order::class.'::'.$relation.'()');

        try {
            new PathCompiler()->compile(Order::class, $path);
        } catch (InvalidSearchDefinition $exception) {
            expect($exception->getMessage())
                ->toContain('"'.$path.'"')
                ->toContain('where(');
        }
    })->with([
        'a where on a child step' => ['whereMarketOrder', 'market_order_number'],
        'a nested where' => ['nestedWhereMarketOrder', 'market_order_number'],
        'a raw where' => ['rawWhereMarketOrder', 'market_order_number'],
        'a join' => ['joinedMarketOrder', 'market_order_number'],
        'a limit' => ['limitedMarketOrder', 'market_order_number'],
        'a latest of many' => ['latestMarketOrder', 'market_order_number'],
        'a where on a self step' => ['whereBillingAddress', 'phone'],
        'a where on a has many' => ['whereMarketOrders', 'market_order_number'],
        'a where on a through relation' => ['whereParcelCarrierUnits', 'tracking_number'],
        'a limit on a through relation' => ['limitedParcelCarrierUnits', 'tracking_number'],
        'a where on a pivot relation' => ['whereTags', 'name'],
        'a pivot where on a pivot relation' => ['wherePivotTags', 'name'],
        'a where on a morph relation' => ['whereNotes', 'body'],
    ]);

    it('is refused in the middle of a longer path too', function (): void {
        expect(static fn () => new PathCompiler()->compile(Order::class, 'whereMarketOrder.order.signature'))
            ->toThrow(InvalidSearchDefinition::class, 'whereMarketOrder()');
    });

    it('is refused by assertRelation() for a via field', function (): void {
        expect(static fn () => new PathCompiler()->assertRelation(Order::class, 'whereBillingAddress'))
            ->toThrow(InvalidSearchDefinition::class, Order::class.'::whereBillingAddress()');
    });

    it('compiles when it only orders its rows', function (): void {
        expect(new PathCompiler()->compile(Order::class, 'orderedMarketOrder.market_order_number'))->not->toBeNull();
    });

    it('compiles through the relations that carry no conditions of their own', function (string $path): void {
        expect(new PathCompiler()->compile(Order::class, $path))->not->toBeNull();
    })->with(['parcelCarrierUnits.tracking_number', 'tags.name', 'notes.body', 'latestNote.body', 'parcels.carrierUnits.tracking_number']);
});
