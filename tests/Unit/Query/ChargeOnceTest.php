<?php

declare(strict_types=1);

use Happenv\ModelSearch\Query\ChargeOnce;

covers(ChargeOnce::class);

it('lets the first caller pay and the others find it paid', function (): void {
    $charge = new ChargeOnce;
    $payments = 0;

    $charge->once(function () use (&$payments): void {
        $payments++;
    });
    $charge->once(function () use (&$payments): void {
        $payments++;
    });

    expect($payments)->toBe(1);
});

it('asks again after a payment that failed', function (): void {
    $charge = new ChargeOnce;

    expect(fn () => $charge->once(static fn () => throw new RuntimeException('limit')))->toThrow(RuntimeException::class);

    $paid = false;
    $charge->once(function () use (&$paid): void {
        $paid = true;
    });

    expect($paid)->toBeTrue();
});
