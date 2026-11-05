<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Normalizers\HookedNormalizer;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\TruncationTestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TruncationTestCase::class);

covers(SearchIndexer::class);

afterEach(function (): void {
    HookedNormalizer::$hook = null;
    ConfigurableOrderSearch::reset();
    DB::purge('second');
});

function cxSecond(): Connection
{
    config(['database.connections.second' => config('database.connections.pgsql')]);

    return DB::connection('second');
}

function cxIndexer(?string $connection = null, ?ModelSearchRegistry $registry = null): SearchIndexer
{
    return new SearchIndexer($registry ?? app(ModelSearchRegistry::class), new IndexState($connection), $connection);
}

function cxChanges(?Connection $db = null): int
{
    return ($db ?? DB::connection())->table(TableNames::forConnection()->changes())->count();
}

function cxOrder(Connection $db, string $signature): string
{
    $id = (string) Str::uuid();
    $db->table('orders')->insert(['id' => $id, 'signature' => $signature, 'state' => 'unshipped']);

    return $id;
}

it('one drainer per batch', function (): void {
    $other = cxSecond();
    $id = cxOrder(DB::connection(), 'LOCKED-1');

    // A drainer in the middle of a batch holds the transaction-scoped lock.
    $other->beginTransaction();
    $other->selectOne('SELECT pg_advisory_xact_lock(?)', [cxIndexer()->lockKey()]);

    try {
        $blocked = cxIndexer()->drain(0);

        expect($blocked->lockedOut)->toBeTrue()
            ->and($blocked->logRowsRead)->toBe(0)
            ->and(cxChanges())->toBe(1);
    } finally {
        $other->rollBack();
    }

    $stats = cxIndexer()->drain(0);

    expect($stats->lockedOut)->toBeFalse()
        ->and(cxChanges())->toBe(0)
        ->and(DB::table(TableNames::forConnection()->entries())->where('subject_id', $id)->count())->toBe(1);
});

it('the lock is released with the batch, not kept by the session', function (): void {
    $other = cxSecond();
    cxOrder(DB::connection(), 'LOCKED-2');

    cxIndexer()->drain(0);

    // If the drain had kept a session lock, this attempt on another connection would fail.
    expect($other->selectOne('SELECT pg_try_advisory_lock(?) AS locked', [cxIndexer()->lockKey()])->locked)->toBeTrue();
    $other->selectOne('SELECT pg_advisory_unlock(?)', [cxIndexer()->lockKey()]);
});

it('accepts writes while a batch holds its lock', function (): void {
    $other = cxSecond();
    $other->statement("SET lock_timeout = '1s'");
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(new HookedNormalizer)];
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    $first = cxOrder(DB::connection(), 'FIRST-1');
    $late = null;
    $rival = null;

    // While the first drainer is inside its batch (its lock is held, its rows are read), a writer on
    // another connection commits a new order and a second drainer is turned away.
    HookedNormalizer::$hook = static function () use ($other, $registry, &$late, &$rival): void {
        if ($late !== null) {
            return;
        }

        $late = cxOrder($other, 'LATE-2');
        $rival = cxIndexer('second', $registry)->drain(0);
    };

    $stats = cxIndexer(null, $registry)->drain(0);
    HookedNormalizer::$hook = null;

    expect($late)->not->toBeNull()
        ->and($rival?->lockedOut)->toBeTrue()
        ->and($stats->logRowsRead)->toBe(1)
        // Only the row that was read is gone; the write that arrived mid-batch is still in the log.
        ->and(DB::table(TableNames::forConnection()->changes())->pluck('node_id')->all())->toBe([$late])
        ->and(DB::table(TableNames::forConnection()->entries())->where('subject_id', $first)->count())->toBe(1)
        ->and(DB::table(TableNames::forConnection()->entries())->where('subject_id', $late)->count())->toBe(0);

    cxIndexer(null, $registry)->drainNow();

    expect(DB::table(TableNames::forConnection()->entries())->where('subject_id', $late)->count())->toBe(1)
        ->and(cxChanges())->toBe(0);
});

it('a writer is not blocked by a drainer that holds the lock', function (): void {
    $other = cxSecond();
    $other->statement("SET lock_timeout = '500ms'");
    $other->beginTransaction();
    $other->selectOne('SELECT pg_advisory_xact_lock(?)', [cxIndexer()->lockKey()]);

    try {
        $id = cxOrder(DB::connection(), 'WRITE-1');
        DB::table('orders')->where('id', $id)->update(['customer_email' => 'a@b.c']);
        DB::table('orders')->where('id', $id)->delete();
    } finally {
        $other->rollBack();
    }

    expect(cxChanges())->toBe(3);
});

it('a rebuild step and a second drainer exclude each other too', function (): void {
    $other = cxSecond();
    $state = new IndexState;
    $state->recordInstall(app(ModelSearchRegistry::class)->for(Order::class));
    cxOrder(DB::connection(), 'REBUILD-1');
    DB::table(TableNames::forConnection()->changes())->delete();

    $other->beginTransaction();
    $other->selectOne('SELECT pg_advisory_xact_lock(?)', [cxIndexer()->lockKey()]);

    try {
        $blocked = cxIndexer()->drain(0);

        expect($blocked->lockedOut)->toBeTrue()
            ->and($blocked->rebuildBatches)->toBe(0)
            ->and($state->find('order')['rebuild_cursor'])->toBe('');
    } finally {
        $other->rollBack();
    }

    cxIndexer()->drainNow();

    expect($state->find('order')['rebuild_cursor'])->toBeNull()
        ->and($state->isReady('order'))->toBeTrue();
});
