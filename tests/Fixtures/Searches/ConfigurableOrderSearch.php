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
 * An order definition whose moving parts are set by the test before it is compiled.
 */
#[SearchesModel(Order::class)]
final class ConfigurableOrderSearch extends ModelSearch
{
    /** @var list<SearchField>|null */
    public static ?array $fields = null;

    /** @var list<string> */
    public static array $openStates = ['unshipped'];

    public static string $stageLabel = 'Open';

    public static int $definitionVersion = 1;

    public static function reset(): void
    {
        self::$fields = null;
        self::$openStates = ['unshipped'];
        self::$stageLabel = 'Open';
        self::$definitionVersion = 1;
    }

    public function fields(): array
    {
        return self::$fields ?? [
            SearchField::key('signature'),
            SearchField::key('billingAddress.phone')->kind('phone'),
            SearchField::name('customer_name'),
        ];
    }

    public function stages(): array
    {
        $states = self::$openStates;
        $label = self::$stageLabel;

        return [
            SearchStage::make('open', static fn ($query) => $query->whereIn('state', $states))
                ->watching(['state'])
                ->label(Closure::fromCallable(static fn (): string => $label)),
            SearchStage::all(),
        ];
    }

    public function version(): int
    {
        return self::$definitionVersion;
    }
}
