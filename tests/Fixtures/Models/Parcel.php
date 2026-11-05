<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $order_id
 * @property string|null $reference
 */
final class Parcel extends Model
{
    /** @use HasUuids<Parcel> */
    use HasUuids;

    protected $table = 'parcels';

    protected $guarded = [];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<CarrierUnit, $this>
     */
    public function carrierUnits(): HasMany
    {
        return $this->hasMany(CarrierUnit::class, 'parcel_id');
    }
}
