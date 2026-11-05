<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidFieldPath;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\ReservedKind;
use Happenv\ModelSearch\Exceptions\UnsupportedMode;
use Happenv\ModelSearch\Normalization\Normalizer;

covers(SearchField::class);

it('applies default modes per field type', function (): void {
    expect(SearchField::key('number')->toCompiled(false)->modes)->toBe([Mode::Exact, Mode::Prefix, Mode::Suffix])
        ->and(SearchField::name('title')->toCompiled(false)->modes)->toBe([Mode::Prefix, Mode::Fuzzy])
        ->and(SearchField::via('order')->toCompiled(false)->modes)->toBe([]);
});

it('derives the kind from the last path segment unless one is given', function (): void {
    expect(SearchField::key('billingAddress.phone')->toCompiled(false)->kind)->toBe('phone')
        ->and(SearchField::key('billingAddress.phone')->kind('contact_phone')->toCompiled(false)->kind)->toBe('contact_phone')
        ->and(SearchField::via('order')->toCompiled(false)->kind)->toBe('order');
});

it('narrows modes and refuses modes the field type cannot serve', function (): void {
    expect(SearchField::key('number')->matches(Mode::Exact)->toCompiled(false)->modes)->toBe([Mode::Exact])
        ->and(SearchField::key('number')->matches(Mode::Exact, Mode::Infix)->toCompiled(false)->modes)->toBe([Mode::Exact, Mode::Infix]);

    SearchField::key('number')->matches(Mode::Fuzzy);
})->throws(UnsupportedMode::class);

it('refuses to narrow a name field to exact matching', function (): void {
    SearchField::name('title')->matches(Mode::Exact);
})->throws(UnsupportedMode::class);

it('refuses to narrow a via field', function (): void {
    SearchField::via('order')->matches(Mode::Exact);
})->throws(UnsupportedMode::class);

it('refuses the full-text seam', function (): void {
    SearchField::text('body');
})->throws(UnsupportedMode::class);

it('refuses the full-text mode on any field', function (): void {
    SearchField::key('number')->matches(Mode::FullText);
})->throws(UnsupportedMode::class);

it('rejects exactOnSource on a relation path', function (): void {
    SearchField::key('billingAddress.phone')->exactOnSource();
})->throws(InvalidSearchDefinition::class, 'relation path');

it('rejects exactOnSource on a name field', function (): void {
    SearchField::name('title')->exactOnSource();
})->throws(InvalidSearchDefinition::class);

it('reserves the name_word kind', function (): void {
    SearchField::key('number')->kind('name_word');
})->throws(ReservedKind::class);

it('reserves the name_word kind when it is the derived one', function (): void {
    SearchField::name('profile.name_word');
})->throws(ReservedKind::class);

it('rejects malformed paths', function (string $path): void {
    SearchField::key($path);
})->with(['empty' => [''], 'trailing dot' => ['a.'], 'space' => ['a b'], 'sql' => ['a;drop']])
    ->throws(InvalidFieldPath::class);

it('rejects a via field that names a path', function (): void {
    SearchField::via('order.customer');
})->throws(InvalidFieldPath::class);

it('carries every modifier into the compiled field', function (): void {
    $normalizer = new class implements Normalizer
    {
        public function normalize(string $value): string
        {
            return $value;
        }

        public function version(): int
        {
            return 3;
        }
    };
    $label = static fn (): string => 'Title';
    $reveal = static fn (): bool => false;

    $compiled = SearchField::name('title')
        ->label($label)
        ->normalizeUsing($normalizer)
        ->translatable()
        ->revealUsing($reveal)
        ->weight(5)
        ->toCompiled(true);

    expect($compiled->type)->toBe(FieldType::Name)
        ->and($compiled->label)->toBe($label)
        ->and($compiled->normalizer)->toBe($normalizer)
        ->and($compiled->translatable)->toBeTrue()
        ->and($compiled->reveal)->toBe($reveal)
        ->and($compiled->weight)->toBe(5)
        ->and($compiled->declaredByExtension)->toBeTrue()
        ->and(SearchField::via('order', ['signature'])->toCompiled(false)->via)->toBe(['signature']);
});

it('rejects exactOnSource on a field whose modes do not include exact', function (): void {
    expect(static fn () => SearchField::key('number')->matches(Mode::Prefix)->exactOnSource()->toCompiled(false))
        ->toThrow(InvalidSearchDefinition::class, 'Mode::Exact')
        ->and(static fn () => SearchField::key('number')->exactOnSource()->matches(Mode::Suffix)->toCompiled(false))
        ->toThrow(InvalidSearchDefinition::class, 'Mode::Exact')
        ->and(SearchField::key('number')->exactOnSource()->matches(Mode::Exact)->toCompiled(false)->exactOnSource)->toBeTrue();
});

it('rejects a negative weight and allows zero', function (): void {
    expect(static fn () => SearchField::key('number')->weight(-1))->toThrow(InvalidSearchDefinition::class, 'negative')
        ->and(SearchField::key('number')->weight(0)->toCompiled(false)->weight)->toBe(0);
});
