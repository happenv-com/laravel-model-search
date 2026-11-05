<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\NameNormalizer;
use Happenv\ModelSearch\Normalization\NameWords;

covers(NameWords::class);

it('splits on whitespace and hyphens', function (): void {
    expect(NameWords::of('anna maria kowalska-nowak'))->toBe(['anna', 'maria', 'kowalska', 'nowak']);
});

it('skips words shorter than the configured minimum', function (): void {
    expect(NameWords::of('anna k maria'))->toBe(['anna', 'maria']);

    config()->set('model-search.name_words.min_length', 4);

    expect(NameWords::of('ola anna ewa'))->toBe(['anna']);
});

it('keeps unique words in order of appearance', function (): void {
    expect(NameWords::of('maria anna maria anna zofia'))->toBe(['maria', 'anna', 'zofia']);
});

it('does not apply the per-record cap', function (): void {
    $name = implode(' ', array_map(static fn (int $i): string => 'word'.$i, range(1, 20)));

    expect(NameWords::of($name))->toHaveCount(20);
});

it('returns nothing for an empty name', function (): void {
    expect(NameWords::of(''))->toBe([]);
});

it('keeps numeric words as strings', function (): void {
    expect(NameWords::of('anna 12 012'))->toBe(['anna', '12', '012']);
});

it('splits punctuation-joined names into words', function (): void {
    $normalizer = new NameNormalizer;

    expect(NameWords::of($normalizer->normalize('J.Kowalski')))->toBe(['kowalski'])
        ->and(NameWords::of($normalizer->normalize('Kowalski, Jan')))->toBe(['kowalski', 'jan'])
        ->and(NameWords::of($normalizer->normalize("O'Brien")))->toBe(['obrien']);
});
