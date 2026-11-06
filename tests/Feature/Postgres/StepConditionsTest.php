<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Enums\MarketOrderKind;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ConfigurableOrderSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(PathCompiler::class);

beforeEach(fn () => ConfigurableOrderSearch::reset());
afterEach(fn () => ConfigurableOrderSearch::reset());

/**
 * @param  list<SearchField>  $fields
 */
function scUse(array $fields, string $driver = 'postgres'): ModelSearchRegistry
{
    config(['model-search.driver' => $driver]);
    ConfigurableOrderSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(ConfigurableOrderSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    new SchemaSync($registry)->apply();

    return $registry;
}

function scOrder(string $signature): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert(['id' => $id, 'signature' => $signature]);

    return $id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function scMarketOrder(string $order, string $number, array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('market_orders')->insert(['id' => $id, 'order_id' => $order, 'market_order_number' => $number, ...$attributes]);

    return $id;
}

/**
 * @return list<string> Signatures of the orders found, sorted.
 */
function scFind(string $phrase): array
{
    $signatures = array_map(
        static fn ($result): string => $result->model->signature,
        iterator_to_array(ConfigurableOrderSearch::query($phrase)->within(Order::query())->get()),
    );
    sort($signatures);

    return $signatures;
}

function scField(): SearchField
{
    return SearchField::key('marketOrder.market_order_number')->kind('market');
}

describe('postgres driver', function (): void {
    it('indexes only the related rows that match a scalar condition', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', 'order')]);
        $matching = scOrder('SIG-A');
        $other = scOrder('SIG-B');
        scMarketOrder($matching, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($matching, 'MO-INVOICE', ['kind' => 'invoice']);
        scMarketOrder($other, 'MO-OTHER', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])
            ->and(scFind('MO-INVOICE'))->toBe([])
            ->and(scFind('MO-OTHER'))->toBe([]);
    });

    it('takes the value of a backed enum', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', MarketOrderKind::Order)]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($id, 'MO-INVOICE', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])->and(scFind('MO-INVOICE'))->toBe([]);
    });

    it('takes a list of values as any-of', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', [MarketOrderKind::Order, 'credit'])]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($id, 'MO-CREDIT', ['kind' => 'credit']);
        scMarketOrder($id, 'MO-INVOICE', ['kind' => 'invoice']);
        msIndex();

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])
            ->and(scFind('MO-CREDIT'))->toBe(['SIG-A'])
            ->and(scFind('MO-INVOICE'))->toBe([]);
    });

    it('combines several conditions with AND', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', 'order')->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-BOTH', ['kind' => 'order', 'status' => 'created']);
        scMarketOrder($id, 'MO-KIND', ['kind' => 'order', 'status' => 'cancelled']);
        scMarketOrder($id, 'MO-STATUS', ['kind' => 'invoice', 'status' => 'created']);
        msIndex();

        expect(scFind('MO-BOTH'))->toBe(['SIG-A'])
            ->and(scFind('MO-KIND'))->toBe([])
            ->and(scFind('MO-STATUS'))->toBe([]);
    });

    it('drops a row out of the index when its condition column stops matching, and brings it back', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        $marketOrder = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-A']);

        DB::table('market_orders')->where('id', $marketOrder)->update(['status' => 'cancelled']);
        msIndex();
        expect(scFind('MO-1'))->toBe([]);

        DB::table('market_orders')->where('id', $marketOrder)->update(['status' => 'created']);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-A']);
    });

    it('adds a row that starts matching and ignores a new row that does not', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        $late = scMarketOrder($id, 'MO-LATE', ['status' => 'draft']);
        msIndex();
        expect(scFind('MO-LATE'))->toBe([]);

        DB::table('market_orders')->where('id', $late)->update(['status' => 'created']);
        scMarketOrder($id, 'MO-DRAFT', ['status' => 'draft']);
        scMarketOrder($id, 'MO-NEW-OK', ['status' => 'created']);
        msIndex();

        expect(scFind('MO-LATE'))->toBe(['SIG-A'])
            ->and(scFind('MO-DRAFT'))->toBe([])
            ->and(scFind('MO-NEW-OK'))->toBe(['SIG-A']);
    });

    it('restricts a self step (a belongs to relation) and follows its condition column', function (): void {
        scUse([SearchField::key('signature'), SearchField::key('billingAddress.phone')->kind('phone')->where('billingAddress', 'kind', 'billing')]);
        $address = (string) Str::uuid();
        DB::table('addresses')->insert(['id' => $address, 'phone' => '555-0101', 'kind' => 'billing']);
        $id = scOrder('SIG-A');
        DB::table('orders')->where('id', $id)->update(['billing_address_id' => $address]);
        msIndex();
        expect(scFind('555-0101'))->toBe(['SIG-A']);

        DB::table('addresses')->where('id', $address)->update(['kind' => 'shipping']);
        msIndex();
        expect(scFind('555-0101'))->toBe([]);

        DB::table('addresses')->where('id', $address)->update(['kind' => 'billing']);
        msIndex();
        expect(scFind('555-0101'))->toBe(['SIG-A']);
    });

    it('restricts a step in the middle of a longer path by its dotted name', function (): void {
        scUse([SearchField::key('signature'), SearchField::key('parcels.carrierUnits.tracking_number')->kind('tracking')->where('parcels', 'reference', 'main')]);
        $id = scOrder('SIG-A');
        $main = (string) Str::uuid();
        $side = (string) Str::uuid();
        DB::table('parcels')->insert([['id' => $main, 'order_id' => $id, 'reference' => 'main'], ['id' => $side, 'order_id' => $id, 'reference' => 'side']]);
        DB::table('carrier_units')->insert([['parcel_id' => $main, 'tracking_number' => 'TRK-MAIN'], ['parcel_id' => $side, 'tracking_number' => 'TRK-SIDE']]);
        msIndex();

        expect(scFind('TRK-MAIN'))->toBe(['SIG-A'])->and(scFind('TRK-SIDE'))->toBe([]);

        // The parcel is the deeper node's parent: moving it out of the condition removes its units from the index.
        DB::table('parcels')->where('id', $main)->update(['reference' => 'other']);
        msIndex();
        expect(scFind('TRK-MAIN'))->toBe([]);
    });

    it('logs a change for the row that leaves the condition, and nothing for a row that never matched', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $id = scOrder('SIG-A');
        DB::table(TableNames::forConnection()->changes())->delete();

        $never = scMarketOrder($id, 'MO-NEVER', ['status' => 'draft']);
        DB::table('market_orders')->where('id', $never)->update(['market_order_number' => 'MO-NEVER-2']);
        DB::table('market_orders')->where('id', $never)->delete();
        $logged = static fn (): array => DB::table(TableNames::forConnection()->changes())->pluck('node_id')->all();
        expect($logged())->toBe([]);

        $row = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        DB::table(TableNames::forConnection()->changes())->delete();
        DB::table('market_orders')->where('id', $row)->update(['status' => 'cancelled']);
        expect($logged())->toBe([$id]);
    });

    it('renders the condition into the trigger function', function (): void {
        $registry = scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', ['order', "o'k"])]);

        $sql = collect(new SchemaSync($registry)->plan()->triggers)->map(static fn ($trigger): string => $trigger->functionBody)->implode("\n");

        expect($sql)->toContain('"kind" IN (CAST(\'o\'\'k\' AS text), CAST(\'order\' AS text))');
    });
});

describe('definition', function (): void {
    it('fingerprints the conditions, so changing one rebuilds the type', function (): void {
        $fingerprint = static function (array $fields): string {
            ConfigurableOrderSearch::$fields = $fields;
            $registry = new ModelSearchRegistry;
            $registry->register(ConfigurableOrderSearch::class);

            return Fingerprint::of($registry->for(Order::class));
        };

        $plain = $fingerprint([scField()]);
        $created = $fingerprint([scField()->where('marketOrder', 'status', 'created')]);
        $cancelled = $fingerprint([scField()->where('marketOrder', 'status', 'cancelled')]);
        $list = $fingerprint([scField()->where('marketOrder', 'status', ['created', 'cancelled'])]);

        expect($created)->not->toBe($plain)
            ->and($cancelled)->not->toBe($created)
            ->and($list)->not->toBe($created)
            ->and($fingerprint([scField()->where('marketOrder', 'status', 'created')]))->toBe($created);
    });

    it('refuses a condition on a relation that is not on the path', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'marketOrder.market_order_number', conditions: scField()->where('parcels', 'reference', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'not a relation segment of its path');
    });

    it('refuses a condition on a field without relations', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'signature', conditions: SearchField::key('signature')->where('marketOrder', 'kind', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'the path has no relations');
    });

    it('refuses a column the step table does not have', function (): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'marketOrder.market_order_number', conditions: scField()->where('marketOrder', 'nope', 'x')->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'has no such column');
    });

    it('refuses a condition that is not a scalar, an enum or a list of them', function (mixed $value): void {
        expect(static fn () => scField()->where('marketOrder', 'kind', $value))->toThrow(InvalidSearchDefinition::class);
    })->with([
        'an empty list' => [[]],
        'a list with null' => [['a', null]],
        'a nested list' => [[['a']]],
        'a keyed array' => [['a' => 'b']],
    ]);

    it('refuses a column that is not a plain name', function (): void {
        expect(static fn () => scField()->where('marketOrder', 'kind; drop table x', 'a'))->toThrow(InvalidSearchDefinition::class);
    });

    it('refuses a condition on a via field', function (): void {
        expect(static fn () => SearchField::via('billingAddress')->where('billingAddress', 'kind', 'x'))->toThrow(InvalidSearchDefinition::class);
    });

    it('is reported when the definition is registered', function (): void {
        ConfigurableOrderSearch::$fields = [scField()->where('parcels', 'reference', 'x')];
        $registry = new ModelSearchRegistry;
        $registry->register(ConfigurableOrderSearch::class);

        expect(static fn () => $registry->for(Order::class))->toThrow(InvalidSearchDefinition::class);
    });
});

describe('database driver', function (): void {
    it('reads only the rows that match the condition', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')], 'database');
        $id = scOrder('SIG-A');
        $row = scMarketOrder($id, 'MO-1', ['status' => 'created']);
        scMarketOrder($id, 'MO-2', ['status' => 'cancelled']);

        expect(scFind('MO-1'))->toBe(['SIG-A'])->and(scFind('MO-2'))->toBe([]);

        DB::table('market_orders')->where('id', $row)->update(['status' => 'cancelled']);
        expect(scFind('MO-1'))->toBe([]);
    });

    it('reads enum and list values', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', [MarketOrderKind::Order, 'credit'])], 'database');
        $id = scOrder('SIG-A');
        scMarketOrder($id, 'MO-ORDER', ['kind' => 'order']);
        scMarketOrder($id, 'MO-CREDIT', ['kind' => 'credit']);
        scMarketOrder($id, 'MO-INVOICE', ['kind' => 'invoice']);

        expect(scFind('MO-ORDER'))->toBe(['SIG-A'])->and(scFind('MO-CREDIT'))->toBe(['SIG-A'])->and(scFind('MO-INVOICE'))->toBe([]);

        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'kind', MarketOrderKind::Invoice)], 'database');
        expect(scFind('MO-INVOICE'))->toBe(['SIG-A'])->and(scFind('MO-ORDER'))->toBe([]);
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function scProbe(string $order, string $label, array $attributes = []): string
{
    $id = (string) Str::uuid();
    DB::table('probe_rows')->insert(['id' => $id, 'order_id' => $order, 'label' => $label, ...$attributes]);

    return $id;
}

function scProbeField(): SearchField
{
    return SearchField::key('probeRows.label')->kind('probe');
}

describe('values and column types', function (): void {
    it('refuses a value that does not fit the type of the column', function (string $column, mixed $value): void {
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', $column, $value)->toCompiled(false)->conditions);

        expect($compile)->toThrow(InvalidSearchDefinition::class, 'probeRows');
    })->with([
        'a bool on an integer column' => ['level', true],
        'a word on an integer column' => ['level', 'high'],
        'a number on a boolean column' => ['flag', 1],
        'a word on a boolean column' => ['flag', 'yes'],
        'a word on a float column' => ['score', 'high'],
        'a bool on a text column' => ['label', true],
        'a bad value inside a list' => ['level', [1, 'high']],
    ]);

    it('leaves the transaction of the caller usable after refusing a value', function (): void {
        try {
            new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', 'level', 'high')->toCompiled(false)->conditions);
        } catch (InvalidSearchDefinition) {
        }

        expect(DB::selectOne('select 1 as one')->one)->toBe(1);
    });

    it('matches a boolean, an integer, a float and a list of integers the same way in the projection and the triggers', function (): void {
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'flag', true)]);
        $id = scOrder('SIG-A');
        $on = scProbe($id, 'PR-ON', ['flag' => true]);
        scProbe($id, 'PR-OFF', ['flag' => false]);
        msIndex();
        expect(scFind('PR-ON'))->toBe(['SIG-A'])->and(scFind('PR-OFF'))->toBe([]);
        DB::table('probe_rows')->where('id', $on)->update(['flag' => false]);
        msIndex();
        expect(scFind('PR-ON'))->toBe([]);

        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'level', [2, 3])]);
        $level = scProbe($id, 'PR-LEVEL', ['level' => 2]);
        scProbe($id, 'PR-OTHER', ['level' => 1]);
        msIndex();
        expect(scFind('PR-LEVEL'))->toBe(['SIG-A'])->and(scFind('PR-OTHER'))->toBe([]);
        DB::table('probe_rows')->where('id', $level)->update(['level' => 1]);
        msIndex();
        expect(scFind('PR-LEVEL'))->toBe([]);

        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'score', 1.5)]);
        scProbe($id, 'PR-SCORE', ['score' => 1.5]);
        scProbe($id, 'PR-NOSCORE', ['score' => 2.5]);
        msIndex();
        expect(scFind('PR-SCORE'))->toBe(['SIG-A'])->and(scFind('PR-NOSCORE'))->toBe([]);
    });

    it('does not break writes to the watched table', function (): void {
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'level', 2)]);
        $id = scOrder('SIG-A');

        expect(static fn () => scProbe($id, 'PR-1', ['level' => 7]))->not->toThrow(Throwable::class);
    });

    it('compares text exactly, with the case, in the projection and in the triggers', function (): void {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('ALTER TABLE probe_rows ADD COLUMN ci citext');
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'ci', 'Alpha')]);
        $id = scOrder('SIG-A');
        $row = scProbe($id, 'PR-UPPER', ['ci' => 'Alpha']);
        scProbe($id, 'PR-LOWER', ['ci' => 'alpha']);
        msIndex();
        expect(scFind('PR-UPPER'))->toBe(['SIG-A'])->and(scFind('PR-LOWER'))->toBe([]);

        DB::table('probe_rows')->where('id', $row)->update(['ci' => 'alpha']);
        msIndex();
        expect(scFind('PR-UPPER'))->toBe([]);
    });

    it('checks the labels of a native enum column and indexes by them', function (): void {
        DB::statement("CREATE TYPE probe_mood AS ENUM ('happy', 'sad')");
        DB::statement('ALTER TABLE probe_rows ADD COLUMN mood probe_mood');
        $compile = static fn () => new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', 'mood', 'hapy')->toCompiled(false)->conditions);
        expect($compile)->toThrow(InvalidSearchDefinition::class);

        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'mood', 'happy')]);
        $id = scOrder('SIG-A');
        scProbe($id, 'PR-HAPPY', ['mood' => 'happy']);
        scProbe($id, 'PR-SAD', ['mood' => 'sad']);
        msIndex();
        expect(scFind('PR-HAPPY'))->toBe(['SIG-A'])->and(scFind('PR-SAD'))->toBe([]);
    });

    it('refuses a value that is not finite', function (float $value): void {
        expect(static fn () => scProbeField()->where('probeRows', 'score', $value))->toThrow(InvalidSearchDefinition::class);
    })->with([NAN, INF, -INF]);

    it('reads the same definition whatever the order of the conditions', function (): void {
        $fingerprint = static function (SearchField $field): string {
            ConfigurableOrderSearch::$fields = [$field];
            $registry = new ModelSearchRegistry;
            $registry->register(ConfigurableOrderSearch::class);

            return Fingerprint::of($registry->for(Order::class));
        };

        $a = $fingerprint(scProbeField()->where('probeRows', 'level', 1)->where('probeRows', 'flag', true));
        $b = $fingerprint(scProbeField()->where('probeRows', 'flag', true)->where('probeRows', 'level', 1));
        $list = $fingerprint(scProbeField()->where('probeRows', 'level', [1, 2]));
        $reversed = $fingerprint(scProbeField()->where('probeRows', 'level', [2, 1]));

        expect($a)->toBe($b)->and($list)->toBe($reversed)->and($list)->not->toBe($a);
    });
});

describe('types whose text depends on the session', function (): void {
    it('compares a timestamp with time zone as a point in time, whatever the time zone of the writer', function (): void {
        DB::statement("SET TIME ZONE 'UTC'");
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'at', '2026-01-01 00:00:00+00')]);
        $id = scOrder('SIG-A');
        DB::statement("SET TIME ZONE 'Europe/Warsaw'");
        DB::table(TableNames::forConnection()->changes())->delete();

        $row = scProbe($id, 'PR-AT', ['at' => '2026-01-01 01:00:00+01']);
        scProbe($id, 'PR-LATER', ['at' => '2026-01-01 01:00:00+00']);
        expect(DB::table(TableNames::forConnection()->changes())->pluck('node_id')->all())->toBe([$id]);

        msIndex();
        expect(scFind('PR-AT'))->toBe(['SIG-A'])->and(scFind('PR-LATER'))->toBe([]);

        DB::table('probe_rows')->where('id', $row)->update(['at' => '2026-01-01 03:00:00+01']);
        msIndex();
        expect(scFind('PR-AT'))->toBe([]);
    });

    it('compares a date whatever the DateStyle of the writer', function (): void {
        DB::statement("SET datestyle = 'ISO, MDY'");
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'day', '2026-01-02')]);
        $id = scOrder('SIG-A');
        DB::statement("SET datestyle = 'German, DMY'");

        scProbe($id, 'PR-DAY', ['day' => '02.01.2026']);
        scProbe($id, 'PR-OTHER', ['day' => '01.02.2026']);
        msIndex();

        expect(scFind('PR-DAY'))->toBe(['SIG-A'])->and(scFind('PR-OTHER'))->toBe([]);
    });

    it('matches an unconstrained numeric whatever scale it was written with', function (): void {
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'amount', 1.5)]);
        $id = scOrder('SIG-A');
        scProbe($id, 'PR-SHORT', ['amount' => '1.5']);
        scProbe($id, 'PR-LONG', ['amount' => '1.50']);
        scProbe($id, 'PR-OTHER', ['amount' => '1.6']);
        msIndex();

        expect(scFind('PR-SHORT'))->toBe(['SIG-A'])->and(scFind('PR-LONG'))->toBe(['SIG-A'])->and(scFind('PR-OTHER'))->toBe([]);
    });

    it('follows a domain to its base type: a domain over timestamptz is a point in time', function (): void {
        DB::statement('CREATE DOMAIN probe_instant AS timestamptz');
        DB::statement('ALTER TABLE probe_rows ADD COLUMN moment probe_instant');
        DB::statement("SET TIME ZONE 'UTC'");
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'moment', '2026-01-01 00:00:00+00')]);
        $id = scOrder('SIG-A');
        DB::statement("SET TIME ZONE 'Europe/Warsaw'");
        DB::table(TableNames::forConnection()->changes())->delete();

        scProbe($id, 'PR-MOMENT', ['moment' => '2026-01-01 01:00:00+01']);
        expect(DB::table(TableNames::forConnection()->changes())->pluck('node_id')->all())->toBe([$id]);

        msIndex();
        expect(scFind('PR-MOMENT'))->toBe(['SIG-A']);
    });

    it('follows a domain to its base type: a domain over numeric takes a float and ignores the scale', function (): void {
        DB::statement('CREATE DOMAIN probe_money AS numeric');
        DB::statement('ALTER TABLE probe_rows ADD COLUMN price probe_money');
        scUse([SearchField::key('signature'), scProbeField()->where('probeRows', 'price', 1.5)]);
        $id = scOrder('SIG-A');
        scProbe($id, 'PR-SHORT', ['price' => '1.5']);
        scProbe($id, 'PR-LONG', ['price' => '1.50']);
        scProbe($id, 'PR-OTHER', ['price' => '1.6']);
        msIndex();

        expect(scFind('PR-SHORT'))->toBe(['SIG-A'])->and(scFind('PR-LONG'))->toBe(['SIG-A'])->and(scFind('PR-OTHER'))->toBe([]);
    });

    it('refuses a column whose type has no btree equality', function (string $type, string $value): void {
        DB::statement('ALTER TABLE probe_rows ADD COLUMN odd '.$type);

        expect(static fn () => new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', 'odd', $value)->toCompiled(false)->conditions))
            ->toThrow(InvalidSearchDefinition::class, 'equality');
    })->with([
        'json' => ['json', '{}'],
        'xml' => ['xml', '<a/>'],
        'point' => ['point', '(1,2)'],
    ]);

    it('refuses a timestamp with time zone value that has no offset', function (): void {
        expect(static fn () => new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', 'at', '2026-01-01 00:00:00')->toCompiled(false)->conditions))
            ->toThrow(InvalidSearchDefinition::class, 'offset');
    });

    it('refuses money, whose text depends on lc_monetary', function (): void {
        DB::statement('ALTER TABLE probe_rows ADD COLUMN cash money');

        expect(static fn () => new PathCompiler()->compile(Order::class, 'probeRows.label', conditions: scProbeField()->where('probeRows', 'cash', '1.00')->toCompiled(false)->conditions))
            ->toThrow(InvalidSearchDefinition::class, 'money');
    });
});

describe('moving rows', function (): void {
    it('converges when a related row moves to another parent while its condition flips', function (): void {
        scUse([SearchField::key('signature'), scField()->where('marketOrder', 'status', 'created')]);
        $first = scOrder('SIG-A');
        $second = scOrder('SIG-B');
        $row = scMarketOrder($first, 'MO-1', ['status' => 'created']);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-A']);

        DB::table('market_orders')->where('id', $row)->update(['order_id' => $second, 'status' => 'cancelled']);
        msIndex();
        expect(scFind('MO-1'))->toBe([]);

        DB::table('market_orders')->where('id', $row)->update(['order_id' => $first, 'status' => 'created']);
        DB::table('market_orders')->where('id', $row)->update(['order_id' => $second]);
        msIndex();
        expect(scFind('MO-1'))->toBe(['SIG-B']);
    });
});
