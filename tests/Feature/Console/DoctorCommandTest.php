<?php

declare(strict_types=1);

use Happenv\ModelSearch\Console\DoctorCommand;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Postgres\Doctor\Doctor;
use Happenv\ModelSearch\Postgres\Indexing\SearchIndexer;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Postgres\Session\PrivacyGate;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(DoctorCommand::class);

function dcTable(string $name): string
{
    return TableNames::forConnection()->{$name}();
}

function dcOrder(): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => 'SIG-'.substr($id, 0, 6), 'state' => 'unshipped', 'customer_name' => 'Ann Kowalska']);

    return $id;
}

/**
 * @return list<string> The codes of the findings.
 */
function dcCodes(): array
{
    return array_map(static fn ($finding): string => $finding->code, app(Doctor::class)->inspect());
}

beforeEach(function (): void {
    // Records the install of the fixture types, then brings the projection up to date.
    app(SchemaSync::class)->apply();
    app(SearchIndexer::class)->drainNow();
    app(PrivacyGate::class)->flush();
});

it('finds nothing wrong with a healthy index', function (): void {
    dcOrder();
    app(SearchIndexer::class)->drainNow();

    expect(dcCodes())->toBe([]);
    expect(Artisan::call('model-search:doctor'))->toBe(0);
});

it('doctor lists privacy violations', function (): void {
    DB::statement("SET LOCAL log_statement = 'all'");

    $exit = Artisan::call('model-search:doctor');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('log_statement')
        ->and(dcCodes())->toBe(['privacy']);
});

it('doctor reports key stage drift', function (): void {
    $id = dcOrder();
    app(SearchIndexer::class)->drainNow();
    DB::table(dcTable('keys'))->where('subject_id', $id)->update(['stage' => 5]);

    $findings = app(Doctor::class)->inspect();

    expect(array_map(static fn ($finding): string => $finding->code, $findings))->toBe(['key-stage-drift'])
        ->and($findings[0]->subject)->toBe('order')
        ->and($findings[0]->message)->toContain('order');
});

it('reports key rows that have no entry', function (): void {
    $id = dcOrder();
    app(SearchIndexer::class)->drainNow();
    DB::table(dcTable('entries'))->where('subject_id', $id)->delete();

    expect(dcCodes())->toBe(['keys-without-entry']);
});

it('runs its scans with parallel workers off', function (): void {
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    dcCodes();

    $parallel = array_keys(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'max_parallel_workers_per_gather')));
    $scans = array_keys(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'IS DISTINCT FROM')));

    expect($parallel)->not->toBe([])
        ->and($scans)->not->toBe([])
        ->and(min($parallel))->toBeLessThan(min($scans));
});

it('doctor reports a missing planned index', function (): void {
    $spec = app(SchemaSync::class)->plan()->indexes[0];
    DB::statement(sprintf('DROP INDEX "%s"', $spec->name));

    $findings = app(Doctor::class)->inspect();

    expect(array_map(static fn ($finding): string => $finding->code, $findings))->toBe(['index-missing'])
        ->and($findings[0]->subject)->toBe($spec->name);
});

it('reports a planned index that is not valid', function (): void {
    $spec = app(SchemaSync::class)->plan()->indexes[0];
    DB::statement(sprintf("UPDATE pg_index SET indisvalid = false WHERE indexrelid = to_regclass('public.%s')", $spec->name));

    $findings = app(Doctor::class)->inspect();

    expect(array_map(static fn ($finding): string => $finding->code, $findings))->toBe(['index-invalid'])
        ->and($findings[0]->subject)->toBe($spec->name);
});

it('reports lag, failures, unknown nodes and a type that is not ready', function (): void {
    config(['model-search.lag_alarm_seconds' => 60]);
    DB::table(dcTable('changes'))->insert(['node_type' => 'nobody_knows_this', 'node_id' => '1', 'changed_at' => now()->subHours(2)]);
    DB::table(dcTable('failures'))->insert(['subject_type' => 'order', 'subject_id' => 'x', 'attempts' => 3, 'last_error_class' => RuntimeException::class, 'last_failed_at' => now()]);
    DB::table(dcTable('state'))->where('subject_type', 'customer')->update(['ready_at' => null]);

    $codes = dcCodes();
    sort($codes);

    expect($codes)->toBe(['failures', 'lag', 'type-not-ready', 'unknown-nodes']);
});

it('says what serves a type that is not ready: the fallback, or only its source fields', function (): void {
    DB::table(dcTable('state'))->whereIn('subject_type', ['customer'])->update(['ready_at' => null]);
    $with = collect(app(Doctor::class)->inspect())->firstWhere('code', 'type-not-ready');

    expect($with->message)->toContain('served from the source tables');
});

it('reports a type that is not installed or installed for another definition', function (): void {
    DB::table(dcTable('state'))->where('subject_type', 'invoice')->delete();
    DB::table(dcTable('state'))->where('subject_type', 'customer')->update(['definition_fingerprint' => 'other']);

    $codes = dcCodes();
    sort($codes);

    expect($codes)->toBe(['fingerprint-differs', 'type-not-installed']);

    $differs = collect(app(Doctor::class)->inspect())->firstWhere('code', 'fingerprint-differs');

    expect($differs->message)->toContain('ModelSearch::version()');
});

it('reports a privacy finding and still runs the key checks when parameter logging cannot be switched off', function (): void {
    $id = dcOrder();
    app(SearchIndexer::class)->drainNow();
    DB::table(dcTable('keys'))->where('subject_id', $id)->update(['stage' => 5]);
    DB::connection()->beforeExecuting(static function (string $query): void {
        if (str_contains($query, 'SET LOCAL log_parameter_max_length_on_error')) {
            throw new QueryException('pgsql', $query, [], new PDOException('refused'));
        }
    });

    expect(dcCodes())->toBe(['privacy', 'key-stage-drift']);
});

it('puts the parameter logging setting back after the key checks', function (): void {
    DB::statement('SET LOCAL log_parameter_max_length_on_error = 77');

    dcCodes();

    expect((string) DB::selectOne("SELECT current_setting('log_parameter_max_length_on_error') AS value")->value)->toBe('77B');
});

it('reports a key field that only the database driver can search by Infix', function (): void {
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->matches(Mode::Infix), SearchField::key('external_number')];
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);

    $infix = collect(app(Doctor::class)->inspect())->firstWhere('code', 'infix-ignored');

    expect($infix->subject)->toBe('order')
        ->and($infix->message)->toContain('signature')->toContain('postgres driver');

    config(['model-search.driver' => 'database']);

    expect(collect(app(Doctor::class)->inspect())->firstWhere('code', 'infix-ignored'))->toBeNull();
});
