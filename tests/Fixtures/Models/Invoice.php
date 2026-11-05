<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $number
 * @property string|null $external_reference
 * @property string|null $order_id
 */
final class Invoice extends Model
{
    /** @use HasUuids<Invoice> */
    use HasUuids;

    protected $table = 'invoices';

    protected $guarded = [];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Belongs to the order whose external number is this invoice's external reference.
     *
     * @return BelongsTo<Order, $this>
     */
    public function orderByExternal(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'external_reference', 'external_number');
    }
}
