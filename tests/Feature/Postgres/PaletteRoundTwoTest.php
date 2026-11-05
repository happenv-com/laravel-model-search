<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Postgres\Query\RoundPlan;
use Happenv\ModelSearch\Postgres\Query\RoundTwo;
use Happenv\ModelSearch\Postgres\Query\RoundTwoPhase;
use Happenv\ModelSearch\Postgres\Query\TypeRound;
use Happenv\ModelSearch\Query\SearchQuery;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Illuminate\Support\Facades\DB;

covers(RoundTwo::class);

beforeEach(function (): void {
    config(['model-search.driver' => 'postgres', 'model-search.pool' => 3]);
});

afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * The orders of one viewer: the ones with the external number "mine".
 */
function rtMine(string $phrase): SearchQuery
{
    return OrderSearch::query($phrase)->within(Order::query()->where('external_number', 'mine'));
}

describe('the cases that need a second round', function (): void {
    it('stage-zero pool eaten by foreign candidates goes to round two, not to all stages', function (): void {
        foreach (range(1, 8) as $i) {
            Order::create(['signature' => 'ABC-'.$i, 'state' => 'unshipped', 'external_number' => 'theirs']);
        }
        Order::create(['signature' => 'ABC-IN-OPEN', 'state' => 'unshipped', 'external_number' => 'mine']);
        Order::create(['signature' => 'ABC-SHIPPED', 'state' => 'shipped', 'external_number' => 'mine']);
        msIndex();
        $recorder = msRecorder();

        $results = rtMine('ABC')->limit(1)->get();

        expect(msSignatures($results))->toBe(['ABC-IN-OPEN'])
            ->and($results->stage)->toBe('open')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('open')
            ->and(msIndexStatements($recorder))->toHaveCount(2);
    });

    it('goes on to the scoped all-stages branches when the scoped stage zero is empty too', function (): void {
        foreach (range(1, 8) as $i) {
            Order::create(['signature' => 'ABC-'.$i, 'state' => 'unshipped', 'external_number' => 'theirs']);
        }
        Order::create(['signature' => 'ABC-SHIPPED', 'state' => 'shipped', 'external_number' => 'mine']);
        msIndex();
        $recorder = msRecorder();

        $results = rtMine('ABC')->limit(1)->get();

        expect(msSignatures($results))->toBe(['ABC-SHIPPED'])
            ->and($results->stage)->toBe('all')
            ->and(iterator_to_array($results)[0]->match->stage)->toBe('all')
            ->and(msIndexStatements($recorder))->toHaveCount(3);
    });

    it('underfilled and truncated type is completed in round two', function (): void {
        msUse([
            SearchField::key('signature'),
            SearchField::key('external_number'),
            SearchField::key('customer_email'),
            SearchField::key('customer_name')->kind('code'),
        ]);
        // One record owns four keys of the prefix and eats the whole pool of three.
        Order::create(['signature' => 'ABC-1', 'external_number' => 'ABC-2', 'customer_email' => 'abc-3@x.test', 'customer_name' => 'abc4']);
        foreach (range(5, 8) as $i) {
            Order::create(['signature' => 'ABC-'.$i]);
        }
        msIndex();
        $recorder = msRecorder();

        $results = ConfigurableOrderSearch::query('ABC')->within(Order::query())->withoutStages()->limit(5)->get();

        expect(msSignatures($results))->toBe(['ABC-1', 'ABC-5', 'ABC-6', 'ABC-7', 'ABC-8'])
            ->and(msIndexStatements($recorder))->toHaveCount(2);
    });

    it('a typical full number completes in one statement', function (): void {
        Order::create(['signature' => 'OR-9173/A']);
        Order::create(['signature' => 'OR-9173/B']);
        msIndex();
        $recorder = msRecorder();

        $results = OrderSearch::query('OR-9173/A')->within(Order::query())->get();

        expect(msSignatures($results))->toBe(['OR-9173/A'])
            ->and(msIndexStatements($recorder))->toHaveCount(1);
    });

    it('does not start round two for a type that is full or was not truncated', function (): void {
        foreach (range(1, 2) as $i) {
            Order::create(['signature' => 'ABC-'.$i]);
        }
        msIndex();
        $recorder = msRecorder();

        $few = OrderSearch::query('ABC')->within(Order::query())->withoutStages()->get();
        $enough = OrderSearch::query('ABC')->within(Order::query())->withoutStages()->limit(1)->get();

        expect(msSignatures($few))->toHaveCount(2)
            ->and(msSignatures($enough))->toHaveCount(1)
            ->and(msIndexStatements($recorder))->toHaveCount(2);
    });

    it('is skipped after a round-one timeout', function (): void {
        foreach (range(1, 6) as $i) {
            Order::create(['signature' => 'ABC-'.$i, 'state' => 'unshipped', 'external_number' => 'theirs']);
        }
        msIndex();
        config(['model-search.search.timeout_ms' => 50]);
        $recorder = msRecorder();
        $slow = Order::query()->where('external_number', 'mine')->whereRaw('pg_sleep(0.4) is not null');

        $results = OrderSearch::query('ABC')->within($slow)->get();

        expect($results->timedOut)->toBeTrue()
            ->and(iterator_to_array($results))->toBe([])
            ->and($recorder->connections)->toHaveCount(1);
    });

    it('reports a round-two timeout as timedOut and keeps what round one found', function (): void {
        foreach (range(1, 40) as $i) {
            Order::create(['signature' => sprintf('ABC-%02d', $i), 'external_number' => 'mine']);
        }
        msIndex();
        config(['model-search.search.timeout_ms' => 400]);
        // Round one asks the host about a handful of candidates, round two about every key it scans.
        $slow = Order::query()->where('external_number', 'mine')->whereRaw('pg_sleep(0.03) is not null');

        $results = OrderSearch::query('ABC')->within($slow)->withoutStages()->get();

        expect($results->timedOut)->toBeTrue()
            ->and(count($results))->toBeGreaterThan(0);
    });

    it('keeps the phrase off the host connection in round two as well', function (): void {
        foreach (range(1, 6) as $i) {
            Order::create(['signature' => 'SECRETX-'.$i, 'state' => 'unshipped', 'external_number' => 'theirs']);
        }
        Order::create(['signature' => 'SECRETX-IN', 'state' => 'unshipped', 'external_number' => 'mine']);
        msIndex();
        $heard = [];
        DB::listen(static function ($query) use (&$heard): void {
            $heard[] = $query->sql.json_encode($query->bindings);
        });

        $results = rtMine('SECRETX')->get();

        expect(msSignatures($results))->toBe(['SECRETX-IN'])
            ->and(stripos(implode('', $heard), 'secretx'))->toBeFalse()
            ->and($heard)->not->toBe([]);
    });
});

describe('the decision', function (): void {
    function rtRound(int $passed, int $nonExactInStageZero, bool $stageZeroFull, bool $anyFull, bool $allStagesFull, bool $viaPoolFull, int $limit = 5): TypeRound
    {
        return new TypeRound([], $passed, $nonExactInStageZero, $stageZeroFull, $anyFull, $allStagesFull, $viaPoolFull, $limit);
    }

    function rtTarget(string $phrase = 'ABC', bool $withoutStages = false): array
    {
        $query = OrderSearch::query($phrase)->within(Order::query());
        $query = $withoutStages ? $query->withoutStages() : $query;

        return ['order' => ['definition' => app(ModelSearchRegistry::class)->for(Order::class), 'query' => $query, 'host' => $query->scope()]];
    }

    it('picks the phase of each case', function (): void {
        $targets = rtTarget();
        $plain = rtTarget('ABC', withoutStages: true);

        expect(RoundTwo::phases($targets, ['order' => rtRound(0, 0, true, true, false, false)]))->toBe(['order' => RoundTwoPhase::StageZero])
            ->and(RoundTwo::phases($targets, ['order' => rtRound(0, 0, false, false, true, false)]))->toBe(['order' => RoundTwoPhase::AllStages])
            ->and(RoundTwo::phases($targets, ['order' => rtRound(2, 2, false, true, false, false)]))->toBe(['order' => RoundTwoPhase::StageZero])
            ->and(RoundTwo::phases($targets, ['order' => rtRound(0, 0, false, false, false, true)]))->toBe(['order' => RoundTwoPhase::AllStages])
            ->and(RoundTwo::phases($plain, ['order' => rtRound(0, 0, false, true, false, false)]))->toBe(['order' => RoundTwoPhase::Unstaged])
            ->and(RoundTwo::phases($targets, ['order' => rtRound(0, 0, false, false, false, false)]))->toBe([])
            ->and(RoundTwo::phases($targets, ['order' => rtRound(5, 0, true, true, true, true)]))->toBe([]);
    });

    it('reads the stage zero branches for a type whose first stage answered, all stages for one it did not', function (): void {
        $targets = rtTarget();
        $sql = static fn (RoundTwoPhase $phase): string => RoundTwo::sql($targets, ['order' => $phase])['sql'];

        expect($sql(RoundTwoPhase::StageZero))->toContain('AND k.stage < 32767')
            ->and($sql(RoundTwoPhase::AllStages))->not->toContain('AND k.stage < 32767')
            ->and($sql(RoundTwoPhase::StageZero))->toContain('JOIN LATERAL (SELECT 1 FROM (')
            ->and($sql(RoundTwoPhase::StageZero))->not->toContain('WHERE EXISTS (SELECT 1 FROM (');
    });

    it('limits every scoped branch to a few keys per record of the limit', function (): void {
        $plan = RoundPlan::scoped(5, RoundTwoPhase::AllStages);

        expect($plan->pool + 1)->toBe(5 * RoundTwo::KEYS_PER_RECORD)
            ->and($plan->scoped)->toBeTrue()
            ->and($plan->filtersStage)->toBeFalse();
    });

    it('runs nothing for a phrase that normalizes to nothing', function (): void {
        expect(RoundTwo::sql(rtTarget('日本語'), ['order' => RoundTwoPhase::StageZero]))->toBeNull();
    });
});

describe('via', function (): void {
    it('lets children in scope through when children out of scope ate the pool', function (): void {
        foreach (range(1, 8) as $i) {
            $order = Order::create(['signature' => 'ORD-0'.$i]);
            Invoice::create(['number' => 'FV-OUT-'.$i, 'order_id' => $order->id]);
        }
        $inScope = Order::create(['signature' => 'ORD-09']);
        Invoice::create(['number' => 'FV-IN', 'order_id' => $inScope->id, 'external_reference' => 'mine']);
        msIndex();
        $recorder = msRecorder();

        $results = InvoiceSearch::query('ORD')->within(Invoice::query()->where('external_reference', 'mine'), [Order::class => Order::query()])->limit(1)->get();

        expect(array_map(static fn ($r): string => $r->model->number, iterator_to_array($results)))->toBe(['FV-IN'])
            ->and(msIndexStatements($recorder))->toHaveCount(2);
    });
});
