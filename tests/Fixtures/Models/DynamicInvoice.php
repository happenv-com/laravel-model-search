<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An invoice that declares no relation at all; its parent order is registered at runtime by the test.
 *
 * @property string $id
 * @property string|null $number
 * @property string|null $order_id
 */
final class DynamicInvoice extends Model
{
    /** @use HasUuids<DynamicInvoice> */
    use HasUuids;

    protected $table = 'invoices';

    protected $guarded = [];
}
