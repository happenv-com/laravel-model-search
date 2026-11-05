<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $label
 */
final class CodedItem extends Model
{
    protected $table = 'coded_items';

    protected $guarded = [];
}
