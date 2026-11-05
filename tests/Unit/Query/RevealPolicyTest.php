<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Query\RevealPolicy;
use Happenv\ModelSearch\Query\SearchMatch;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;

covers(RevealPolicy::class);

afterEach(fn () => ConfigurableOrderSearch::reset());

function revealDefinition(array $fields): CompiledDefinition
{
    ConfigurableOrderSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    return $registry->for(Order::class);
}

function revealMatch(string $kind, ?string $value, array $alternatives = []): SearchMatch
{
    return new SearchMatch($kind, 'Label', $value, Mode::Exact, null, null, $alternatives);
}

it('keeps the value when the field has no reveal rule', function (): void {
    $definition = revealDefinition([SearchField::key('signature')]);

    $match = (new RevealPolicy($definition))->apply(revealMatch('signature', 'A-1'), new Order, null);

    expect($match->value)->toBe('A-1');
});

it('hides the value when the reveal rule of the field denies', function (): void {
    $definition = revealDefinition([
        SearchField::key('signature')->revealUsing(static fn (): bool => false),
        SearchField::key('external_number'),
    ]);
    $policy = new RevealPolicy($definition);

    expect($policy->apply(revealMatch('signature', 'A-1'), new Order, null)->value)->toBeNull()
        ->and($policy->apply(revealMatch('external_number', 'X'), new Order, null)->value)->toBe('X');
});

it('hands the model and the viewer to the reveal rule', function (): void {
    $seen = [];
    $definition = revealDefinition([
        SearchField::key('signature')->revealUsing(static function ($record, $viewer) use (&$seen): bool {
            $seen = [$record, $viewer];

            return true;
        }),
    ]);
    $order = new Order;

    (new RevealPolicy($definition))->apply(revealMatch('signature', 'A-1'), $order, 'viewer');

    expect($seen)->toBe([$order, 'viewer']);
});

it('drops alternatives whose field denies and keeps the others', function (): void {
    $definition = revealDefinition([
        SearchField::key('signature'),
        SearchField::key('external_number')->revealUsing(static fn (): bool => false),
        SearchField::key('customer_email'),
    ]);

    $match = (new RevealPolicy($definition))->apply(
        revealMatch('signature', 'A', [revealMatch('external_number', 'B'), revealMatch('customer_email', 'C')]),
        new Order,
        null,
    );

    expect($match->alternatives)->toHaveCount(1)
        ->and($match->alternatives[0]->kind)->toBe('customer_email')
        ->and($match->alternatives[0]->value)->toBe('C');
});

it('reveals a name word only when every name field allows it', function (): void {
    $allow = static fn (): bool => true;
    $deny = static fn (): bool => false;
    $word = revealMatch('name_word', 'Jan Kowalski');

    $allAllow = revealDefinition([SearchField::name('customer_name')->revealUsing($allow), SearchField::name('shippingAddress.name')]);
    $oneDenies = revealDefinition([SearchField::name('customer_name')->revealUsing($allow), SearchField::name('shippingAddress.name')->revealUsing($deny)]);

    expect((new RevealPolicy($allAllow))->apply($word, new Order, null)->value)->toBe('Jan Kowalski')
        ->and((new RevealPolicy($oneDenies))->apply($word, new Order, null)->value)->toBeNull();
});

it('drops a name word alternative when any name field denies', function (): void {
    $definition = revealDefinition([
        SearchField::key('signature'),
        SearchField::name('customer_name')->revealUsing(static fn (): bool => false),
    ]);

    $match = (new RevealPolicy($definition))->apply(
        revealMatch('signature', 'A', [revealMatch('name_word', 'Jan')]),
        new Order,
        null,
    );

    expect($match->alternatives)->toBe([]);
});

it('treats a kind the definition does not know as hidden', function (): void {
    $definition = revealDefinition([SearchField::key('signature')]);

    expect((new RevealPolicy($definition))->apply(revealMatch('mystery', 'v'), new Order, null)->value)->toBeNull();
});

it('asks only the name fields that match by prefix about a name word', function (): void {
    $word = revealMatch('name_word', 'Jan Kowalski');
    $deny = static fn (): bool => false;

    $fuzzyOnlyDenies = revealDefinition([
        SearchField::name('customer_name'),
        SearchField::name('shippingAddress.name')->matches(Mode::Fuzzy)->revealUsing($deny),
    ]);
    $noPrefixName = revealDefinition([SearchField::name('customer_name')->matches(Mode::Fuzzy)]);

    expect((new RevealPolicy($fuzzyOnlyDenies))->apply($word, new Order, null)->value)->toBe('Jan Kowalski')
        ->and((new RevealPolicy($noPrefixName))->apply($word, new Order, null)->value)->toBeNull();
});
