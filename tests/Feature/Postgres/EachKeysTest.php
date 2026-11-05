<?php

declare(strict_types=1);

use Happenv\ModelSearch\Compilation\Fingerprint;
use Happenv\ModelSearch\Compilation\PathCompiler;
use Happenv\ModelSearch\Definition\Mode;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Exceptions\InvalidSearchDefinition;
use Happenv\ModelSearch\Postgres\Schema\SchemaSync;
use Happenv\ModelSearch\Postgres\Schema\TableNames;
use Happenv\ModelSearch\Registry\ModelSearchRegistry;
use Happenv\ModelSearch\Tests\Fixtures\Models\CodedItem;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CodedItemSearch;
use Illuminate\Support\Facades\DB;

covers(PathCompiler::class);

afterEach(function (): void {
    CodedItemSearch::reset();
});

/**
 * @param  list<SearchField>|null  $fields
 */
function ekUse(?array $fields = null): ModelSearchRegistry
{
    CodedItemSearch::$fields = $fields;
    $registry = new ModelSearchRegistry;
    $registry->register(CodedItemSearch::class);
    app()->instance(ModelSearchRegistry::class, $registry);
    DB::table(TableNames::forConnection()->state())->delete();
    new SchemaSync($registry)->apply();

    return $registry;
}

/**
 * @param  list<mixed>|null  $codes
 */
function ekItem(string $label, ?array $codes, string $column = 'codes'): int
{
    $value = match (true) {
        $codes === null => null,
        $column === 'tag_codes' => '{'.implode(',', array_map(static fn (mixed $code): string => '"'.$code.'"', $codes)).'}',
        default => json_encode($codes, JSON_THROW_ON_ERROR),
    };

    return DB::table('coded_items')->insertGetId(['label' => $label, $column => $value]);
}

/**
 * @return list<string> Labels found, sorted.
 */
function ekFind(string $phrase, ?Mode $mode = null): array
{
    $query = CodedItemSearch::query($phrase)->within(CodedItem::query());
    $labels = array_map(
        static fn ($result): string => $result->model->label,
        iterator_to_array(($mode === null ? $query : $query->using($mode))->get()),
    );
    sort($labels);

    return $labels;
}

/**
 * @return list<string> The normalised keys of one record.
 */
function ekKeys(int $id): array
{
    return DB::table(TableNames::forConnection()->keys())->where('subject_id', (string) $id)->orderBy('key')->pluck('key')->all();
}

describe('postgres driver', function (): void {
    it('finds a record by its first, middle and last element in every mode', function (): void {
        ekUse();
        ekItem('three', ['AAA-111', 'BBB-222', 'CCC-333']);
        ekItem('other', ['ZZZ-999']);
        msIndex();

        foreach ([['AAA-111', 'AAA'], ['BBB-222', 'BBB'], ['CCC-333', 'CCC']] as [$whole, $start]) {
            expect(ekFind($whole, Mode::Exact))->toBe(['three'])
                ->and(ekFind($start, Mode::Prefix))->toBe(['three']);
        }

        expect(ekFind('A111', Mode::Suffix))->toBe(['three'])
            ->and(ekFind('B222', Mode::Suffix))->toBe(['three'])
            ->and(ekFind('C333', Mode::Suffix))->toBe(['three'])
            ->and(ekFind('AAA-222', Mode::Exact))->toBe([]);
    });

    it('stores one key row per element and drops an element that left the array after a drain', function (): void {
        ekUse();
        $id = ekItem('three', ['AAA-111', 'BBB-222', 'CCC-333']);
        msIndex();

        expect(ekKeys($id))->toBe(['aaa111', 'bbb222', 'ccc333']);

        DB::table('coded_items')->where('id', $id)->update(['codes' => json_encode(['AAA-111', 'CCC-333'])]);
        msIndex();

        expect(ekKeys($id))->toBe(['aaa111', 'ccc333'])
            ->and(ekFind('BBB-222', Mode::Exact))->toBe([])
            ->and(ekFind('CCC-333', Mode::Exact))->toBe(['three']);
    });

    it('gives no keys for an empty array or null', function (): void {
        ekUse();
        $empty = ekItem('empty', []);
        $null = ekItem('null', null);
        msIndex();

        expect(ekKeys($empty))->toBe([])
            ->and(ekKeys($null))->toBe([]);
    });

    it('keeps strings and numbers, and ignores null, booleans, objects, nested lists and blanks', function (): void {
        ekUse();
        $id = ekItem('mixed', ['x y-1', 123, null, true, ['a'], ['k' => 'v'], '', '  ', 4.5]);
        msIndex();

        expect(ekKeys($id))->toBe(['123', '45', 'xy1']);
    });

    it('reads json columns and native text arrays the same way', function (): void {
        ekUse([SearchField::key('raw_codes')->each(), SearchField::key('tag_codes')->each()]);
        $json = ekItem('from json', ['J-1', 'J-2'], 'raw_codes');
        $array = ekItem('from array', ['A-1', 'A-2'], 'tag_codes');
        msIndex();

        expect(ekKeys($json))->toBe(['j1', 'j2'])
            ->and(ekKeys($array))->toBe(['a1', 'a2'])
            ->and(ekFind('J-2', Mode::Exact))->toBe(['from json'])
            ->and(ekFind('A-2', Mode::Exact))->toBe(['from array']);

        DB::table('coded_items')->where('id', $array)->update(['tag_codes' => '{"A-1"}']);
        msIndex();

        expect(ekKeys($array))->toBe(['a1']);
    });

    it('ignores the elements beyond the cap, in array order, and the cap is part of the fingerprint', function (): void {
        $without = Fingerprint::of(ekUse()->for(CodedItem::class));
        config(['model-search.each.max_per_record' => 2]);
        $with = Fingerprint::of(ekUse()->for(CodedItem::class));
        $id = ekItem('long', ['Z-1', 'A-2', 'M-3']);
        msIndex();

        expect(ekKeys($id))->toBe(['a2', 'z1'])
            ->and($with)->not->toBe($without);
    });
});

it('makes each() part of the fingerprint', function (): void {
    $plain = Fingerprint::of(ekUse([SearchField::key('codes')])->for(CodedItem::class));
    $list = Fingerprint::of(ekUse([SearchField::key('codes')->each()])->for(CodedItem::class));

    expect($list)->not->toBe($plain);
});

describe('database driver', function (): void {
    beforeEach(function (): void {
        config(['model-search.driver' => 'database']);
    });

    it('finds a record by any element', function (): void {
        ekUse();
        ekItem('three', ['AAA-111', 'BBB-222', 'CCC-333']);
        ekItem('other', ['ZZZ-999']);

        expect(ekFind('AAA-111', Mode::Exact))->toBe(['three'])
            ->and(ekFind('BBB 222', Mode::Exact))->toBe(['three'])
            ->and(ekFind('CCC-333', Mode::Exact))->toBe(['three'])
            ->and(ekFind('DDD-444', Mode::Exact))->toBe([])
            ->and(ekFind('BBB', Mode::Prefix))->toBe(['three'])
            ->and(ekFind('C333', Mode::Suffix))->toBe(['three']);
    });

    it('answers the same for a native array and honours the cap', function (): void {
        config(['model-search.each.max_per_record' => 1]);
        ekUse([SearchField::key('tag_codes')->each()]);
        ekItem('arr', ['A-1', 'A-2'], 'tag_codes');

        expect(ekFind('A-1', Mode::Exact))->toBe(['arr'])
            ->and(ekFind('A-2', Mode::Exact))->toBe([]);
    });
});

describe('definition', function (): void {
    it('refuses a text column, which holds no list', function (): void {
        ekUse([SearchField::key('plain')->each()]);
    })->throws(InvalidSearchDefinition::class, 'list');

    it('refuses each() on a translatable field', function (): void {
        ekUse([SearchField::key('codes')->translatable()->each()]);
    })->throws(InvalidSearchDefinition::class, 'translatable');
});
