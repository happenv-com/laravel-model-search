<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

covers(CompiledDefinition::class);

function compiledDefinitionForTest(array $fields, array $stages): CompiledDefinition
{
    return new CompiledDefinition(Order::class, 'order', 'uuid', $fields, $stages, null, null);
}

it('reports names and stages', function (): void {
    $plain = compiledDefinitionForTest([SearchField::key('number')->toCompiled(false)], [SearchStage::all()]);
    $rich = compiledDefinitionForTest(
        [SearchField::name('title')->toCompiled(false)],
        [SearchStage::make('open', static fn (): null => null), SearchStage::all()],
    );

    expect($plain->hasNames())->toBeFalse()
        ->and($plain->hasStages())->toBeFalse()
        ->and($rich->hasNames())->toBeTrue()
        ->and($rich->hasStages())->toBeTrue();
});

it('refuses a key type that is not a plain type name', function (): void {
    new CompiledDefinition(Order::class, 'order', 'uuid', [], [SearchStage::all()], null, null, keySqlType: 'uuid); DROP TABLE x; --');
})->throws(InvalidSearchDefinition::class);

it('accepts a plain key type name', function (): void {
    $definition = new CompiledDefinition(Order::class, 'order', 'uuid', [], [SearchStage::all()], null, null, keySqlType: 'bpchar');

    expect($definition->keySqlType)->toBe('bpchar');
});
