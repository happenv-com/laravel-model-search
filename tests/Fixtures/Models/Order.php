<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Happenv\ModelSearch\Tests\Fixtures\Builders\OrderBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/**
 * @property string $id
 * @property string $signature
 * @property string|null $external_number
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property string $state
 */
final class Order extends Model
{
    /** @use HasUuids<Order> */
    use HasUuids;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = ['purchased_at' => 'datetime'];

    /**
     * @param  Builder  $query
     */
    #[\Override]
    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function marketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id');
    }

    /**
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class, 'order_id');
    }
}
