<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $phone
 */
final class Address extends Model
{
    /** @use HasUuids<Address> */
    use HasUuids;

    protected $table = 'addresses';

    protected $guarded = [];
}
