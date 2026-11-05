<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Searches;

use Closure;
use Happenv\ModelSearch\Definition\ModelSearch;
use Happenv\ModelSearch\Definition\SearchesModel;
use Happenv\ModelSearch\Definition\SearchField;
use Happenv\ModelSearch\Definition\SearchStage;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;

/**
 * A definition whose single stage is supplied by the test; not registered by the fixture provider.
 */
#[SearchesModel(Order::class)]
final class StageProbeSearch extends ModelSearch
{
    public static ?Closure $constraint = null;

    /** @var list<string> */
    public static array $watching = [];

    public function fields(): array
    {
        return [SearchField::key('signature')];
    }

    public function stages(): array
    {
        $constraint = self::$constraint;

        return $constraint === null
            ? [SearchStage::all()]
            : [SearchStage::make('probe', $constraint)->watching(self::$watching), SearchStage::all()];
    }
}
