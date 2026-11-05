<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $noteable_type
 * @property string $noteable_id
 * @property string|null $body
 */
final class Note extends Model
{
    protected $table = 'notes';

    protected $guarded = [];
}
