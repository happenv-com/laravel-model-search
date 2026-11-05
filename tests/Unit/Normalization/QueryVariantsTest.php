<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\QueryVariants;

covers(QueryVariants::class);

it('adds the locale transliteration next to the neutral form', function (): void {
    $variants = QueryVariants::for('Müller', 'de');

    expect($variants)->toBe(['muller', 'mueller']);
});

it('adds no variant when the locale does not change the form', function (): void {
    expect(QueryVariants::for('Müller', 'pl'))->toBe(['muller'])
        ->and(QueryVariants::for('Müller', null))->toBe(['muller'])
        ->and(QueryVariants::for('Kowalski', 'de'))->toBe(['kowalski']);
});

it('never returns more than three distinct variants', function (): void {
    expect(QueryVariants::for('Müller Größe Ärger', 'de'))->toHaveCount(2)
        ->and(count(QueryVariants::for('Müller', 'de')))->toBeLessThanOrEqual(3);
});

it('returns nothing for a blank phrase', function (): void {
    expect(QueryVariants::for('   ', 'de'))->toBe([]);
});
