<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures;

use Happenv\ModelSearch\Facades\ModelSearches;
use Happenv\ModelSearch\Tests\Fixtures\Models\Address;
use Happenv\ModelSearch\Tests\Fixtures\Models\CarrierUnit;
use Happenv\ModelSearch\Tests\Fixtures\Models\CodedItem;
use Happenv\ModelSearch\Tests\Fixtures\Models\Customer;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicInvoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\DynamicOrder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Invoice;
use Happenv\ModelSearch\Tests\Fixtures\Models\MarketOrder;
use Happenv\ModelSearch\Tests\Fixtures\Models\Order;
use Happenv\ModelSearch\Tests\Fixtures\Models\Parcel;
use Happenv\ModelSearch\Tests\Fixtures\Models\Product;
use Happenv\ModelSearch\Tests\Fixtures\Searches\CustomerSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\InvoiceSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\MarketOrderSearchExtension;
use Happenv\ModelSearch\Tests\Fixtures\Searches\OrderSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ParcelSearch;
use Happenv\ModelSearch\Tests\Fixtures\Searches\ProductSearch;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the fixture search definitions before the test migrations run.
 */
final class FixtureSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap([
            'order' => Order::class,
            'address' => Address::class,
            'market_order' => MarketOrder::class,
            'parcel' => Parcel::class,
            'carrier_unit' => CarrierUnit::class,
            'product' => Product::class,
            'invoice' => Invoice::class,
            'customer' => Customer::class,
            'coded_item' => CodedItem::class,
            'dynamic_order' => DynamicOrder::class,
            'dynamic_invoice' => DynamicInvoice::class,
        ]);

        ModelSearches::register(OrderSearch::class);
        ModelSearches::register(ParcelSearch::class);
        ModelSearches::register(ProductSearch::class);
        ModelSearches::register(InvoiceSearch::class);
        ModelSearches::register(CustomerSearch::class);
        ModelSearches::extend(MarketOrderSearchExtension::class);
    }
}
