<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
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
