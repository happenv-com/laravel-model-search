<?php

declare(strict_types=1);

use Happenv\ModelSearch\Drivers\DatabaseDriver;
use Happenv\ModelSearch\Normalization\KeyNormalizer;
use Happenv\ModelSearch\Normalization\NameNormalizer;
use Happenv\ModelSearch\Normalization\Normalizer;
use Happenv\ModelSearch\Normalization\PhraseShape;
use Happenv\ModelSearch\Normalization\QueryVariants;

covers(QueryVariants::class);

dataset('phrase carrying parameters', [
    [QueryVariants::class, 'for', 'q'],
    [QueryVariants::class, 'keysFor', 'q'],
    [PhraseShape::class, 'isIdentifierLike', 'q'],
    [PhraseShape::class, 'words', 'q'],
    [DatabaseDriver::class, 'keyBranch', 'phrase'],
    [DatabaseDriver::class, 'wordSets', 'phrase'],
    [Normalizer::class, 'normalize', 'value'],
    [KeyNormalizer::class, 'normalize', 'value'],
    [KeyNormalizer::class, 'transliterated', 'value'],
    [NameNormalizer::class, 'normalize', 'value'],
    [NameNormalizer::class, 'transliterated', 'value'],
    [NameNormalizer::class, 'unicodeFolded', 'value'],
]);

it('hides a phrase carrying parameter from stack traces', function (string $class, string $method, string $parameter): void {
    $found = array_values(array_filter(
        new ReflectionMethod($class, $method)->getParameters(),
        static fn (ReflectionParameter $p): bool => $p->getName() === $parameter,
    ));

    expect($found)->toHaveCount(1)
        ->and($found[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with('phrase carrying parameters');
