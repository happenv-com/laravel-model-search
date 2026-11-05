<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Happenv\ModelSearch\Tests\Fixtures\Builders\OrderBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    /**
     * @return BelongsTo<ScopedAddress, $this>
     */
    public function scopedBillingAddress(): BelongsTo
    {
        return $this->belongsTo(ScopedAddress::class, 'billing_address_id');
    }

    /**
     * A genuine one-to-one: the carrier unit carries the order's key.
     *
     * @return HasOne<CarrierUnit, $this>
     */
    public function carrierUnit(): HasOne
    {
        return $this->hasOne(CarrierUnit::class, 'order_id');
    }

    /**
     * @return HasOneThrough<CarrierUnit, Parcel, $this>
     */
    public function parcelCarrierUnit(): HasOneThrough
    {
        return $this->hasOneThrough(CarrierUnit::class, Parcel::class, 'order_id', 'parcel_id');
    }

    /**
     * @return HasManyThrough<CarrierUnit, Parcel, $this>
     */
    public function parcelCarrierUnits(): HasManyThrough
    {
        return $this->hasManyThrough(CarrierUnit::class, Parcel::class, 'order_id', 'parcel_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'order_tag', 'order_id', 'tag_id');
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    /**
     * @return MorphOne<Note, $this>
     */
    public function latestNote(): MorphOne
    {
        return $this->morphOne(Note::class, 'noteable');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function notARelation(): string
    {
        return 'nope';
    }
}
