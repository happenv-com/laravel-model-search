<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Tests\Fixtures\Models\CodedItem;

/**
 * Keys held in lists; the test sets the fields before the definition is compiled.
 */
#[SearchesModel(CodedItem::class)]
final class CodedItemSearch extends ModelSearch
{
    /** @var list<SearchField>|null */
    public static ?array $fields = null;

    public static function reset(): void
    {
        self::$fields = null;
    }

    public function fields(): array
    {
        return self::$fields ?? [SearchField::key('codes')->each()];
    }
}
