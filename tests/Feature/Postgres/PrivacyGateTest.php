<?php

declare(strict_types=1);

use Happenv\ModelSearch\Events\SearchPrivacyUnsafe;
use Happenv\ModelSearch\Postgres\Session\PrivacyGate;
use Happenv\ModelSearch\Postgres\Session\PrivacyVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

covers(PrivacyGate::class);

/**
 * @param  array<string, string>  $overrides
 * @return array<string, array{setting: string, vartype: string}>
 */
function pgSettings(array $overrides = []): array
{
    $settings = [
        'log_statement' => ['setting' => 'none', 'vartype' => 'enum'],
        'log_min_duration_statement' => ['setting' => '-1', 'vartype' => 'integer'],
        'log_min_duration_sample' => ['setting' => '-1', 'vartype' => 'integer'],
        'log_statement_sample_rate' => ['setting' => '1', 'vartype' => 'real'],
        'log_transaction_sample_rate' => ['setting' => '0', 'vartype' => 'real'],
        'debug_print_parse' => ['setting' => 'off', 'vartype' => 'bool'],
        'debug_print_rewritten' => ['setting' => 'off', 'vartype' => 'bool'],
        'debug_print_plan' => ['setting' => 'off', 'vartype' => 'bool'],
        'auto_explain.log_min_duration' => ['setting' => '-1', 'vartype' => 'integer'],
    ];

    foreach ($overrides as $name => $value) {
        $settings[$name] = ['setting' => $value, 'vartype' => $settings[$name]['vartype'] ?? 'integer'];
    }

    return $settings;
}

it('accepts a server that logs nothing about statements', function (): void {
    $verdict = PrivacyGate::evaluate(pgSettings());

    expect($verdict->isSafe())->toBeTrue()
        ->and($verdict->limitMs)->toBeNull()
        ->and($verdict->effectiveTimeout(300))->toBe(300);
});

it('flags every setting that can write a statement with its parameters', function (string $setting, string $value): void {
    $verdict = PrivacyGate::evaluate(pgSettings([$setting => $value]));

    expect($verdict->isSafe())->toBeFalse()
        ->and(implode(' ', $verdict->violations))->toContain($setting);
})->with([
    ['log_statement', 'all'],
    ['log_min_duration_statement', '0'],
    ['log_min_duration_statement', '50'],
    ['auto_explain.log_min_duration', '0'],
    ['auto_explain.log_min_duration', '20'],
    ['log_min_duration_sample', '0'],
    ['log_transaction_sample_rate', '0.1'],
    ['debug_print_parse', 'on'],
    ['debug_print_rewritten', 'on'],
    ['debug_print_plan', 'on'],
]);

it('takes the lowest positive threshold less a margin as the search limit', function (): void {
    $verdict = PrivacyGate::evaluate(pgSettings(['log_min_duration_statement' => '1000', 'auto_explain.log_min_duration' => '500', 'log_min_duration_sample' => '700']));

    expect($verdict->isSafe())->toBeTrue()
        ->and($verdict->limitMs)->toBe(450)
        ->and($verdict->effectiveTimeout(300))->toBe(300)
        ->and($verdict->effectiveTimeout(1000))->toBe(450);
});

it('treats an unloaded auto_explain as disabled', function (): void {
    $placeholder = pgSettings(['auto_explain.log_min_duration' => '0']);
    $placeholder['auto_explain.log_min_duration']['vartype'] = 'string';
    $absent = pgSettings();
    unset($absent['auto_explain.log_min_duration']);

    expect(PrivacyGate::evaluate($placeholder)->isSafe())->toBeTrue()
        ->and(PrivacyGate::evaluate($absent)->isSafe())->toBeTrue();
});

it('ignores the sample duration while sampling is off', function (): void {
    $verdict = PrivacyGate::evaluate(pgSettings(['log_min_duration_sample' => '0', 'log_statement_sample_rate' => '0']));

    expect($verdict->isSafe())->toBeTrue();
});

it('round trips a verdict through a scalar', function (): void {
    $verdict = new PrivacyVerdict(['log_statement is all'], 450);

    expect(PrivacyVerdict::fromScalar($verdict->toScalar()))->toEqual($verdict)
        ->and(PrivacyVerdict::fromScalar('not json'))->toBeNull()
        ->and(PrivacyVerdict::fromScalar(5))->toBeNull();
});

it('refuses by default and warns when asked to', function (): void {
    expect(app(PrivacyGate::class)->refuses())->toBeTrue();

    config(['model-search.privacy' => 'warn']);
    expect(app(PrivacyGate::class)->refuses())->toBeFalse();

    config(['model-search.privacy' => 'nonsense']);
    expect(app(PrivacyGate::class)->refuses())->toBeTrue();
});

it('reads the server and caches the verdict until flushed', function (): void {
    $gate = app(PrivacyGate::class);
    $gate->flush();
    DB::statement("SET LOCAL log_statement = 'all'");
    $unsafe = $gate->verdict(DB::connection());

    DB::statement("SET LOCAL log_statement = 'none'");
    $cached = $gate->verdict(DB::connection());
    $gate->flush();
    $fresh = $gate->verdict(DB::connection());

    expect($unsafe->isSafe())->toBeFalse()
        ->and($cached->isSafe())->toBeFalse()
        ->and($fresh->isSafe())->toBeTrue();
});

it('announces an unsafe reading once per fresh read', function (): void {
    Event::fake([SearchPrivacyUnsafe::class]);
    $gate = app(PrivacyGate::class);
    $gate->flush();
    DB::statement("SET LOCAL log_statement = 'all'");

    $gate->verdict(DB::connection());
    $gate->verdict(DB::connection());

    Event::assertDispatchedTimes(SearchPrivacyUnsafe::class, 1);
    Event::assertDispatched(SearchPrivacyUnsafe::class, static fn (SearchPrivacyUnsafe $event): bool => $event->refused);
});
