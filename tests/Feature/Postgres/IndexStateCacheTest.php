<?php

declare(strict_types=1);

use Happenv\ModelSearch\Postgres\Indexing\IndexState;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Illuminate\Support\Facades\DB;

covers(IndexState::class);

/**
 * A second connection to the same server that sees another, empty state table (a table prefix of
 * its own) while it shares the test's session and transaction: the same alias, another database.
 */
function iscSecondDatabase(): string
{
    config(['database.connections.second' => [...config('database.connections.pgsql'), 'prefix' => 'b_']]);
    DB::purge('second');
    DB::connection('second')->setPdo(DB::connection()->getPdo());
    $table = 'b_'.TableNames::forConnection()->state();
    DB::statement(sprintf('CREATE TABLE "%s" (LIKE "%s" INCLUDING ALL)', $table, TableNames::forConnection()->state()));

    return 'second';
}

it('never reads the cached readiness of another connection or database', function (): void {
    $definition = app(ModelSearchRegistry::class)->for(Order::class);
    $first = new IndexState;
    $first->recordInstall($definition);
    DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['ready_at' => now(), 'rebuild_cursor' => null]);
    $first->forget('order');

    $second = new IndexState(iscSecondDatabase());

    expect($first->isReady('order'))->toBeTrue()
        ->and($first->isRebuilding('order'))->toBeFalse()
        ->and($second->isReady('order'))->toBeFalse()
        ->and($second->isRebuilding('order'))->toBeFalse();
});

it('tells a pending rebuild from a ready index, cached next to the readiness', function (): void {
    $definition = app(ModelSearchRegistry::class)->for(Order::class);
    $state = new IndexState;
    $state->recordInstall($definition);

    expect($state->isReady('order'))->toBeFalse()
        ->and($state->isRebuilding('order'))->toBeTrue();

    DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['ready_at' => now(), 'rebuild_cursor' => null]);
    $state->forget('order');

    expect($state->isReady('order'))->toBeTrue()
        ->and($state->isRebuilding('order'))->toBeFalse();

    DB::table(TableNames::forConnection()->state())->where('subject_type', 'order')->update(['rebuild_cursor' => '']);
    $state->forget('order');

    expect($state->isReady('order'))->toBeTrue()
        ->and($state->isRebuilding('order'))->toBeTrue();
});
