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

    /** @var (Closure(mixed): mixed)|null */
    public static ?Closure $constraint = null;

    /** @var list<string> */
    public static array $watching = ['state'];

    public static string $stageLabel = 'Open';

    public static ?string $sortAt = null;

    public static int $definitionVersion = 1;

    public static ?string $partition = null;

    public static function reset(): void
    {
        self::$fields = null;
        self::$openStates = ['unshipped'];
        self::$constraint = null;
        self::$watching = ['state'];
        self::$stageLabel = 'Open';
        self::$sortAt = null;
        self::$definitionVersion = 1;
        self::$partition = null;
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
        $constraint = self::$constraint ?? static fn ($query) => $query->whereIn('state', $states);

        return [
            SearchStage::make('open', $constraint)
                ->watching(self::$watching)
                ->label(Closure::fromCallable(static fn (): string => $label)),
            SearchStage::all(),
        ];
    }

    public function sortAt(): ?string
    {
        return self::$sortAt;
    }

    public function partition(): ?string
    {
        return self::$partition;
    }

    public function version(): int
    {
        return self::$definitionVersion;
    }
}
