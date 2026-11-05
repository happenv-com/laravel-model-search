<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\KeyNormalizer;
use Happenv\ModelSearch\Normalization\Normalizer;

covers(KeyNormalizer::class);

it('strips separators from keys', function (string $raw, string $expected): void {
    expect((new KeyNormalizer)->normalize($raw))->toBe($expected);
})->with([
    'phone' => ['+48 600-100-200', '48600100200'],
    'email' => ['jan.kowalski@x.pl', 'jankowalski@xpl'],
    'order number' => ['OR-9173', 'or9173'],
    'every separator' => ["a b-c_d.e/f#g+h,i:j;k\tl", 'abcdefghijkl'],
    'diacritics' => ['Łódź', 'lodz'],
    'compatibility forms' => ['ＡＢＣ１２３', 'abc123'],
    'combining marks' => ["Cafe\u{0301}", 'cafe'],
]);

it('implements the normalizer contract with a version', function (): void {
    $normalizer = new KeyNormalizer;

    expect($normalizer)->toBeInstanceOf(Normalizer::class)
        ->and($normalizer->version())->toBe(1);
});

it('may normalise to an empty string', function (string $raw): void {
    expect((new KeyNormalizer)->normalize($raw))->toBe('');
})->with(['cjk' => ['北京'], 'emoji' => ['😀'], 'only separators' => [' - . ']]);

it('exposes no public folding helper', function (): void {
    $class = new ReflectionClass(KeyNormalizer::class);

    expect($class->hasMethod('fold') && $class->getMethod('fold')->isPublic())->toBeFalse();
});
