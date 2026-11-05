<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that lives on a different connection than the orders.
 *
 * @property string $id
 * @property string|null $phone
 */
final class ElsewhereAddress extends Model
{
    /** @use HasUuids<ElsewhereAddress> */
    use HasUuids;

    protected $connection = 'elsewhere';

    protected $table = 'addresses';

    protected $guarded = [];
}
