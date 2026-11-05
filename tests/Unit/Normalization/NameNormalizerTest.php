<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\NameNormalizer;
use Happenv\ModelSearch\Normalization\Normalizer;

covers(NameNormalizer::class);

it('folds names and keeps word separators', function (string $raw, string $expected): void {
    expect((new NameNormalizer)->normalize($raw))->toBe($expected);
})->with([
    'diacritics' => ['Łódź', 'lodz'],
    'collapsed whitespace' => ["  Anna \t Maria\n Kowalska-Nowak ", 'anna maria kowalska-nowak'],
    'compatibility forms' => ['ＡＮＮＡ', 'anna'],
]);

it('implements the normalizer contract with a version', function (): void {
    $normalizer = new NameNormalizer;

    expect($normalizer)->toBeInstanceOf(Normalizer::class)
        ->and($normalizer->version())->toBe(1);
});
