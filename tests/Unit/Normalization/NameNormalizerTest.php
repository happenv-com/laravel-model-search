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

it('may normalise to an empty string', function (string $raw): void {
    expect((new NameNormalizer)->normalize($raw))->toBe('');
})->with(['cjk' => ['北京'], 'emoji' => ['😀']]);

it('treats punctuation inside names as a word boundary', function (string $raw, string $expected): void {
    expect((new NameNormalizer)->normalize($raw))->toBe($expected);
})->with([
    'initial' => ['J.Kowalski', 'j kowalski'],
    'comma' => ['Kowalski, Jan', 'kowalski jan'],
    'apostrophe' => ["O'Brien", 'obrien'],
    'typographic apostrophe' => ['O’Brien', 'obrien'],
    'backtick' => ['O`Brien', 'obrien'],
    'hyphen stays' => ['Kowalska-Nowak', 'kowalska-nowak'],
    'other separators' => ['a_b/c#d+e:f;g', 'a b c d e f g'],
]);

it('takes no locale on the index side', function (): void {
    expect((new ReflectionMethod(NameNormalizer::class, 'normalize'))->getNumberOfParameters())->toBe(1);
});

it('transliterates for a locale on the query side', function (): void {
    expect((new NameNormalizer)->transliterated('Müller', 'de'))->toBe('mueller');
});
