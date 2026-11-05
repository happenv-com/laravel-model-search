<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\PhraseShape;

covers(PhraseShape::class);

it('recognises identifier-like phrases', function (string $phrase, bool $expected): void {
    expect(PhraseShape::isIdentifierLike($phrase))->toBe($expected);
})->with([
    'email' => ['foo@bar.pl', true],
    'digits' => ['123456', true],
    'plain word' => ['kowal', false],
    'mixed order number' => ['or9173', true],
    'separators do not count' => ['OR-9173', true],
    'no alphanumerics' => ['---', false],
    'empty' => ['', false],
]);

it('honours the configured digit share', function (): void {
    config()->set('model-search.identifier_rule.digit_share', 0.9);

    expect(PhraseShape::isIdentifierLike('or9173'))->toBeFalse();
});

it('splits a phrase into normalized words', function (): void {
    expect(PhraseShape::words('  Łódź   Kowalska-Nowak '))->toBe(['lodz', 'kowalska', 'nowak'])
        ->and(PhraseShape::words(''))->toBe([]);
});
