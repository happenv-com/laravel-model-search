<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;

covers(SearchStage::class);

it('builds a constrained stage with watched columns and a label', function (): void {
    $stage = SearchStage::make('open', static fn (): null => null)
        ->watching(['state', 'state', 'is_archived'])
        ->label(static fn (): string => 'Open');

    expect($stage->isAll())->toBeFalse()
        ->and($stage->watchedColumns())->toBe(['state', 'is_archived'])
        ->and(($stage->labelResolver())())->toBe('Open');
});

it('builds the unconstrained all stage', function (): void {
    expect(SearchStage::all()->isAll())->toBeTrue()
        ->and(SearchStage::all()->key)->toBe('all');
});

it('reserves the all key', function (): void {
    SearchStage::make('all', static fn (): null => null);
})->throws(InvalidSearchDefinition::class);
