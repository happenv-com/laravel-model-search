<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\FieldType;
use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\ModelSearchExtension;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Exceptions\DuplicateDefinition;
use Happenv\ModelSearch\Exceptions\DuplicateKind;
use Happenv\ModelSearch\Exceptions\ExtensionWithoutDefinition;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Exceptions\InvalidStageConstraint;
use Happenv\ModelSearch\Exceptions\UnknownDefinition;
use Happenv\ModelSearch\Exceptions\UnsupportedRelation;
use Happenv\ModelSearch\Exceptions\UnsupportedStages;
use Happenv\ModelSearch\Facades\ModelSearch as ModelSearchFacade;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Builders\OrderBuilder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Customer;
use Happenv\ModelSearch\Tests\Fixtures\Models\DatedRecord;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\DatedRecordSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\HasManyViaOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\MarketOrderSearchExtension;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\StageProbeSearch;
use Illuminate\Support\Facades\DB;

covers(ModelSearchRegistry::class);

it('compiles owner fields with extension fields', function (): void {
    $definition = ModelSearchFacade::for(Order::class);

    expect($definition->modelClass)->toBe(Order::class)
        ->and($definition->morphAlias)->toBe('order')
        ->and($definition->keyType)->toBe('uuid')
        ->and($definition->sortAt)->toBe('purchased_at')
        ->and(array_map(static fn ($field): string => $field->kind, $definition->fields))->toBe([
            'signature', 'external_number', 'customer_email', 'phone', 'customer_name', 'shipping_name', 'market_order_number',
        ])
        ->and($definition->fields[6]->declaredByExtension)->toBeTrue()
        ->and($definition->fields[0]->declaredByExtension)->toBeFalse()
        ->and($definition->hasNames())->toBeTrue()
        ->and($definition->hasStages())->toBeTrue()
        ->and(array_map(static fn ($stage): string => $stage->key, $definition->stages))->toBe(['open', 'all']);
});

it('derives the key type from the model', function (): void {
    expect(ModelSearchFacade::for(Product::class)->keyType)->toBe('bigint')
        ->and(ModelSearchFacade::for(Customer::class)->keyType)->toBe('bigint')
        ->and(ModelSearchFacade::for(Parcel::class)->keyType)->toBe('uuid')
        ->and(ModelSearchFacade::for(Invoice::class)->keyType)->toBe('uuid')
        ->and(ModelSearchFacade::for(Product::class)->hasStages())->toBeFalse()
        ->and(ModelSearchFacade::for(Parcel::class)->hasNames())->toBeFalse();
});

it('keeps via fields on the compiled definition', function (): void {
    $via = array_values(array_filter(
        ModelSearchFacade::for(Parcel::class)->fields,
        static fn ($field): bool => $field->type === FieldType::Via,
    ));

    expect($via)->toHaveCount(1)
        ->and($via[0]->path)->toBe('order')
        ->and($via[0]->via)->toBe(['signature', 'market_order_number']);
});

it('caches the compiled definition per process and recompiles after a registration', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);

    $first = $registry->for(Order::class);

    expect($registry->for(Order::class))->toBe($first);

    $registry->extend(MarketOrderSearchExtension::class);

    expect($registry->for(Order::class))->not->toBe($first)
        ->and($registry->for(Order::class)->fields)->toHaveCount(7);
});

it('lists every compiled definition', function (): void {
    expect(array_keys(ModelSearchFacade::all()))->toEqualCanonicalizing([
        Order::class, Parcel::class, Product::class, Invoice::class, Customer::class,
    ]);
});

it('rejects a duplicated kind within one composed definition', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);
    $registry->extend(new class extends ModelSearchExtension
    {
        public function model(): string
        {
            return Order::class;
        }

        public function fields(): array
        {
            return [SearchField::key('marketOrder.signature')];
        }
    }::class);

    $registry->for(Order::class);
})->throws(DuplicateKind::class, '"signature"');

it('allows the same kind on different models', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('signature')->kind('number')];
        }
    }::class);
    $registry->register(new #[SearchesModel(Invoice::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('number')];
        }
    }::class);

    expect($registry->for(Order::class)->fields[0]->kind)->toBe('number')
        ->and($registry->for(Invoice::class)->fields[0]->kind)->toBe('number');
});

it('rejects a second definition for the same model', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('signature')];
        }
    }::class);
})->throws(DuplicateDefinition::class);

it('treats registering the same definition twice as a no-op', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(OrderSearch::class);
    $registry->register(OrderSearch::class);

    expect($registry->all())->toHaveCount(1);
});

it('rejects an extension whose model has no definition at compile time', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->extend(MarketOrderSearchExtension::class);

    expect(static fn () => $registry->all())->toThrow(ExtensionWithoutDefinition::class)
        ->and(static fn () => $registry->for(Order::class))->toThrow(ExtensionWithoutDefinition::class);
});

it('is indifferent to the order of registration', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->extend(MarketOrderSearchExtension::class);
    $registry->register(OrderSearch::class);

    expect($registry->for(Order::class)->fields)->toHaveCount(7);
});

it('rejects a model without a definition', function (): void {
    (new ModelSearchRegistry)->for(Order::class);
})->throws(UnknownDefinition::class);

it('rejects a definition class without the attribute or the base class', function (): void {
    $registry = new ModelSearchRegistry;

    expect(static fn () => $registry->register(stdClass::class))->toThrow(InvalidSearchDefinition::class)
        ->and(static fn () => $registry->register(new class extends ModelSearch
        {
            public function fields(): array
            {
                return [];
            }
        }::class))->toThrow(InvalidSearchDefinition::class, '#[SearchesModel]');
});

it('rejects a definition without fields', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [];
        }
    }::class);

    $registry->for(Order::class);
})->throws(InvalidSearchDefinition::class, 'no fields');

it('rejects stage constraints touching another table or undeclared columns', function (Closure $constraint, array $watching, string $fragment): void {
    StageProbeSearch::$constraint = $constraint;
    StageProbeSearch::$watching = $watching;

    try {
        $registry = new ModelSearchRegistry;
        $registry->register(StageProbeSearch::class);

        expect(static fn () => $registry->for(Order::class))->toThrow(InvalidStageConstraint::class, $fragment);
    } finally {
        StageProbeSearch::$constraint = null;
        StageProbeSearch::$watching = [];
    }
})->with([
    'undeclared column' => [static fn (OrderBuilder $q) => $q->where('state', 'unshipped'), [], 'not declared'],
    'another column undeclared' => [static fn (OrderBuilder $q) => $q->where('state', 'unshipped')->where('customer_email', 'x'), ['state'], '"customer_email" is not declared'],
    'other table column' => [static fn (OrderBuilder $q) => $q->where('parcels.reference', 'x'), ['reference'], 'not the model\'s own table'],
    'relation existence' => [static fn (OrderBuilder $q) => $q->whereHas('parcels'), ['state'], 'subquery'],
    'join' => [static fn (OrderBuilder $q) => $q->join('parcels', 'parcels.order_id', '=', 'orders.id'), ['state'], 'joins'],
    'raw sql' => [static fn (OrderBuilder $q) => $q->whereRaw('state = ?', ['x']), ['state'], 'raw SQL'],
    'nested undeclared' => [static fn (OrderBuilder $q) => $q->where(static fn ($n) => $n->where('state', 'a')->orWhere('signature', 'b')), ['state'], '"signature" is not declared'],
    'whereIn subquery' => [static fn (OrderBuilder $q) => $q->whereIn('state', Parcel::query()->select('reference')), ['state'], 'another table'],
    'whereIn raw expression' => [static fn (OrderBuilder $q) => $q->whereIn('state', [DB::raw('(select reference from parcels)')]), ['state'], 'another table'],
    'basic raw subquery value' => [static fn (OrderBuilder $q) => $q->where('state', '=', DB::raw('(select reference from parcels limit 1)')), ['state'], 'another table'],
    'basic eloquent builder value' => [static fn (OrderBuilder $q) => $q->where('state', '=', Parcel::query()->select('reference')->limit(1)), ['state'], 'another table'],
    'other from' => [static fn (OrderBuilder $q) => $q->from('parcels')->where('state', 'a'), ['state'], 'reads from'],
    'having' => [static fn (OrderBuilder $q) => $q->where('state', 'a')->groupBy('state')->having('state', '=', 'a'), ['state'], 'having'],
    'limit' => [static fn (OrderBuilder $q) => $q->where('state', 'a')->limit(10), ['state'], 'limit'],
    'offset' => [static fn (OrderBuilder $q) => $q->where('state', 'a')->offset(10), ['state'], 'offset'],
]);

it('accepts a stage constraint over declared columns of the own table', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('signature')];
        }

        public function stages(): array
        {
            return [
                SearchStage::make('open', static fn (OrderBuilder $q) => $q->where(static fn ($n) => $n->where('orders.state', 'a')->orWhereNull('customer_email')))
                    ->watching(['state', 'customer_email']),
            ];
        }
    }::class);

    expect(array_map(static fn ($stage): string => $stage->key, $registry->for(Order::class)->stages))->toBe(['open', 'all']);
});

it('rejects more than one stage besides all', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('signature')];
        }

        public function stages(): array
        {
            return [
                SearchStage::make('open', static fn (OrderBuilder $q) => $q->where('state', 'a'))->watching(['state']),
                SearchStage::make('recent', static fn (OrderBuilder $q) => $q->where('state', 'b'))->watching(['state']),
                SearchStage::all(),
            ];
        }
    }::class);

    $registry->for(Order::class);
})->throws(UnsupportedStages::class);

it('requires the all stage to come last', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(new #[SearchesModel(Order::class)] class extends ModelSearch
    {
        public function fields(): array
        {
            return [SearchField::key('signature')];
        }

        public function stages(): array
        {
            return [SearchStage::all(), SearchStage::make('open', static fn (OrderBuilder $q) => $q->where('state', 'a'))->watching(['state'])];
        }
    }::class);

    $registry->for(Order::class);
})->throws(UnsupportedStages::class);

it('rejects a stage that depends on the current time', function (): void {
    ConfigurableOrderSearch::$constraint = static fn ($query) => $query->where('purchased_at', '>=', now()->subDays(90));
    ConfigurableOrderSearch::$watching = ['purchased_at'];
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    try {
        $registry->for(Order::class);
    } finally {
        ConfigurableOrderSearch::reset();
    }
})->throws(InvalidStageConstraint::class, 'time');

it('rejects a stage that binds an object', function (): void {
    ConfigurableOrderSearch::$constraint = static fn ($query) => $query->where('state', new stdClass);
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);

    try {
        $registry->for(Order::class);
    } finally {
        ConfigurableOrderSearch::reset();
    }
})->throws(InvalidStageConstraint::class);

it('refuses a via field whose relation cannot be served', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(HasManyViaOrderSearch::class);

    expect(fn () => $registry->for(Order::class))->toThrow(UnsupportedRelation::class, 'BelongsTo');
});

it('refuses a key type a list of keys cannot narrow by', function (): void {
    $registry = new ModelSearchRegistry;
    $registry->register(DatedRecordSearch::class);

    expect(fn () => $registry->for(DatedRecord::class))->toThrow(InvalidSearchDefinition::class, 'date');
});
