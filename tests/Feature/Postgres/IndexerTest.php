<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Events\SearchIndexChanged;
use Happenv\ModelSearch\Events\SearchIndexLagging;
use Happenv\ModelSearch\Exceptions\DrainInsideTransaction;
use Happenv\ModelSearch\Exceptions\InstallRefused;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\InstallOutcome;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Happenv\ModelSearch\Tests\Fixtures\Normalizers\HookedNormalizer;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderPathInvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderPathParcelSearch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

covers(SearchIndexer::class);

beforeEach(function (): void {
    // The migration listener installed the fixture types; these tests start from "nothing installed".
    DB::table(TableNames::forConnection()->state())->delete();
});

afterEach(function (): void {
    ConfigurableOrderSearch::reset();
    HookedNormalizer::$hook = null;
});

function ixIndexer(?ModelSearchRegistry $registry = null): SearchIndexer
{
    return new SearchIndexer($registry ?? app(ModelSearchRegistry::class), new IndexState);
}

function ixTable(string $name): string
{
    return TableNames::forConnection()->{$name}();
}

function ixOrder(array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'SIG-'.substr($id, 0, 6), 'state' => 'unshipped', ...$attributes]);

    return $id;
}

function ixAddress(array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('addresses')->insert(['id' => $id, ...$attributes]);

    return $id;
}

/**
 * @return array{entries: array<string, string>, keys: list<string>}
 */
function ixSnapshot(string $type): array
{
    $entries = [];

    foreach (DB::table(ixTable('entries'))->where('subject_type', $type)->orderBy('subject_id')->get() as $row) {
        $entries[$row->subject_id] = implode('|', [$row->stage, $row->names, $row->sort_at ?? '-', $row->partition ?? '-']);
    }

    $keys = DB::table(ixTable('keys'))->where('subject_type', $type)->orderBy('subject_id')->orderBy('kind')->orderBy('key')->get()
        ->map(static fn (object $row): string => implode('|', [$row->subject_id, $row->kind, $row->key, $row->value, $row->stage, $row->partition ?? '-']))
        ->all();

    return ['entries' => $entries, 'keys' => $keys];
}

/**
 * @return list<string> "kind|key" of the key rows of one root.
 */
function ixKeys(string $type, string $id): array
{
    return DB::table(ixTable('keys'))->where('subject_type', $type)->where('subject_id', $id)->orderBy('kind')->orderBy('key')->get()
        ->map(static fn (object $row): string => $row->kind.'|'.$row->key)
        ->all();
}

/**
 * @return array<string, string> ctid per "kind|key", to tell a rewritten row from an untouched one.
 */
function ixKeyVersions(string $id): array
{
    $versions = [];

    foreach (DB::select(sprintf('SELECT "kind", "key", ctid::text AS "version" FROM %s WHERE "subject_id" = ?', ixTable('keys')), [$id]) as $row) {
        $versions[$row->kind.'|'.$row->key] = $row->version;
    }

    return $versions;
}

function ixLog(): array
{
    return DB::table(ixTable('changes'))->orderBy('id')->get()->map(static fn (object $row): string => $row->node_type.'|'.$row->node_id)->all();
}

function ixClearIndex(): void
{
    foreach (['entries', 'keys', 'changes', 'failures'] as $table) {
        DB::table(ixTable($table))->delete();
    }
}

function ixRegistry(): ModelSearchRegistry
{
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    $registry->all();

    return $registry;
}

function ixDefinition(ModelSearchRegistry $registry): CompiledDefinition
{
    return $registry->for(Order::class);
}

describe('writing the projection', function (): void {
    it('writes a diff', function (): void {
        $id = ixOrder(['customer_name' => 'Ann Kowalska']);
        $stats = ixIndexer()->drainNow();

        expect($stats->entriesWritten)->toBe(1)
            ->and($stats->keysWritten)->toBeGreaterThan(0)
            ->and($stats->changedTypes)->toBe(['order']);

        $versions = ixKeyVersions($id);
        $entry = DB::selectOne(sprintf('SELECT ctid::text AS v, indexed_at FROM %s WHERE subject_id = ?', ixTable('entries')), [$id]);

        // The same root again, with nothing changed: no write at all.
        DB::table(ixTable('changes'))->insert(['node_type' => 'order', 'node_id' => $id]);
        $again = ixIndexer()->drainNow();

        expect($again->roots)->toBe(1)
            ->and($again->entriesWritten)->toBe(0)
            ->and($again->keysWritten)->toBe(0)
            ->and($again->keysDeleted)->toBe(0)
            ->and($again->keyStageRewrites)->toBe(0)
            ->and($again->changedTypes)->toBe([])
            ->and(ixKeyVersions($id))->toBe($versions)
            ->and(DB::selectOne(sprintf('SELECT ctid::text AS v, indexed_at FROM %s WHERE subject_id = ?', ixTable('entries')), [$id]))->toEqual($entry);
    });

    it('removes the keys a change took away', function (): void {
        $id = ixOrder(['customer_email' => 'buyer@example.com']);
        ixIndexer()->drainNow();
        expect(ixKeys('order', $id))->toContain('customer_email|buyer@examplecom');

        DB::table('orders')->where('id', $id)->update(['customer_email' => null]);
        $stats = ixIndexer()->drainNow();

        expect(ixKeys('order', $id))->not->toContain('customer_email|buyer@examplecom')
            ->and($stats->keysDeleted)->toBe(1);
    });

    it('soft-deleted product leaves the index', function (): void {
        $id = DB::table('products')->insertGetId(['sku' => 'SKU-1', 'name' => json_encode(['en' => 'Coffee Mug', 'pl' => 'Kubek'])]);
        ixIndexer()->drainNow();

        expect(ixKeys('product', (string) $id))->toContain('sku|sku1');

        DB::table('products')->where('id', $id)->update(['deleted_at' => now()]);
        $stats = ixIndexer()->drainNow();

        expect(ixKeys('product', (string) $id))->toBe([])
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'product')->count())->toBe(0)
            ->and($stats->entriesDeleted)->toBe(1);

        DB::table('products')->where('id', $id)->update(['deleted_at' => null]);
        ixIndexer()->drainNow();

        expect(ixKeys('product', (string) $id))->toContain('sku|sku1');
    });

    it('indexes every language of a translatable name', function (): void {
        $id = DB::table('products')->insertGetId(['sku' => 'SKU-1', 'name' => json_encode(['en' => 'Coffee Mug', 'pl' => 'Kubek'])]);
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_id', (string) $id)->value('names'))->toBe('coffee mug kubek')
            ->and(ixKeys('product', (string) $id))->toBe(['name_word|coffee', 'name_word|kubek', 'name_word|mug', 'sku|sku1']);
    });

    it('keeps the sort moment and derives the partition from the root', function (): void {
        ConfigurableOrderSearch::$sortAt = 'purchased_at';
        ConfigurableOrderSearch::$partition = 'tenant_id';
        $registry = ixRegistry();

        $id = ixOrder(['tenant_id' => 7, 'purchased_at' => '2026-01-02 03:04:05+00']);
        ixIndexer($registry)->drainNow();

        $entry = DB::table(ixTable('entries'))->where('subject_id', $id)->first();

        expect($entry->partition)->toBe('7')
            ->and($entry->sort_at)->toContain('2026-01-02')
            ->and(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('partition')->unique()->all())->toBe(['7']);

        DB::table('orders')->where('id', $id)->update(['tenant_id' => 9]);
        DB::table(ixTable('changes'))->insert(['node_type' => 'order', 'node_id' => $id]);
        ixIndexer($registry)->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_id', $id)->value('partition'))->toBe('9')
            ->and(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('partition')->unique()->all())->toBe(['9']);
    });

    it('never writes an empty key or word', function (): void {
        $id = ixOrder(['signature' => '---', 'customer_name' => '😀']);
        ixIndexer()->drainNow();

        expect(ixKeys('order', $id))->toBe([])
            ->and(DB::table(ixTable('keys'))->where('key', '')->count())->toBe(0)
            ->and(DB::table(ixTable('entries'))->where('subject_id', $id)->value('names'))->toBe('');
    });
});

describe('name words', function (): void {
    it('name words become name_word keys with the source name as value', function (): void {
        $shipping = ixAddress(['name' => 'Zoë Kowalska']);
        $id = ixOrder(['customer_name' => "Ann-Marie O'Neil J", 'shipping_address_id' => $shipping]);
        ixIndexer()->drainNow();

        $words = DB::table(ixTable('keys'))->where('subject_id', $id)->where('kind', 'name_word')->orderBy('key')->pluck('value', 'key')->all();

        expect($words)->toBe([
            'ann' => "Ann-Marie O'Neil J",
            'kowalska' => 'Zoë Kowalska',
            'marie' => "Ann-Marie O'Neil J",
            'oneil' => "Ann-Marie O'Neil J",
            'zoe' => 'Zoë Kowalska',
        ])->and(DB::table(ixTable('entries'))->where('subject_id', $id)->value('names'))->toBe('ann-marie oneil j zoe kowalska');
    });

    it('name words are capped per record', function (): void {
        config(['model-search.name_words.max_per_record' => 3]);
        $shipping = ixAddress(['name' => 'Zoë Kowalska']);
        $id = ixOrder(['customer_name' => "Ann-Marie O'Neil J", 'shipping_address_id' => $shipping]);
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('keys'))->where('subject_id', $id)->where('kind', 'name_word')->orderBy('key')->pluck('key')->all())
            ->toBe(['ann', 'marie', 'oneil']);
    });

    it('follows a changed name', function (): void {
        $id = ixOrder(['customer_name' => 'Ann Kowalska']);
        ixIndexer()->drainNow();

        DB::table('orders')->where('id', $id)->update(['customer_name' => 'Ann Nowak']);
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('keys'))->where('subject_id', $id)->where('kind', 'name_word')->orderBy('key')->pluck('key')->all())->toBe(['ann', 'nowak']);
    });
});

describe('the stage on key rows', function (): void {
    it('every key row carries the entry stage', function (): void {
        $shipping = ixAddress(['name' => 'Zoë Kowalska', 'phone' => '+48 600 100 200']);
        $open = ixOrder(['customer_name' => 'Ann Kowalska', 'state' => 'unshipped', 'shipping_address_id' => $shipping, 'billing_address_id' => $shipping]);
        $shipped = ixOrder(['customer_name' => 'Bob Nowak', 'state' => 'shipped']);
        ixIndexer()->drainNow();

        $stages = fn (string $id): array => DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('stage')->unique()->map(intval(...))->all();

        expect($stages($open))->toBe([0])
            ->and($stages($shipped))->toBe([CompiledDefinition::ALL_STAGE])
            ->and(DB::table(ixTable('keys'))->where('subject_id', $open)->where('kind', 'name_word')->count())->toBeGreaterThan(0)
            ->and((int) DB::table(ixTable('entries'))->where('subject_id', $open)->value('stage'))->toBe(0);
    });

    it('stage change rewrites key stage and converges', function (): void {
        $id = ixOrder(['customer_name' => 'Ann Kowalska', 'state' => 'unshipped']);
        ixIndexer()->drainNow();
        $keyCount = count(ixKeys('order', $id));

        DB::table('orders')->where('id', $id)->update(['state' => 'shipped']);
        $sent = ixIndexer()->drainNow();

        expect($sent->keyStageRewrites)->toBe($keyCount)
            ->and(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('stage')->unique()->map(intval(...))->all())->toBe([CompiledDefinition::ALL_STAGE])
            ->and((int) DB::table(ixTable('entries'))->where('subject_id', $id)->value('stage'))->toBe(CompiledDefinition::ALL_STAGE);

        DB::table('orders')->where('id', $id)->update(['state' => 'unshipped']);
        $back = ixIndexer()->drainNow();

        expect($back->keyStageRewrites)->toBe($keyCount)
            ->and(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('stage')->unique()->map(intval(...))->all())->toBe([0]);

        // Two changes in the opposite order, drained once, with the log replayed and doubled: the
        // end state is the function of the source row, so it is the same.
        DB::table('orders')->where('id', $id)->update(['state' => 'shipped']);
        DB::table('orders')->where('id', $id)->update(['state' => 'unshipped']);
        DB::table(ixTable('changes'))->insert([['node_type' => 'order', 'node_id' => $id], ['node_type' => 'order', 'node_id' => $id]]);
        $settled = ixIndexer()->drainNow();

        expect($settled->keyStageRewrites)->toBe(0)
            ->and(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('stage')->unique()->map(intval(...))->all())->toBe([0]);

        DB::table('orders')->where('id', $id)->update(['state' => 'unshipped']);
        DB::table('orders')->where('id', $id)->update(['state' => 'shipped']);
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('keys'))->where('subject_id', $id)->pluck('stage')->unique()->map(intval(...))->all())->toBe([CompiledDefinition::ALL_STAGE]);
    });

    it('a change that does not touch stage rewrites no key stage', function (): void {
        $id = ixOrder(['customer_name' => 'Ann Kowalska', 'customer_email' => 'old@example.com']);
        ixIndexer()->drainNow();
        $before = ixKeyVersions($id);

        DB::table('orders')->where('id', $id)->update(['customer_email' => 'new@example.com']);
        $stats = ixIndexer()->drainNow();
        $after = ixKeyVersions($id);

        expect($stats->keyStageRewrites)->toBe(0)
            ->and($after['signature|'.strtolower(str_replace('-', '', DB::table('orders')->where('id', $id)->value('signature')))])->toBe($before['signature|'.strtolower(str_replace('-', '', DB::table('orders')->where('id', $id)->value('signature')))])
            ->and(array_keys($after))->toContain('customer_email|new@examplecom')
            ->and(array_keys($after))->not->toContain('customer_email|old@examplecom');
    });
});

describe('converging', function (): void {
    it('converges regardless of order and repetition', function (): void {
        $shared = ixAddress(['name' => 'Ann Kowalska', 'phone' => '111']);
        $other = ixAddress(['name' => 'Zoë Nowak', 'phone' => '222']);
        $first = ixOrder(['customer_name' => 'Bob Stone', 'billing_address_id' => $shared, 'shipping_address_id' => $other]);
        $second = ixOrder(['customer_name' => 'Cleo Stone', 'billing_address_id' => $shared]);
        $third = ixOrder(['customer_name' => 'Dan Stone', 'state' => 'shipped']);

        DB::table('addresses')->where('id', $shared)->update(['phone' => '999']);
        DB::table('orders')->where('id', $second)->update(['customer_name' => 'Cleo Rivers', 'state' => 'shipped']);
        DB::table('orders')->where('id', $third)->delete();

        ixIndexer()->drainNow();
        $inOrder = ixSnapshot('order');

        $nodes = [];

        foreach ([$first, $second, $third] as $id) {
            $nodes[] = ['order', $id];
        }

        foreach ([$shared, $other] as $id) {
            $nodes[] = ['table:addresses', $id];
        }

        ixClearIndex();
        $replay = [...$nodes, ...$nodes, ...$nodes];
        shuffle($replay);
        DB::table(ixTable('changes'))->insert(array_map(static fn (array $node): array => ['node_type' => $node[0], 'node_id' => $node[1]], $replay));
        ixIndexer()->drainNow();
        $shuffled = ixSnapshot('order');

        ixClearIndex();
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        ixIndexer()->drainNow();
        $rebuilt = ixSnapshot('order');

        expect($inOrder['entries'])->not->toBe([])
            ->and($shuffled)->toBe($inOrder)
            ->and($rebuilt)->toBe($inOrder)
            ->and(array_keys($inOrder['entries']))->not->toContain($third);
    });

    it('deletes only the change rows it read', function (): void {
        config(['model-search.drain.rows' => 2]);

        foreach (range(1, 5) as $ignored) {
            ixOrder();
        }

        $stats = ixIndexer()->drain(0);

        expect($stats->logRowsRead)->toBe(2)
            ->and(DB::table(ixTable('changes'))->count())->toBe(3)
            ->and(DB::table(ixTable('entries'))->count())->toBe(2);
    });

    it('caps a batch at the unique nodes and keeps the rest of the log', function (): void {
        config(['model-search.drain.nodes' => 2]);
        $first = ixOrder();
        ixOrder();
        DB::table(ixTable('changes'))->insert(['node_type' => 'order', 'node_id' => $first]);
        ixOrder();

        $stats = ixIndexer()->drain(0);

        expect($stats->nodes)->toBe(2)
            ->and($stats->logRowsRead)->toBe(3)
            ->and(DB::table(ixTable('changes'))->count())->toBe(1);
    });

    it('shared address spills roots back to the log', function (): void {
        $address = ixAddress(['phone' => '111']);
        $orders = array_map(static fn (): string => ixOrder(['billing_address_id' => $address]), range(1, 5));
        ixIndexer()->drainNow();

        config(['model-search.drain.fan_out_limit' => 2]);
        DB::table('addresses')->where('id', $address)->update(['phone' => '222']);

        $stats = ixIndexer()->drain(0);

        expect($stats->spilledRoots)->toBe(5)
            ->and(ixLog())->toHaveCount(5)
            ->and(array_unique(DB::table(ixTable('changes'))->pluck('node_type')->all()))->toBe(['order'])
            ->and(ixKeys('order', $orders[0]))->toContain('phone|111')
            ->and(ixKeys('order', $orders[0]))->not->toContain('phone|222');

        ixIndexer()->drainNow();

        foreach ($orders as $id) {
            expect(ixKeys('order', $id))->toContain('phone|222')->not->toContain('phone|111');
        }

        expect(ixLog())->toBe([]);
    });

    it('judges the fan-out on the union of all routes to a node', function (): void {
        $address = ixAddress(['name' => 'Ann Kowalska', 'phone' => '111']);
        ixOrder(['billing_address_id' => $address]);
        ixOrder(['billing_address_id' => $address]);
        ixOrder(['shipping_address_id' => $address]);
        ixOrder(['shipping_address_id' => $address]);
        ixIndexer()->drainNow();

        config(['model-search.drain.fan_out_limit' => 3]);
        DB::table('addresses')->where('id', $address)->update(['phone' => '222', 'name' => 'Ann Nowak']);

        // Two orders per route, four on the union: over the limit of three although no route is.
        expect(ixIndexer()->drain(0)->spilledRoots)->toBe(4);
    });
});

describe('poison records and unknown nodes', function (): void {
    it('poison record goes to failures and the drain continues', function (): void {
        ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(new HookedNormalizer)];
        $registry = ixRegistry();
        HookedNormalizer::$hook = static function (string $value): void {
            if (str_contains($value, 'BOOM')) {
                throw new RuntimeException('secret value '.$value);
            }
        };

        $good = ixOrder(['signature' => 'A-1']);
        $bad = ixOrder(['signature' => 'BOOM-2']);
        $later = ixOrder(['signature' => 'C-3']);

        $stats = ixIndexer($registry)->drainNow();

        expect($stats->poisoned)->toBe(1)
            ->and(ixKeys('order', $good))->toBe(['signature|a1'])
            ->and(ixKeys('order', $later))->toBe(['signature|c3'])
            ->and(ixKeys('order', $bad))->toBe([])
            ->and(ixLog())->toBe([]);

        $failure = DB::table(ixTable('failures'))->first();

        expect($failure->subject_type)->toBe('order')
            ->and($failure->subject_id)->toBe($bad)
            ->and($failure->attempts)->toBe(1)
            ->and($failure->last_error_class)->toBe(RuntimeException::class)
            ->and(json_encode($failure))->not->toContain('secret');

        // A later change of the record is a new attempt, and success clears the failure.
        HookedNormalizer::$hook = null;
        DB::table('orders')->where('id', $bad)->update(['signature' => 'FIXED-2']);
        ixIndexer($registry)->drainNow();

        expect(ixKeys('order', $bad))->toBe(['signature|fixed2'])
            ->and(DB::table(ixTable('failures'))->count())->toBe(0);
    });

    it('counts a record that keeps failing', function (): void {
        ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(new HookedNormalizer)];
        $registry = ixRegistry();
        HookedNormalizer::$hook = static function (string $value): void {
            if (str_contains($value, 'BOOM')) {
                throw new RuntimeException('no');
            }
        };

        $bad = ixOrder(['signature' => 'BOOM-1']);
        ixIndexer($registry)->drainNow();
        DB::table('orders')->where('id', $bad)->update(['customer_name' => 'touched']);
        ixIndexer($registry)->drainNow();

        expect((int) DB::table(ixTable('failures'))->where('subject_id', $bad)->value('attempts'))->toBe(2);
    });

    it('retry-failures re-queues', function (): void {
        ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(new HookedNormalizer)];
        $registry = ixRegistry();
        HookedNormalizer::$hook = static function (string $value): void {
            if (str_contains($value, 'BOOM')) {
                throw new RuntimeException('no');
            }
        };

        $bad = ixOrder(['signature' => 'BOOM-1']);
        $other = ixOrder(['signature' => 'BOOM-2']);
        ixIndexer($registry)->drainNow();
        DB::table(ixTable('failures'))->update(['attempts' => 5]);
        HookedNormalizer::$hook = null;

        expect(Artisan::call('model-search:retry-failures', ['--subject' => ['order'], '--id' => [$bad]]))->toBe(0)
            ->and(ixLog())->toBe(['order|'.$bad])
            ->and((int) DB::table(ixTable('failures'))->where('subject_id', $bad)->value('attempts'))->toBe(0)
            ->and((int) DB::table(ixTable('failures'))->where('subject_id', $other)->value('attempts'))->toBe(5);

        // The container's own registry does not poison: the record goes through and the failure is cleared.
        app(SearchIndexer::class)->drainNow();

        expect(ixKeys('order', $bad))->not->toBe([])
            ->and(DB::table(ixTable('failures'))->where('subject_id', $bad)->count())->toBe(0);
    });

    it('retries a failure by itself after a day, a limited number of times', function (): void {
        $id = ixOrder();
        ixIndexer()->drainNow();
        $table = ixTable('failures');
        DB::table($table)->insert([
            ['subject_type' => 'order', 'subject_id' => $id, 'attempts' => 1, 'last_error_class' => 'X', 'last_failed_at' => now()->subHours(25)],
            ['subject_type' => 'order', 'subject_id' => 'young', 'attempts' => 1, 'last_error_class' => 'X', 'last_failed_at' => now()->subHours(2)],
            ['subject_type' => 'order', 'subject_id' => 'tired', 'attempts' => 5, 'last_error_class' => 'X', 'last_failed_at' => now()->subHours(48)],
            ['subject_type' => 'other_release_type', 'subject_id' => 'x', 'attempts' => 1, 'last_error_class' => 'X', 'last_failed_at' => now()->subHours(48)],
        ]);

        $stats = ixIndexer()->drain(0);

        // Only the old, not exhausted failure of a known type was queued, and it succeeded.
        expect($stats->logRowsRead)->toBe(1)
            ->and(DB::table($table)->pluck('subject_id')->sort()->values()->all())->toBe(['tired', 'x', 'young']);
    });

    it('unknown node type is skipped, not thrown', function (): void {
        $old = now()->subHours(2);
        DB::table(ixTable('changes'))->insert([
            ['node_type' => 'ghost', 'node_id' => '1', 'changed_at' => $old],
            ['node_type' => 'table:no_longer_watched', 'node_id' => 'abc', 'changed_at' => $old],
        ]);

        $stats = ixIndexer()->drainNow();

        expect($stats->unknownNodes)->toBe(2)
            ->and(ixLog())->toBe([])
            ->and($stats->roots)->toBe(0);
    });
});

describe('rebuilds', function (): void {
    it('rebuild does not starve live changes', function (): void {
        foreach (range(1, 6) as $ignored) {
            ixOrder();
        }

        ixIndexer()->drainNow();
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        $live = ixOrder(['customer_name' => 'Live Change']);
        config(['model-search.drain.rows' => 2]);

        $stats = ixIndexer()->drain(0);
        $row = $state->find('order');

        expect($stats->logBatches)->toBe(1)
            ->and($stats->rebuildBatches)->toBe(1)
            ->and(ixLog())->toBe([])
            ->and(ixKeys('order', $live))->toContain('name_word|live')
            // the rebuild is under way (still sweeping the old entries, a bounded step per round) and not ready
            ->and($row['rebuild_cursor'])->not->toBeNull()
            ->and($row['sweep_cursor'])->not->toBeNull()
            ->and($row['ready_at'])->toBeNull();
    });

    it('ready_at after cursor end', function (): void {
        foreach (range(1, 5) as $ignored) {
            ixOrder();
        }

        $state = new IndexState;
        $definition = app(ModelSearchRegistry::class)->for(Order::class);

        expect($state->isReady('order'))->toBeFalse()
            ->and($state->recordInstall($definition))->toBe(InstallOutcome::Installed);

        config(['model-search.drain.rows' => 2]);
        $partial = ixIndexer()->drain(0);
        $mid = $state->find('order');

        expect($partial->rebuiltTypes)->toBe([])
            ->and($mid['ready_at'])->toBeNull()
            ->and($state->isReady('order'))->toBeFalse();

        $stats = ixIndexer()->drainNow();
        $done = $state->find('order');

        expect($stats->rebuiltTypes)->toBe(['order'])
            ->and($done['rebuild_cursor'])->toBeNull()
            ->and($done['ready_at'])->not->toBeNull()
            ->and($state->isReady('order'))->toBeTrue()
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(5);
    });

    it('rebuild removes what no longer exists, after a truncate or a lost log', function (): void {
        foreach (range(1, 3) as $ignored) {
            ixOrder();
        }

        $parcel = (string) Str::uuid();
        DB::table('parcels')->insert(['id' => $parcel, 'reference' => 'P-1']);
        ixIndexer()->drainNow();
        $state = new IndexState;
        $registry = app(ModelSearchRegistry::class);
        $state->recordInstall($registry->for(Order::class));
        $state->recordInstall($registry->for(Parcel::class));
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(3);

        DB::statement('TRUNCATE orders');
        expect($state->find('order')['rebuild_cursor'])->toBe('')
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(3);

        // A soft delete whose log row was lost: only the rebuild can notice it.
        DB::table('parcels')->where('id', $parcel)->update(['deleted_at' => now()]);
        DB::table(ixTable('changes'))->delete();
        $state->markForRebuild(['parcel']);

        $stats = ixIndexer()->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(0)
            ->and(DB::table(ixTable('keys'))->where('subject_type', 'order')->count())->toBe(0)
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'parcel')->count())->toBe(0)
            ->and(DB::table(ixTable('keys'))->where('subject_type', 'parcel')->count())->toBe(0)
            ->and($stats->entriesDeleted)->toBe(4)
            ->and($state->isReady('order'))->toBeTrue();
    });

    it('sweeps in bounded steps', function (): void {
        foreach (range(1, 5) as $ignored) {
            ixOrder();
        }

        ixIndexer()->drainNow();
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        ixIndexer()->drainNow();
        DB::statement('TRUNCATE orders');
        config(['model-search.drain.rows' => 2]);

        $first = ixIndexer()->drain(0);

        expect($first->entriesDeleted)->toBe(2)
            ->and($state->find('order')['rebuild_cursor'])->toBe('');

        ixIndexer()->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(0)
            ->and($state->find('order')['rebuild_cursor'])->toBeNull();
    });

    it('oversized log switches the type to rebuild', function (): void {
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        ixIndexer()->drainNow();
        expect($state->isReady('order'))->toBeTrue();

        config(['model-search.drain.log_rebuild_threshold' => 5]);

        foreach (range(1, 8) as $ignored) {
            ixOrder();
        }

        $stats = ixIndexer()->drain(0);

        expect($stats->switchedTypes)->toBe(['order'])
            ->and($stats->logRowsDiscarded)->toBe(8)
            ->and($stats->logRowsRead)->toBe(0)
            ->and($stats->rebuildBatches)->toBe(1)
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(8)
            ->and(ixLog())->toBe([])
            ->and($state->isReady('order'))->toBeTrue();
    });

    it('discards the log rows of the switched type only', function (): void {
        $state = new IndexState;
        $registry = app(ModelSearchRegistry::class);
        $state->recordInstall($registry->for(Order::class));
        $state->recordInstall($registry->for(Parcel::class));
        ixIndexer()->drainNow();
        config(['model-search.drain.log_rebuild_threshold' => 3]);

        foreach (range(1, 4) as $ignored) {
            ixOrder();
        }

        foreach (range(1, 2) as $number) {
            DB::table('parcels')->insert(['id' => (string) Str::uuid(), 'reference' => 'P-'.$number]);
        }

        $stats = ixIndexer()->drain(0);

        expect($stats->switchedTypes)->toBe(['order'])
            ->and($stats->logRowsDiscarded)->toBe(4)
            ->and($stats->logRowsRead)->toBe(2)
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'parcel')->count())->toBe(2);
    });
});

describe('installing', function (): void {
    it('install refuses a downgrade', function (): void {
        ConfigurableOrderSearch::$definitionVersion = 2;
        $current = ixDefinition(ixRegistry());
        ConfigurableOrderSearch::$definitionVersion = 1;
        $older = ixDefinition(ixRegistry());
        ConfigurableOrderSearch::$definitionVersion = 2;
        ConfigurableOrderSearch::$fields = [SearchField::key('signature')];
        $foreign = ixDefinition(ixRegistry());
        $state = new IndexState;

        expect($state->recordInstall($current))->toBe(InstallOutcome::Installed);

        expect(fn () => $state->recordInstall($older))->toThrow(InstallRefused::class, 'older')
            ->and(fn () => $state->recordInstall($foreign))->toThrow(InstallRefused::class, 'bump')
            ->and($state->recordInstall($current))->toBe(InstallOutcome::Unchanged)
            ->and($state->find('order')['definition_version'])->toBe(2);
    });

    it('a newer version keeps the index served and starts a rebuild', function (): void {
        $registry = ixRegistry();
        $first = ixDefinition($registry);
        $state = new IndexState;
        $state->recordInstall($first);
        ixIndexer($registry)->drainNow();
        expect($state->isReady('order'))->toBeTrue();

        ConfigurableOrderSearch::$definitionVersion = 2;
        $second = ixDefinition(ixRegistry());

        expect($state->recordInstall($second))->toBe(InstallOutcome::Updated);

        $row = $state->find('order');

        expect($row['rebuild_cursor'])->toBe('')
            ->and($row['ready_at'])->not->toBeNull()
            ->and($state->isReady('order'))->toBeTrue();
    });

    it('blue-green: an old fingerprint does not rebuild', function (): void {
        $old = ixRegistry();
        ConfigurableOrderSearch::$definitionVersion = 2;
        $new = ixRegistry();
        $state = new IndexState;

        $state->recordInstall(ixDefinition($old));
        ixOrder();
        ixIndexer($old)->drainNow();
        expect($state->find('order')['ready_at'])->not->toBeNull();

        $state->recordInstall(ixDefinition($new));
        $oldRelease = ixIndexer($old)->drainNow();

        expect($oldRelease->rebuiltTypes)->toBe([])
            ->and($oldRelease->rebuildBatches)->toBe(0)
            ->and($state->find('order')['rebuild_cursor'])->toBe('');

        $newRelease = ixIndexer($new)->drainNow();

        expect($newRelease->rebuiltTypes)->toBe(['order'])
            ->and($state->find('order')['rebuild_cursor'])->toBeNull();
    });

    it('a rebuild never installs', function (): void {
        ixOrder();

        expect(ixIndexer()->rebuild(['order']))->toBe([])
            ->and(ixIndexer()->drainNow()->rebuildBatches)->toBe(0)
            ->and(DB::table(ixTable('state'))->count())->toBe(0);

        Artisan::call('model-search:rebuild', ['--subject' => ['order']]);

        expect(DB::table(ixTable('state'))->count())->toBe(0);
    });

    it('the rebuild command marks installed types', function (): void {
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        ixIndexer()->drainNow();

        expect(Artisan::call('model-search:rebuild', ['--subject' => [Order::class]]))->toBe(0)
            ->and($state->find('order')['rebuild_cursor'])->toBe('')
            ->and(Artisan::call('model-search:rebuild', ['--subject' => ['nope']]))->toBe(1);
    });
});

describe('observability', function (): void {
    it('reports no lag on an empty log and the age of the oldest row otherwise', function (): void {
        expect(ixIndexer()->lag())->toBeNull();

        DB::statement(sprintf("INSERT INTO %s (node_type, node_id, changed_at) VALUES ('order', 'x', clock_timestamp() - interval '10 minutes')", ixTable('changes')));

        expect(ixIndexer()->lag())->toBeGreaterThanOrEqual(600);
    });

    it('dispatches SearchIndexLagging when the oldest change waited too long', function (): void {
        Event::fake([SearchIndexLagging::class]);
        DB::statement(sprintf("INSERT INTO %s (node_type, node_id, changed_at) VALUES ('order', 'x', clock_timestamp() - interval '10 minutes')", ixTable('changes')));

        ixIndexer()->drain(0);

        Event::assertDispatched(SearchIndexLagging::class, static fn (SearchIndexLagging $event): bool => $event->lagSeconds >= 600 && $event->alarmSeconds === 300);
    });

    it('stays quiet when the lag is below the alarm', function (): void {
        Event::fake([SearchIndexLagging::class]);
        ixOrder();

        ixIndexer()->drain(0);

        Event::assertNotDispatched(SearchIndexLagging::class);
    });

    it('dispatches SearchIndexChanged for the types a batch changed', function (): void {
        Event::fake([SearchIndexChanged::class]);
        ixOrder();

        ixIndexer()->drainNow();
        ixIndexer()->drainNow();

        Event::assertDispatchedTimes(SearchIndexChanged::class, 1);
        Event::assertDispatched(SearchIndexChanged::class, static fn (SearchIndexChanged $event): bool => $event->subjectType === 'order');
    });

    it('the drain command prints what it did', function (): void {
        ixOrder();

        expect(Artisan::call('model-search:drain'))->toBe(0)
            ->and(Artisan::output())->toContain('Roots recomputed')
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(1);
    });
});

describe('blue-green ownership (R14)', function (): void {
    it('a release applies the log and rebuilds only the types it owns', function (): void {
        $v1 = ixRegistry();
        ConfigurableOrderSearch::$definitionVersion = 2;
        $v2 = ixRegistry();
        $state = new IndexState;

        $state->recordInstall(ixDefinition($v1));
        ixIndexer($v1)->drainNow();
        $state->recordInstall(ixDefinition($v2));

        $id = ixOrder(['customer_name' => 'Ann Kowalska']);
        $old = ixIndexer($v1)->drainNow();

        // The previous release neither applies the rows nor rebuilds, and leaves the rows where they are.
        expect($old->logRowsRead)->toBe(0)
            ->and($old->rebuildBatches)->toBe(0)
            ->and(ixLog())->toBe(['order|'.$id])
            ->and(ixKeys('order', $id))->toBe([])
            ->and($state->find('order')['rebuild_cursor'])->toBe('');

        $new = ixIndexer($v2)->drainNow();

        expect($new->rebuiltTypes)->toBe(['order'])
            ->and(ixLog())->toBe([])
            ->and(ixKeys('order', $id))->toContain('name_word|ann');
    });

    it('never leaves a pending rebuild to a release that does not own the type, even when the index was never ready', function (): void {
        $v1 = ixRegistry();
        ConfigurableOrderSearch::$definitionVersion = 2;
        $v2 = ixRegistry();
        $state = new IndexState;
        $state->recordInstall(ixDefinition($v2));

        expect($state->find('order')['ready_at'])->toBeNull()
            ->and(ixIndexer($v1)->drainNow()->rebuildBatches)->toBe(0)
            ->and(ixIndexer($v2)->drainNow()->rebuiltTypes)->toBe(['order']);
    });

    it('applies the owned part of a node that an unowned type also reads, and keeps the row', function (): void {
        $v1 = ixRegistry();
        ConfigurableOrderSearch::$definitionVersion = 2;
        $v2 = ixRegistry();
        $state = new IndexState;
        $state->recordInstall(ixDefinition($v1));
        ixIndexer($v1)->drainNow();
        $state->recordInstall(ixDefinition($v2));

        $address = ixAddress(['phone' => '111']);
        ixOrder(['billing_address_id' => $address]);
        ixIndexer($v1)->drainNow();

        // `table:addresses` is read only by the order definition, which v1 does not own: row kept.
        expect(DB::table(ixTable('changes'))->where('node_type', 'table:addresses')->count())->toBe(1);
        ixIndexer($v2)->drainNow();
        expect(DB::table(ixTable('changes'))->count())->toBe(0);
    });

    it('deletes an unknown node only after the grace period', function (): void {
        DB::table(ixTable('changes'))->insert([
            ['node_type' => 'ghost', 'node_id' => 'young', 'changed_at' => now()->subMinutes(5)],
            ['node_type' => 'ghost', 'node_id' => 'old', 'changed_at' => now()->subHours(2)],
        ]);

        $stats = ixIndexer()->drainNow();

        expect($stats->unknownNodes)->toBe(1)
            ->and(ixLog())->toBe(['ghost|young']);

        config(['model-search.drain.unknown_grace_seconds' => 60]);
        ixIndexer()->drainNow();

        expect(ixLog())->toBe([]);
    });

    it('does not let young unknown rows stall the batch', function (): void {
        config(['model-search.drain.rows' => 2]);
        DB::table(ixTable('changes'))->insert([
            ['node_type' => 'ghost', 'node_id' => '1', 'changed_at' => now()],
            ['node_type' => 'ghost', 'node_id' => '2', 'changed_at' => now()],
        ]);
        $id = ixOrder();

        ixIndexer()->drainNow();

        expect(ixKeys('order', $id))->not->toBe([])
            ->and(ixLog())->toHaveCount(2);
    });
});

describe('a single error does not wedge the drain (I2)', function (): void {
    it('a malformed log row is counted and removed, the drain proceeds', function (): void {
        DB::table(ixTable('changes'))->insert(['node_type' => 'table:addresses', 'node_id' => 'not-a-uuid']);
        $id = ixOrder();

        $stats = ixIndexer()->drainNow();

        expect($stats->unresolvableNodes)->toBe(1)
            ->and(ixKeys('order', $id))->not->toBe([])
            ->and(ixLog())->toBe([]);
    });

    it('keeps the good nodes of a type that also holds a malformed one', function (): void {
        $address = ixAddress(['phone' => '111']);
        $order = ixOrder(['billing_address_id' => $address]);
        ixIndexer()->drainNow();
        DB::table('addresses')->where('id', $address)->update(['phone' => '222']);
        DB::table(ixTable('changes'))->insert(['node_type' => 'table:addresses', 'node_id' => 'not-a-uuid']);

        $stats = ixIndexer()->drainNow();

        expect($stats->unresolvableNodes)->toBe(1)
            ->and(ixKeys('order', $order))->toContain('phone|222');
    });

    it('a rebuild that throws for one type is reported and the log keeps draining', function (): void {
        Exceptions::fake();
        $state = new IndexState;
        $registry = app(ModelSearchRegistry::class);
        $state->recordInstall($registry->for(Order::class));
        DB::table(ixTable('state'))->where('subject_type', 'order')->update(['rebuild_cursor' => 'not-a-uuid']);
        $customer = DB::table('customers')->insertGetId(['name' => 'Ann Kowalska']);

        $stats = ixIndexer()->drainNow();

        expect(ixKeys('customer', (string) $customer))->toContain('name_word|ann')
            ->and($stats->rebuiltTypes)->toBe([])
            ->and($state->find('order')['rebuild_cursor'])->toBe('not-a-uuid');
        Exceptions::assertReported(QueryException::class);
    });
});

describe('bounded work (M3, M4, M7)', function (): void {
    it('stops expanding at the batch cap and writes the remaining nodes back unresolved', function (): void {
        config(['model-search.drain.rows' => 2, 'model-search.drain.nodes' => 2]);
        $first = ixAddress(['phone' => '1']);
        $second = ixAddress(['phone' => '2']);

        foreach (range(1, 3) as $ignored) {
            ixOrder(['billing_address_id' => $first]);
            ixOrder(['billing_address_id' => $second]);
        }

        ixIndexer()->drainNow();
        DB::table('addresses')->update(['phone' => '9']);

        $stats = ixIndexer()->drain(0);

        expect(DB::table(ixTable('changes'))->where('node_type', 'table:addresses')->count())->toBe(1)
            ->and($stats->spilledRoots)->toBeGreaterThan(0);

        ixIndexer()->drainNow();

        expect(DB::table(ixTable('keys'))->where('kind', 'phone')->pluck('key')->unique()->values()->all())->toBe(['9']);
    });

    it('isolates a poison record by bisection', function (): void {
        ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(new HookedNormalizer)];
        $registry = ixRegistry();
        $calls = 0;
        HookedNormalizer::$hook = static function (string $value) use (&$calls): void {
            $calls++;

            if (str_contains($value, 'BOOM')) {
                throw new RuntimeException('no');
            }
        };

        foreach (range(1, 15) as $number) {
            ixOrder(['signature' => 'GOOD-'.$number]);
        }

        $bad = ixOrder(['signature' => 'BOOM']);
        $stats = ixIndexer($registry)->drainNow();

        expect($stats->poisoned)->toBe(1)
            ->and(DB::table(ixTable('failures'))->value('subject_id'))->toBe($bad)
            ->and(DB::table(ixTable('entries'))->count())->toBe(15)
            // one pass over 16 roots + a bisection path of about log2(16) halves, not a pass per record
            ->and($calls)->toBeLessThan(16 * 4);
    });

    it('refuses to drain inside an open transaction', function (): void {
        ixOrder();
        DB::beginTransaction();

        try {
            expect(fn () => ixIndexer()->drain(0))->toThrow(DrainInsideTransaction::class);
        } finally {
            DB::rollBack();
        }

        expect(ixLog())->toHaveCount(1);
    });
});

describe('internals of the fix round', function (): void {
    it('resolves the key type from the catalog when the definition carries none', function (): void {
        expect(app(ModelSearchRegistry::class)->for(Order::class)->resolvedKeySqlType())->toBe('uuid');
    });

    it('requeues due failures in one statement', function (): void {
        $id = ixOrder();
        ixIndexer()->drainNow();
        DB::table(ixTable('failures'))->insert(['subject_type' => 'order', 'subject_id' => $id, 'attempts' => 1, 'last_error_class' => 'X', 'last_failed_at' => now()->subHours(30)]);
        DB::enableQueryLog();
        ixIndexer()->drain(0);
        $statements = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'last_failed_at'));
        DB::disableQueryLog();

        expect($statements)->toHaveCount(1);
    });
});

function ixSharedRegistry(): ModelSearchRegistry
{
    $registry = new ModelSearchRegistry;
    $registry->register(OrderPathParcelSearch::class);
    $registry->register(OrderPathInvoiceSearch::class);
    $registry->all();

    return $registry;
}

/** The invoice type belongs to another release: a state row with a fingerprint this release does not have. */
function ixForeignInvoice(): void
{
    DB::table(ixTable('state'))->insert([
        'subject_type' => 'invoice', 'definition_fingerprint' => 'another-release', 'definition_version' => 1,
        'installed_at' => now(), 'ready_at' => now(),
    ]);
}

function ixParcel(string $order, string $reference): string
{
    $id = (string) Str::uuid();
    DB::table('parcels')->insert(['id' => $id, 'order_id' => $order, 'reference' => $reference]);

    return $id;
}

describe('handing kept rows over by age (R15)', function (): void {
    it('keeps a row an unowned type also reads while it is young, hands it over once it is old', function (): void {
        ixForeignInvoice();
        $registry = ixSharedRegistry();
        $order = ixOrder(['signature' => 'SIG-A']);
        $parcel = ixParcel($order, 'P-1');
        ixIndexer($registry)->drainNow();
        DB::table(ixTable('changes'))->delete();

        DB::table('orders')->where('id', $order)->update(['signature' => 'SIG-B']);
        DB::table(ixTable('changes'))->delete();
        DB::table(ixTable('changes'))->insert(['node_type' => 'table:orders', 'node_id' => $order]);

        $young = ixIndexer($registry)->drainNow();

        expect(ixKeys('parcel', $parcel))->toContain('order_signature|sigb')
            ->and(ixLog())->toBe(['table:orders|'.$order])
            ->and($young->handedOffRows)->toBe(0)
            ->and(DB::table(ixTable('state'))->where('subject_type', 'invoice')->value('rebuild_cursor'))->toBeNull();

        DB::table('orders')->where('id', $order)->update(['signature' => 'SIG-C']);
        DB::table(ixTable('changes'))->where('node_type', '<>', 'table:orders')->delete();
        DB::table(ixTable('changes'))->update(['changed_at' => now()->subHours(2)]);

        $old = ixIndexer($registry)->drainNow();

        expect(ixKeys('parcel', $parcel))->toContain('order_signature|sigc')
            ->and(ixLog())->toBe([])
            ->and($old->handedOffRows)->toBe(1)
            ->and(DB::table(ixTable('state'))->where('subject_type', 'invoice')->value('rebuild_cursor'))->toBe('')
            // flagged only: the owner rebuilds, this release did not touch the invoice index
            ->and(DB::table(ixTable('entries'))->where('subject_type', 'invoice')->count())->toBe(0);
    });

    it('does not flag a rebuild that is already pending or running', function (): void {
        ixForeignInvoice();
        DB::table(ixTable('state'))->where('subject_type', 'invoice')->update(['rebuild_cursor' => 'abc']);
        $registry = ixSharedRegistry();
        $order = ixOrder();
        DB::table(ixTable('changes'))->delete();
        DB::table(ixTable('changes'))->insert(['node_type' => 'table:orders', 'node_id' => $order, 'changed_at' => now()->subHours(2)]);

        ixIndexer($registry)->drainNow();

        expect(DB::table(ixTable('state'))->where('subject_type', 'invoice')->value('rebuild_cursor'))->toBe('abc');
    });

    it('old kept rows do not block newer rows', function (): void {
        ixForeignInvoice();
        config(['model-search.drain.rows' => 2]);
        $registry = ixSharedRegistry();
        $order = ixOrder();
        $parcel = ixParcel($order, 'P-1');
        ixIndexer($registry)->drainNow();
        DB::table(ixTable('changes'))->delete();

        foreach (range(1, 3) as $ignored) {
            DB::table(ixTable('changes'))->insert(['node_type' => 'table:orders', 'node_id' => $order, 'changed_at' => now()->subHours(2)]);
        }

        DB::table('parcels')->where('id', $parcel)->update(['reference' => 'P-2']);

        ixIndexer($registry)->drainNow();

        expect(ixKeys('parcel', $parcel))->toContain('reference|p2')
            ->and(ixLog())->toBe([]);
    });

    it('hands over a row only unowned types read, once it is old', function (): void {
        ixForeignInvoice();
        $registry = new ModelSearchRegistry;
        $registry->register(OrderPathInvoiceSearch::class);
        $order = ixOrder();
        DB::table(ixTable('changes'))->delete();
        DB::table(ixTable('changes'))->insert([
            ['node_type' => 'table:orders', 'node_id' => $order, 'changed_at' => now()->subHours(2)],
            ['node_type' => 'table:orders', 'node_id' => 'young', 'changed_at' => now()],
        ]);

        ixIndexer($registry)->drainNow();

        expect(ixLog())->toBe(['table:orders|young'])
            ->and(DB::table(ixTable('state'))->where('subject_type', 'invoice')->value('rebuild_cursor'))->toBe('');
    });

    it('never writes a node back to the log while its own row is kept', function (): void {
        ixForeignInvoice();
        config(['model-search.drain.rows' => 2, 'model-search.drain.nodes' => 2]);
        $registry = ixSharedRegistry();
        $first = ixOrder();
        $second = ixOrder();

        foreach ([$first, $second] as $order) {
            foreach (range(1, 3) as $number) {
                ixParcel($order, 'P-'.$number);
            }
        }

        ixIndexer($registry)->drainNow();
        DB::table(ixTable('changes'))->delete();
        DB::table(ixTable('changes'))->insert([['node_type' => 'table:orders', 'node_id' => $first], ['node_type' => 'table:orders', 'node_id' => $second]]);

        // The first node fans out to three parcels, which fills the cap of two: the second is deferred.
        ixIndexer($registry)->drain(0);

        $log = DB::table(ixTable('changes'))->where('node_type', 'table:orders')->pluck('node_id')->sort()->values()->all();

        expect($log)->toBe(collect([$first, $second])->sort()->values()->all());
    });
});

describe('the bounded sweep', function (): void {
    it('walks the entries with a cursor, a bounded step per drain', function (): void {
        foreach (range(1, 5) as $ignored) {
            ixOrder();
        }

        ixIndexer()->drainNow();
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        ixIndexer()->drainNow();
        DB::statement('TRUNCATE orders');
        config(['model-search.drain.rows' => 2]);

        $left = [];
        $cursors = [];
        DB::enableQueryLog();

        foreach (range(1, 3) as $ignored) {
            ixIndexer()->drain(0);
            $left[] = DB::table(ixTable('entries'))->where('subject_type', 'order')->count();
            $cursors[] = DB::table(ixTable('state'))->where('subject_type', 'order')->value('sweep_cursor');
        }

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        expect($left)->toBe([3, 1, 0])
            ->and($cursors[0])->not->toBeNull()
            ->and($cursors[1])->not->toBeNull()->not->toBe($cursors[0])
            ->and($cursors[2])->toBeNull()
            ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'UNION')))->toBe([])
            ->and($state->find('order')['rebuild_cursor'])->toBeNull();
    });

    it('restarts the sweep when the rebuild is flagged again', function (): void {
        foreach (range(1, 4) as $ignored) {
            ixOrder();
        }

        ixIndexer()->drainNow();
        $state = new IndexState;
        $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
        DB::statement('TRUNCATE orders');
        config(['model-search.drain.rows' => 2]);
        ixIndexer()->drain(0);

        expect(DB::table(ixTable('state'))->where('subject_type', 'order')->value('sweep_cursor'))->not->toBeNull();

        DB::statement('TRUNCATE orders');

        expect(DB::table(ixTable('state'))->where('subject_type', 'order')->value('sweep_cursor'))->toBeNull();

        $state->markForRebuild(['order']);
        ixIndexer()->drainNow();

        expect(DB::table(ixTable('entries'))->where('subject_type', 'order')->count())->toBe(0);
    });
});

describe('the allowed transaction level (M7)', function (): void {
    it('is configurable and defaults to refusing any open transaction', function (): void {
        ixOrder();
        config(['model-search.drain.allowed_transaction_level' => 0]);

        expect(fn () => ixIndexer()->drain(0))->toThrow(DrainInsideTransaction::class);
        expect(config('model-search.drain.allowed_transaction_level'))->toBe(0);
        expect((require __DIR__.'/../../../config/model-search.php')['drain']['allowed_transaction_level'])->toBe(0);
    });
});
