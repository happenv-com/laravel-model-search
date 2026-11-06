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
class Order extends Model
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

    /**
     * Joins on a column that is not the owner's primary key.
     *
     * @return BelongsTo<Address, $this>
     */
    public function billingAddressByCode(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_code', 'code');
    }

    /**
     * Joins on a column that is not the order's primary key.
     *
     * @return HasOne<MarketOrder, $this>
     */
    public function marketOrderBySignature(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_signature', 'signature');
    }

    /**
     * @return BelongsTo<RecentAddress, $this>
     */
    public function recentBillingAddress(): BelongsTo
    {
        return $this->belongsTo(RecentAddress::class, 'billing_address_id');
    }

    /**
     * @return BelongsTo<ElsewhereAddress, $this>
     */
    public function elsewhereAddress(): BelongsTo
    {
        return $this->belongsTo(ElsewhereAddress::class, 'billing_address_id');
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function whereMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->where('kind', 'order');
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function nestedWhereMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->where(static function ($query): void {
            $query->where('kind', 'order')->orWhere('status', 'created');
        });
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function rawWhereMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->whereRaw("kind = 'order'");
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function joinedMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->join('addresses', 'addresses.id', '=', 'market_orders.order_id');
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function limitedMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->limit(1);
    }

    /**
     * @return HasOne<MarketOrder, $this>
     */
    public function latestMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->latestOfMany();
    }

    /**
     * Ordering does not change which rows the relation holds.
     *
     * @return HasOne<MarketOrder, $this>
     */
    public function orderedMarketOrder(): HasOne
    {
        return $this->hasOne(MarketOrder::class, 'order_id')->orderByDesc('market_order_number');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function whereBillingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id')->where('kind', 'billing');
    }

    /**
     * @return HasMany<MarketOrder, $this>
     */
    public function whereMarketOrders(): HasMany
    {
        return $this->hasMany(MarketOrder::class, 'order_id')->whereNotNull('status');
    }

    /**
     * @return HasManyThrough<CarrierUnit, Parcel, $this>
     */
    public function whereParcelCarrierUnits(): HasManyThrough
    {
        return $this->hasManyThrough(CarrierUnit::class, Parcel::class, 'order_id', 'parcel_id')->where('tracking_number', 'T-1');
    }

    /**
     * @return HasManyThrough<CarrierUnit, Parcel, $this>
     */
    public function limitedParcelCarrierUnits(): HasManyThrough
    {
        return $this->hasManyThrough(CarrierUnit::class, Parcel::class, 'order_id', 'parcel_id')->limit(3);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function whereTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'order_tag', 'order_id', 'tag_id')->where('name', 'vip');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function wherePivotTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'order_tag', 'order_id', 'tag_id')->wherePivot('tag_id', '>', 0);
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function whereNotes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->where('body', 'pinned');
    }
}
