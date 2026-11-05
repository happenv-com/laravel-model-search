<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string|null $sku
 * @property string|null $ean
 * @property array<string, string> $name Translations keyed by locale.
 */
final class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $guarded = [];

    protected $casts = ['name' => 'array'];
}
