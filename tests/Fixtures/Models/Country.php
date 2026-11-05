<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $name
 */
final class Country extends Model
{
    protected $table = 'countries';

    protected $guarded = [];
}
