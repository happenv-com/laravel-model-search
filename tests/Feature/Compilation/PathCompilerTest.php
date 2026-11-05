<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\CompiledPath;
use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Compilation\ReverseHop;
use Happenv\ModelSearch\Compilation\WatchedTable;
use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\UnsupportedRelation;
use Happenv\ModelSearch\Tests\Fixtures\Models\Address;
use Happenv\ModelSearch\Tests\Fixtures\Models\CarrierUnit;
use Happenv\ModelSearch\Tests\Fixtures\Models\Country;
use Happenv\ModelSearch\Tests\Fixtures\Models\MarketOrder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Note;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Models\Tag;
use Happenv\ModelSearch\Tests\Fixtures\Models\UlidRecord;
use Happenv\ModelSearch\Tests\Fixtures\Scopes\NamedAddressScope;
use Happenv\ModelSearch\Tests\Fixtures\Scopes\RecentAddressScope;
use Illuminate\Support\Facades\DB;

covers(PathCompiler::class);

/**
 * @param  list<string|int>  $ids
 * @return list<string>
 */
function contributionsOf(CompiledPath $path, array $ids): array
{
    $query = $path->contributionSql($ids);
    $rows = array_map(
        static fn (object $row): string => $row->root_id.'|'.$row->value,
        DB::select($query['sql'], $query['bindings']),
    );
    sort($rows);

    return $rows;
}

/**
 * @param  list<string|int>  $nodeIds
 * @return list<array{string, string}> (node_id, root_id) rows, sorted.
 */
function hopRowsOf(CompiledPath $path, string $nodeType, array $nodeIds, int $limit = 100): array
{
    foreach ($path->reverseHops() as $hop) {
        if ($hop->nodeType === $nodeType) {
            $query = $hop->rootsSql($nodeType, $nodeIds, $limit);
            $rows = array_map(static fn (object $row): array => [(string) $row->node_id, (string) $row->root_id], DB::select($query['sql'], $query['bindings']));
            sort($rows);

            return $rows;
        }
    }

    throw new RuntimeException('No reverse hop for '.$nodeType);
}

/**
 * @param  list<string|int>  $nodeIds
 * @return list<string>
 */
function rootsOf(CompiledPath $path, string $nodeType, array $nodeIds, int $limit = 100): array
{
    $roots = array_values(array_unique(array_map(static fn (array $row): string => $row[1], hopRowsOf($path, $nodeType, $nodeIds, $limit))));
    sort($roots);

    return $roots;
}

function compilePath(string $model, string $path, bool $translatable = false, array $withScopes = []): CompiledPath
{
    return new PathCompiler()->compile($model, $path, $translatable, $withScopes);
}

describe('contributions', function (): void {
    it('reads an own column without any join', function (): void {
        $order = Order::create(['signature' => 'SIG-1']);
        Order::create(['signature' => 'SIG-2']);

        expect(contributionsOf(compilePath(Order::class, 'signature'), [$order->id]))->toBe([$order->id.'|SIG-1']);
    });

    it('follows a HasOne to the market order number', function (): void {
        $order = Order::create(['signature' => 'A']);
        MarketOrder::create(['order_id' => $order->id, 'market_order_number' => '302-1']);

        expect(contributionsOf(compilePath(Order::class, 'marketOrder.market_order_number'), [$order->id]))->toBe([$order->id.'|302-1']);
    });

    it('follows a BelongsTo to the billing phone and skips orders without one', function (): void {
        $address = Address::create(['phone' => '+48 600 100 200']);
        $with = Order::create(['signature' => 'A', 'billing_address_id' => $address->id]);
        $without = Order::create(['signature' => 'B']);

        expect(contributionsOf(compilePath(Order::class, 'billingAddress.phone'), [$with->id, $without->id]))
            ->toBe([$with->id.'|+48 600 100 200']);
    });

    it('follows a HasOne that carries the order key to the tracking number', function (): void {
        $order = Order::create(['signature' => 'A']);
        CarrierUnit::create(['order_id' => $order->id, 'tracking_number' => 'TRK-9']);

        expect(contributionsOf(compilePath(Order::class, 'carrierUnit.tracking_number'), [$order->id]))->toBe([$order->id.'|TRK-9']);
    });

    it('follows a BelongsToMany through the pivot table to the tag names', function (): void {
        $order = Order::create(['signature' => 'A']);
        $vip = Tag::create(['name' => 'vip']);
        $gift = Tag::create(['name' => 'gift']);
        $order->tags()->attach([$vip->id, $gift->id]);
        Order::create(['signature' => 'B'])->tags()->attach($vip->id);

        expect(contributionsOf(compilePath(Order::class, 'tags.name'), [$order->id]))
            ->toBe([$order->id.'|gift', $order->id.'|vip']);
    });

    it('follows HasOneThrough and HasManyThrough', function (): void {
        $order = Order::create(['signature' => 'A']);
        $first = Parcel::create(['order_id' => $order->id]);
        $second = Parcel::create(['order_id' => $order->id]);
        CarrierUnit::create(['parcel_id' => $first->id, 'tracking_number' => 'T1']);
        CarrierUnit::create(['parcel_id' => $second->id, 'tracking_number' => 'T2']);

        expect(contributionsOf(compilePath(Order::class, 'parcelCarrierUnits.tracking_number'), [$order->id]))
            ->toBe([$order->id.'|T1', $order->id.'|T2'])
            ->and(contributionsOf(compilePath(Order::class, 'parcelCarrierUnit.tracking_number'), [$order->id]))
            ->toHaveCount(2);
    });

    it('follows MorphOne and MorphMany only for the morph class of the root', function (): void {
        $order = Order::create(['signature' => 'A']);
        Note::create(['noteable_type' => 'order', 'noteable_id' => $order->id, 'body' => 'call back']);
        Note::create(['noteable_type' => 'parcel', 'noteable_id' => $order->id, 'body' => 'foreign']);

        expect(contributionsOf(compilePath(Order::class, 'notes.body'), [$order->id]))->toBe([$order->id.'|call back'])
            ->and(contributionsOf(compilePath(Order::class, 'latestNote.body'), [$order->id]))->toBe([$order->id.'|call back']);
    });

    it('follows a chain of relations', function (): void {
        $country = Country::create(['name' => 'Poland']);
        $address = Address::create(['country_id' => $country->id]);
        $order = Order::create(['signature' => 'A', 'billing_address_id' => $address->id]);

        expect(contributionsOf(compilePath(Order::class, 'billingAddress.country.name'), [$order->id]))->toBe([$order->id.'|Poland']);
    });

    it('binds the root keys instead of interpolating them', function (): void {
        $query = compilePath(Order::class, 'signature')->contributionSql(['a\'b', 'c']);

        expect($query['sql'])->not->toContain('a\'b')
            ->and($query['bindings'])->toContain('{"a\'b","c"}');
    });

    it('excludes soft-deleted rows on paths', function (): void {
        $order = Order::create(['signature' => 'A']);
        $kept = Parcel::create(['order_id' => $order->id, 'reference' => 'KEPT']);
        $gone = Parcel::create(['order_id' => $order->id, 'reference' => 'GONE']);
        CarrierUnit::create(['parcel_id' => $kept->id, 'tracking_number' => 'T-KEPT']);
        CarrierUnit::create(['parcel_id' => $gone->id, 'tracking_number' => 'T-GONE']);
        $gone->delete();
        $deletedProduct = Product::create(['sku' => 'SKU-1']);
        Product::create(['sku' => 'SKU-2']);
        $deletedProduct->delete();

        expect(contributionsOf(compilePath(Order::class, 'parcels.reference'), [$order->id]))->toBe([$order->id.'|KEPT'])
            ->and(contributionsOf(compilePath(Order::class, 'parcelCarrierUnits.tracking_number'), [$order->id]))->toBe([$order->id.'|T-KEPT'])
            ->and(contributionsOf(compilePath(Product::class, 'sku'), [$deletedProduct->id]))->toBe([]);
    });

    it('ignores global scopes unless the definition lists them', function (): void {
        $named = Address::create(['name' => 'Ann', 'phone' => '1']);
        $nameless = Address::create(['phone' => '2']);
        $first = Order::create(['signature' => 'A', 'billing_address_id' => $named->id]);
        $second = Order::create(['signature' => 'B', 'billing_address_id' => $nameless->id]);
        $ids = [$first->id, $second->id];

        expect(contributionsOf(compilePath(Order::class, 'scopedBillingAddress.phone'), $ids))->toHaveCount(2)
            ->and(contributionsOf(compilePath(Order::class, 'scopedBillingAddress.phone', withScopes: [NamedAddressScope::class]), $ids))
            ->toBe([$first->id.'|1']);
    });

    it('indexes every locale of a translatable field', function (): void {
        $product = Product::create(['sku' => 'P', 'name' => ['pl' => 'Kot', 'en' => 'Cat']]);
        $broken = Product::create(['sku' => 'Q', 'name' => 'plain string']);

        expect(contributionsOf(compilePath(Product::class, 'name', translatable: true), [$product->id, $broken->id]))
            ->toBe([$product->id.'|Cat', $product->id.'|Kot']);
    });
});

describe('validation', function (): void {
    it('rejects MorphTo', function (): void {
        compilePath(Order::class, 'subject.name');
    })->throws(UnsupportedRelation::class);

    it('rejects an unknown relation', function (): void {
        compilePath(Order::class, 'nothing.name');
    })->throws(InvalidFieldPath::class);

    it('rejects a method that is not a relation', function (): void {
        compilePath(Order::class, 'notARelation.name');
    })->throws(InvalidFieldPath::class);
});

describe('watched tables', function (): void {
    it('derives watched tables with child and self shapes', function (): void {
        $tags = compilePath(Order::class, 'tags.name')->tables();
        $phone = compilePath(Order::class, 'billingAddress.phone');
        $marketOrder = compilePath(Order::class, 'marketOrder.market_order_number')->tables();

        expect($tags)->toHaveCount(2)
            ->and($tags[0])->toBeInstanceOf(WatchedTable::class)
            ->and([$tags[0]->table, $tags[0]->shape, $tags[0]->parentNodeType, $tags[0]->fkColumn, $tags[0]->columns])
            ->toBe(['order_tag', 'child', 'order', 'order_id', ['order_id', 'tag_id']])
            ->and([$tags[1]->table, $tags[1]->shape, $tags[1]->parentNodeType, $tags[1]->fkColumn, $tags[1]->columns])
            ->toBe(['tags', 'self', 'table:tags', 'id', ['name']])
            ->and($phone->tables())->toHaveCount(1)
            ->and([$phone->tables()[0]->table, $phone->tables()[0]->shape, $phone->tables()[0]->columns])->toBe(['addresses', 'self', ['phone']])
            ->and($phone->rootColumns())->toBe(['billing_address_id'])
            ->and([$marketOrder[0]->shape, $marketOrder[0]->parentNodeType, $marketOrder[0]->fkColumn, $marketOrder[0]->columns])
            ->toBe(['child', 'order', 'order_id', ['market_order_number', 'order_id']]);
    });

    it('watches the keys and soft deletes of intermediate tables', function (): void {
        $tables = compilePath(Order::class, 'parcelCarrierUnits.tracking_number')->tables();

        expect($tables[0]->table)->toBe('parcels')
            ->and($tables[0]->columns)->toBe(['deleted_at', 'order_id'])
            ->and($tables[1]->table)->toBe('carrier_units')
            ->and($tables[1]->parentNodeType)->toBe('table:parcels')
            ->and($tables[1]->columns)->toBe(['parcel_id', 'tracking_number']);
    });

    it('watches the foreign key of an intermediate BelongsTo', function (): void {
        $tables = compilePath(Order::class, 'billingAddress.country.name')->tables();

        expect($tables[0]->table)->toBe('addresses')
            ->and($tables[0]->columns)->toBe(['country_id'])
            ->and($tables[1]->table)->toBe('countries')
            ->and($tables[1]->columns)->toBe(['name']);
    });

    it('reads an own column straight from the root', function (): void {
        $path = compilePath(Order::class, 'signature');

        expect($path->tables())->toBe([])
            ->and($path->rootColumns())->toBe(['signature'])
            ->and($path->reverseHops())->toBe([]);
    });
});

describe('reverse hops', function (): void {
    it('finds all roots of a shared address', function (): void {
        $shared = Address::create(['phone' => '1']);
        $other = Address::create(['phone' => '2']);
        $first = Order::create(['signature' => 'A', 'billing_address_id' => $shared->id]);
        $second = Order::create(['signature' => 'B', 'billing_address_id' => $shared->id]);
        Order::create(['signature' => 'C', 'billing_address_id' => $other->id]);
        $expected = [$first->id, $second->id];
        sort($expected);

        $path = compilePath(Order::class, 'billingAddress.phone');

        expect($path->reverseHops())->toHaveCount(1)
            ->and($path->reverseHops()[0])->toBeInstanceOf(ReverseHop::class)
            ->and(rootsOf($path, 'table:addresses', [$shared->id]))->toBe($expected)
            ->and(rootsOf($path, 'table:addresses', [$shared->id], 1))->toHaveCount(1);
    });

    it('walks back up a chain, through the pivot and through intermediate rows', function (): void {
        $country = Country::create(['name' => 'Poland']);
        $address = Address::create(['country_id' => $country->id]);
        $viaCountry = Order::create(['signature' => 'A', 'billing_address_id' => $address->id]);
        $tag = Tag::create(['name' => 'vip']);
        $tagged = Order::create(['signature' => 'B']);
        $tagged->tags()->attach($tag->id);
        $parcel = Parcel::create(['order_id' => $tagged->id]);
        CarrierUnit::create(['parcel_id' => $parcel->id, 'tracking_number' => 'T']);

        expect(rootsOf(compilePath(Order::class, 'billingAddress.country.name'), 'table:countries', [$country->id]))->toBe([$viaCountry->id])
            ->and(rootsOf(compilePath(Order::class, 'tags.name'), 'table:tags', [$tag->id]))->toBe([$tagged->id])
            ->and(rootsOf(compilePath(Order::class, 'parcelCarrierUnits.tracking_number'), 'table:parcels', [$parcel->id]))->toBe([$tagged->id]);
    });

    it('still expands a node that is soft-deleted', function (): void {
        $order = Order::create(['signature' => 'A']);
        $parcel = Parcel::create(['order_id' => $order->id]);
        $parcel->delete();

        expect(rootsOf(compilePath(Order::class, 'parcelCarrierUnits.tracking_number'), 'table:parcels', [$parcel->id]))->toBe([$order->id]);
    });

    it('refuses a node type it does not handle', function (): void {
        $hop = compilePath(Order::class, 'billingAddress.phone')->reverseHops()[0];

        $hop->rootsSql('table:parcels', ['x'], 1);
    })->throws(InvalidArgumentException::class);
});

describe('column types', function (): void {
    it('binds the real type of a fixed-width ULID key and never casts to text', function (): void {
        $record = UlidRecord::create(['code' => 'U-1']);
        $path = compilePath(UlidRecord::class, 'code');
        $query = $path->contributionSql([$record->id]);

        expect($query['sql'])->toContain('::bpchar[]')->not->toContain('::text = ANY')
            ->and(contributionsOf($path, [$record->id]))->toBe([$record->id.'|U-1']);
    });

    it('compiles paths that join on columns other than the primary key', function (): void {
        $address = Address::create(['code' => 'ADDR-1', 'phone' => '77']);
        $order = Order::create(['signature' => 'SIG-9', 'billing_code' => 'ADDR-1']);
        MarketOrder::create(['order_id' => $order->id, 'order_signature' => 'SIG-9', 'market_order_number' => 'MO-1']);

        $byCode = compilePath(Order::class, 'billingAddressByCode.phone');
        $bySignature = compilePath(Order::class, 'marketOrderBySignature.market_order_number');

        expect(contributionsOf($byCode, [$order->id]))->toBe([$order->id.'|77'])
            ->and(contributionsOf($bySignature, [$order->id]))->toBe([$order->id.'|MO-1'])
            ->and($byCode->reverseHops()[0]->rootsSql('table:addresses#code', ['ADDR-1'], 5)['sql'])->not->toContain('::text = ANY')
            ->and($bySignature->reverseHops()[0]->nodeType)->toBe('order#signature')
            ->and(rootsOf($byCode, 'table:addresses#code', ['ADDR-1']))->toBe([$order->id])
            ->and(rootsOf($bySignature, 'order#signature', ['SIG-9']))->toBe([$order->id]);
    });

    it('gives a node reached through another column a different node type', function (): void {
        $byId = compilePath(Order::class, 'billingAddress.phone')->reverseHops()[0]->nodeType;
        $byCode = compilePath(Order::class, 'billingAddressByCode.phone')->reverseHops()[0]->nodeType;

        expect($byId)->toBe('table:addresses')
            ->and($byCode)->toBe('table:addresses#code');
    });

    it('uses an index on the key column of a seeded table', function (): void {
        foreach (range(1, 300) as $i) {
            UlidRecord::create(['code' => 'C'.$i]);
        }

        $ids = UlidRecord::query()->limit(2)->pluck('id')->all();
        DB::statement('ANALYZE ulid_records');
        $query = compilePath(UlidRecord::class, 'code')->contributionSql($ids);
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = implode("\n", array_map(static fn (object $row): string => (string) ((array) $row)['QUERY PLAN'], DB::select('EXPLAIN '.$query['sql'], $query['bindings'])));

        expect($plan)->toContain('ulid_records_pkey');
    });
});

describe('reverse hop fan-out', function (): void {
    it('limits the roots per node, not in total', function (): void {
        $crowded = Address::create(['phone' => 'A']);
        $lonely = Address::create(['phone' => 'B']);
        Order::create(['signature' => '1', 'billing_address_id' => $crowded->id]);
        Order::create(['signature' => '2', 'billing_address_id' => $crowded->id]);
        Order::create(['signature' => '3', 'billing_address_id' => $crowded->id]);
        Order::create(['signature' => '4', 'billing_address_id' => $lonely->id]);

        $rows = hopRowsOf(compilePath(Order::class, 'billingAddress.phone'), 'table:addresses', [$crowded->id, $lonely->id], 2);
        $perNode = array_count_values(array_column($rows, 0));

        expect($perNode)->toBe([$crowded->id => 2, $lonely->id => 1]);
    });

    it('tells hops of different relations to the same table apart', function (): void {
        $billing = compilePath(Order::class, 'billingAddress.phone')->reverseHops()[0];
        $shipping = compilePath(Order::class, 'shippingAddress.phone')->reverseHops()[0];

        expect($billing->identity())->not->toBe($shipping->identity());
    });
});

describe('watched table metadata', function (): void {
    it('carries the morph discriminator and keeps it in the merge identity', function (): void {
        $notes = compilePath(Order::class, 'notes.body')->tables()[0];
        $plain = new WatchedTable('notes', $notes->columns, 'child', 'order', 'noteable_id', null, null, 'pgsql', '"notes"');

        expect([$notes->morphColumn, $notes->morphValue, $notes->fkColumn])->toBe(['noteable_type', 'order', 'noteable_id'])
            ->and($notes->columns)->toBe(['body', 'noteable_id', 'noteable_type'])
            ->and($notes->identity())->not->toBe($plain->identity());
    });

    it('carries the connection and the quoted, prefixed table', function (): void {
        $table = compilePath(Order::class, 'marketOrder.market_order_number')->tables()[0];

        expect($table->connection)->toBe('pgsql')
            ->and($table->quotedTable)->toBe('"market_orders"');
    });
});

describe('guards', function (): void {
    it('refuses a related model on another connection', function (): void {
        config(['database.connections.elsewhere' => config('database.connections.pgsql')]);

        compilePath(Order::class, 'elsewhereAddress.phone');
    })->throws(UnsupportedRelation::class, 'elsewhere');

    it('refuses a global scope whose bindings depend on the clock', function (): void {
        compilePath(Order::class, 'recentBillingAddress.phone', withScopes: [RecentAddressScope::class]);
    })->throws(InvalidSearchDefinition::class, 'time');

    it('ignores such a scope when the definition does not list it', function (): void {
        expect(compilePath(Order::class, 'recentBillingAddress.phone'))->toBeInstanceOf(CompiledPath::class);
    });
});

describe('reverse hop duplicates', function (): void {
    it('drops DISTINCT where the chain cannot repeat a root and keeps it where it can', function (): void {
        $plain = compilePath(Order::class, 'billingAddress.phone')->reverseHops()[0]->rootsSql('table:addresses', ['x'], 5)['sql'];
        $pivot = compilePath(Order::class, 'tags.name')->reverseHops()[0]->rootsSql('table:tags', ['1'], 5)['sql'];
        $through = compilePath(Order::class, 'parcelCarrierUnits.tracking_number')->reverseHops()[0]->rootsSql('table:parcels', ['x'], 5)['sql'];

        expect($plain)->not->toContain('DISTINCT')
            ->and($pivot)->toContain('DISTINCT')
            ->and($through)->toContain('DISTINCT');
    });
});
