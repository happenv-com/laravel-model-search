<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests;

use Happenv\ModelSearch\ModelSearchServiceProvider;
use Happenv\ModelSearch\Tests\Fixtures\FixtureSearchServiceProvider;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ModelSearchServiceProvider::class, FixtureSearchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'postgres'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }
}
