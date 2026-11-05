<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Definition\CompiledDefinition;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Exceptions\InvalidStageConstraint;
use Happenv\ModelSearch\Normalization\Normalizer;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Enums\OrderState;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Scopes\NamedAddressScope;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;

covers(Fingerprint::class);

beforeEach(fn () => ConfigurableOrderSearch::reset());
afterEach(fn () => ConfigurableOrderSearch::reset());

function fingerprintOfConfigurableOrder(): string
{
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    return Fingerprint::of($registry->for(Order::class));
}

function normalizerOfVersion(int $version): Normalizer
{
    return new readonly class($version) implements Normalizer
    {
        public function __construct(private int $version) {}

        public function normalize(string $value): string
        {
            return strtolower($value);
        }

        public function version(): int
        {
            return $this->version;
        }
    };
}

it('is a stable sha256 of the definition', function (): void {
    expect(fingerprintOfConfigurableOrder())->toMatch('/^[0-9a-f]{64}$/')
        ->and(fingerprintOfConfigurableOrder())->toBe(fingerprintOfConfigurableOrder());
});

it('changes when the stage logic changes behind the same watched columns', function (): void {
    $before = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$openStates = ['unshipped', 'partially_shipped'];

    expect(fingerprintOfConfigurableOrder())->not->toBe($before);
});

it('changes when the normalizer version changes', function (): void {
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(normalizerOfVersion(1))];
    $before = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->normalizeUsing(normalizerOfVersion(2))];

    expect(fingerprintOfConfigurableOrder())->not->toBe($before);
});

it('changes when the name word rules change', function (): void {
    $before = fingerprintOfConfigurableOrder();
    config(['model-search.name_words.min_length' => 3]);
    $minLength = fingerprintOfConfigurableOrder();
    config(['model-search.name_words.max_per_record' => 5]);

    expect($minLength)->not->toBe($before)
        ->and(fingerprintOfConfigurableOrder())->not->toBe($minLength);
});

it('does not change when a label changes', function (): void {
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->label(static fn (): string => 'One')];
    $before = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$fields = [SearchField::key('signature')->label(static fn (): string => 'Two')];
    $field = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$stageLabel = 'Renamed';

    expect($field)->toBe($before)
        ->and(fingerprintOfConfigurableOrder())->toBe($before);
});

it('changes when version() is bumped', function (): void {
    $before = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$definitionVersion = 2;

    expect(fingerprintOfConfigurableOrder())->not->toBe($before);
});

it('changes when a field path, kind, modes or translatability change', function (): void {
    $variants = [
        [SearchField::key('signature')],
        [SearchField::key('external_number')->kind('signature')],
        [SearchField::key('signature')->kind('number')],
        [SearchField::key('signature')->matches(Mode::Exact)],
        [SearchField::name('signature')],
        [SearchField::name('signature')->translatable()],
    ];
    $seen = [];

    foreach ($variants as $fields) {
        ConfigurableOrderSearch::$fields = $fields;
        $seen[] = fingerprintOfConfigurableOrder();
    }

    expect(array_unique($seen))->toHaveCount(count($variants));
});

it('is identical across two compilations of the same definition', function (): void {
    expect(fingerprintOfConfigurableOrder())->toBe(fingerprintOfConfigurableOrder());
});

it('canonicalises backed enum bindings to their values', function (): void {
    $before = fingerprintOfConfigurableOrder();
    ConfigurableOrderSearch::$constraint = static fn ($query) => $query->whereIn('state', [OrderState::Unshipped]);

    expect(fingerprintOfConfigurableOrder())->toBe($before);
});

it('refuses to hash a stage binding that is not a scalar, null or enum', function (): void {
    $definition = new CompiledDefinition(
        Order::class,
        'order',
        'uuid',
        [SearchField::key('signature')->toCompiled(false)],
        [SearchStage::make('recent', static fn ($query) => $query->where('purchased_at', '>=', now()))->watching(['purchased_at']), SearchStage::all()],
        null,
        null,
    );

    Fingerprint::of($definition);
})->throws(InvalidStageConstraint::class);

it('canonicalises the bindings of a listed scope', function (): void {
    $compiler = new PathCompiler;
    $definition = static fn (): CompiledDefinition => new CompiledDefinition(
        Order::class,
        'order',
        'uuid',
        [SearchField::key('scopedBillingAddress.phone')->toCompiled(false)->withCompiledPath($compiler->compile(Order::class, 'scopedBillingAddress.phone', false, [NamedAddressScope::class]))],
        [SearchStage::all()],
        null,
        null,
    );

    expect(Fingerprint::of($definition()))->toBe(Fingerprint::of($definition()));
});
