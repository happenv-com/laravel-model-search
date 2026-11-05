<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 */
final class Customer extends Model
{
    protected $table = 'customers';

    protected $guarded = [];
}
