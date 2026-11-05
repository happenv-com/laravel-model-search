<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Schema\TriggerSql;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(TriggerSql::class);

/**
 * @return list<string> "type|id" of every logged change, in log order.
 */
function loggedChanges(): array
{
    return DB::table(TableNames::forConnection()->changes())->orderBy('id')->get()
        ->map(static fn (object $row): string => $row->node_type.'|'.$row->node_id)
        ->all();
}

function clearChanges(): void
{
    DB::table(TableNames::forConnection()->changes())->delete();
}

function insertOrder(array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'SIG-'.substr($id, 0, 6), ...$attributes]);

    return $id;
}

afterEach(function (): void {
    ConfigurableOrderSearch::reset();
});

describe('triggers installed by the migration listener', function (): void {
    it('exist for every watched table under the documented names', function (): void {
        $names = DB::table('pg_trigger')->where('tgisinternal', false)->pluck('tgname')->all();

        foreach (['orders', 'addresses', 'market_orders', 'parcels', 'carrier_units', 'products', 'invoices', 'customers'] as $table) {
            expect($names)->toContain('model_search_'.$table.'_ins', 'model_search_'.$table.'_del', 'model_search_'.$table.'_trunc');
        }

        expect($names)->toContain('model_search_orders_upd', 'model_search_market_orders_upd');
    });

    it('raw update is captured', function (): void {
        $id = insertOrder();
        clearChanges();

        DB::table('orders')->where('id', $id)->update(['customer_email' => 'buyer@example.com']);

        expect(loggedChanges())->toBe(['order|'.$id]);
    });

    it('insert logs NEW parent, update logs OLD and NEW on fk change, delete logs OLD parent', function (): void {
        $first = insertOrder();
        $second = insertOrder();
        clearChanges();

        $child = (string) Str::uuid();
        DB::table('market_orders')->insert(['id' => $child, 'order_id' => $first, 'market_order_number' => 'MO-1']);
        expect(loggedChanges())->toBe(['order|'.$first]);

        clearChanges();
        DB::table('market_orders')->where('id', $child)->update(['order_id' => $second]);
        expect(loggedChanges())->toBe(['order|'.$first, 'order|'.$second]);

        clearChanges();
        DB::table('market_orders')->where('id', $child)->delete();
        expect(loggedChanges())->toBe(['order|'.$second]);
    });

    it('logs the parent once when a watched column changes and the key does not', function (): void {
        $order = insertOrder();
        $child = (string) Str::uuid();
        DB::table('market_orders')->insert(['id' => $child, 'order_id' => $order, 'market_order_number' => 'MO-1']);
        clearChanges();

        DB::table('market_orders')->where('id', $child)->update(['market_order_number' => 'MO-2']);

        expect(loggedChanges())->toBe(['order|'.$order]);
    });

    it('does not log a child row whose key is null', function (): void {
        insertOrder();
        clearChanges();

        DB::table('carrier_units')->insert(['parcel_id' => null, 'tracking_number' => 'T-1']);
        DB::table('carrier_units')->update(['tracking_number' => 'T-2']);
        DB::table('carrier_units')->delete();

        expect(loggedChanges())->toBe([]);
    });

    it('no-op update logs nothing', function (): void {
        $order = insertOrder(['customer_email' => 'same@example.com']);
        $child = (string) Str::uuid();
        DB::table('market_orders')->insert(['id' => $child, 'order_id' => $order, 'market_order_number' => 'MO-1', 'order_signature' => 'a']);
        clearChanges();

        DB::table('orders')->where('id', $order)->update(['customer_email' => 'same@example.com']);
        DB::table('market_orders')->where('id', $child)->update(['market_order_number' => 'MO-1']);
        // a column nothing reads
        DB::table('market_orders')->where('id', $child)->update(['order_signature' => 'b']);
        DB::table('orders')->where('id', $order)->update(['tenant_id' => 7]);

        expect(loggedChanges())->toBe([]);
    });

    it('stage column change is captured', function (): void {
        $id = insertOrder(['state' => 'unshipped']);
        clearChanges();

        DB::table('orders')->where('id', $id)->update(['state' => 'shipped']);

        expect(loggedChanges())->toBe(['order|'.$id]);
    });

    it('self shape logs the address node', function (): void {
        $address = (string) Str::uuid();

        DB::table('addresses')->insert(['id' => $address, 'name' => 'Ann']);
        expect(loggedChanges())->toBe(['table:addresses|'.$address]);

        clearChanges();
        DB::table('addresses')->where('id', $address)->update(['name' => 'Anna']);
        expect(loggedChanges())->toBe(['table:addresses|'.$address]);

        clearChanges();
        DB::table('addresses')->where('id', $address)->update(['country_id' => 5]);
        expect(loggedChanges())->toBe([]);

        DB::table('addresses')->where('id', $address)->delete();
        expect(loggedChanges())->toBe(['table:addresses|'.$address]);
    });

    it('logs a root row on insert, soft delete and delete', function (): void {
        $parcel = (string) Str::uuid();

        DB::table('parcels')->insert(['id' => $parcel, 'reference' => 'P-1']);
        expect(loggedChanges())->toBe(['parcel|'.$parcel]);

        clearChanges();
        DB::table('parcels')->where('id', $parcel)->update(['deleted_at' => now()]);
        expect(loggedChanges())->toBe(['parcel|'.$parcel]);

        clearChanges();
        DB::table('parcels')->where('id', $parcel)->delete();
        expect(loggedChanges())->toBe(['parcel|'.$parcel]);
    });

    it('truncate marks every root type that reads the table', function (): void {
        foreach (['order', 'parcel', 'invoice'] as $type) {
            DB::table(TableNames::forConnection()->state())->insert([
                'subject_type' => $type, 'definition_fingerprint' => 'f', 'definition_version' => 1, 'installed_at' => now(),
            ]);
        }

        DB::statement('TRUNCATE addresses');

        $marked = DB::table(TableNames::forConnection()->state())->whereNotNull('rebuild_started_at')->pluck('subject_type')->all();
        expect($marked)->toBe(['order']);
        expect(DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->value('rebuild_cursor'))->toBe('');

        DB::table(TableNames::forConnection()->state())->update(['rebuild_cursor' => null, 'rebuild_started_at' => null]);
        DB::statement('TRUNCATE carrier_units');
        expect(DB::table(TableNames::forConnection()->state())->whereNotNull('rebuild_started_at')->pluck('subject_type')->all())->toBe(['parcel']);

        DB::table(TableNames::forConnection()->state())->update(['rebuild_cursor' => null, 'rebuild_started_at' => null]);
        DB::statement('TRUNCATE invoices');
        expect(DB::table(TableNames::forConnection()->state())->whereNotNull('rebuild_started_at')->pluck('subject_type')->all())->toBe(['invoice']);
    });

    it('guards a morph child on the discriminator for OLD and NEW', function (): void {
        ConfigurableOrderSearch::$fields = [
            SearchField::key('signature'),
            SearchField::key('notes.body')->kind('note'),
            SearchField::name('customer_name'),
        ];
        $registry = new ModelSearchRegistry;
        $registry->register(ConfigurableOrderSearch::class);
        (new SchemaSync($registry))->apply();

        $order = insertOrder();
        $other = insertOrder();
        clearChanges();

        $note = DB::table('notes')->insertGetId(['noteable_type' => 'order', 'noteable_id' => $order, 'body' => 'call back']);
        expect(loggedChanges())->toBe(['order|'.$order]);

        clearChanges();
        DB::table('notes')->insert(['noteable_type' => 'parcel', 'noteable_id' => $order, 'body' => 'foreign']);
        expect(loggedChanges())->toBe([]);

        // the discriminator moves the row out of the path: the OLD parent is logged, the NEW one is not
        DB::table('notes')->where('id', $note)->update(['noteable_type' => 'parcel']);
        expect(loggedChanges())->toBe(['order|'.$order]);

        // and back into it under another parent
        clearChanges();
        DB::table('notes')->where('id', $note)->update(['noteable_type' => 'order', 'noteable_id' => $other]);
        expect(loggedChanges())->toBe(['order|'.$other]);

        clearChanges();
        DB::table('notes')->where('id', $note)->delete();
        expect(loggedChanges())->toBe(['order|'.$other]);
    });

    it('logs through the model layer as well, builders and saveQuietly included', function (): void {
        $order = Order::query()->create(['signature' => 'SIG-M']);
        clearChanges();

        Order::query()->whereKey($order->getKey())->update(['customer_name' => 'Ann']);
        $order->customer_email = 'x@example.com';
        $order->saveQuietly();

        expect(loggedChanges())->toBe(['order|'.$order->getKey(), 'order|'.$order->getKey()]);
    });
});

it('compares columns of a type from another schema, as text', function (): void {
    // hstore's `=` lives in its own schema, outside the fixed search_path of the trigger function.
    DB::statement('CREATE SCHEMA ms_ext');
    DB::statement('CREATE EXTENSION hstore SCHEMA ms_ext');
    DB::statement('ALTER TABLE orders ADD COLUMN attrs ms_ext.hstore');

    ConfigurableOrderSearch::$watching = ['state', 'attrs'];
    ConfigurableOrderSearch::$fields = [SearchField::key('signature'), SearchField::name('customer_name')];
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    (new SchemaSync($registry))->apply();

    $order = insertOrder();
    clearChanges();

    DB::statement("UPDATE orders SET attrs = 'a=>1'::ms_ext.hstore WHERE id = ?", [$order]);
    expect(loggedChanges())->toBe(['order|'.$order]);

    clearChanges();
    DB::statement("UPDATE orders SET attrs = 'a=>1'::ms_ext.hstore WHERE id = ?", [$order]);
    expect(loggedChanges())->toBe([]);
});
