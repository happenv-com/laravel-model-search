<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer read through a second connection to the same database.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 */
final class SecondaryCustomer extends Model
{
    protected $connection = 'second';

    protected $table = 'customers';

    protected $guarded = [];
}
