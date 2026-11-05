<?php

declare(strict_types=1);

use Happenv\ModelSearch\Contracts\NullSearchTermObserver;
use Happenv\ModelSearch\Contracts\SearchTermObserver;
use Happenv\ModelSearch\Events\SearchPerformed;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\Event;

covers(SearchQuery::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'database']);
});

it('SearchPerformed carries no term', function (): void {
    Order::create(['signature' => 'SECRET-77', 'state' => 'unshipped']);
    Event::fake([SearchPerformed::class]);

    OrderSearch::query('SECRET-77')->within(Order::query())->get();

    Event::assertDispatched(SearchPerformed::class, static function (SearchPerformed $event): bool {
        $properties = array_map(static fn (ReflectionProperty $property): string => $property->getName(), new ReflectionClass($event)->getProperties());

        return $event->definition === 'order'
            && $event->resultCount === 1
            && $event->timedOut === false
            && $event->durationMs >= 0
            && $properties === ['definition', 'durationMs', 'resultCount', 'timedOut']
            && ! str_contains(json_encode($event, JSON_THROW_ON_ERROR), 'SECRET');
    });
});

it('reports similar and candidates too', function (): void {
    Event::fake([SearchPerformed::class]);

    OrderSearch::query('Kowalska')->within(Order::query())->similar();
    OrderSearch::query('ABC-1')->withoutScope('test')->candidates();

    Event::assertDispatchedTimes(SearchPerformed::class, 2);
});

it('term observer sees every term', function (): void {
    $observer = new class implements SearchTermObserver
    {
        /** @var list<string> */
        public array $terms = [];

        #[Override]
        public function observe(#[SensitiveParameter] string $term): void
        {
            $this->terms[] = $term;
        }
    };
    app()->instance(SearchTermObserver::class, $observer);

    OrderSearch::query('first')->within(Order::query())->get();
    OrderSearch::query('second')->within(Order::query())->similar();
    OrderSearch::query('third')->within(Order::query())->apply(Order::query());
    OrderSearch::query('fourth')->withoutScope('test')->candidates();

    expect($observer->terms)->toBe(['first', 'second', 'third', 'fourth']);
});

it('observes nothing by default', function (): void {
    expect(resolve(SearchTermObserver::class))->toBeInstanceOf(NullSearchTermObserver::class);
});

it('reports apply with the number of ids found', function (): void {
    Order::create(['signature' => 'APPLY-1', 'state' => 'unshipped']);
    Order::create(['signature' => 'APPLY-2', 'state' => 'unshipped']);
    Event::fake([SearchPerformed::class]);

    OrderSearch::query('APPLY')->within(Order::query())->apply(Order::query());

    Event::assertDispatched(SearchPerformed::class, static fn (SearchPerformed $event): bool => $event->resultCount === 2);
});
