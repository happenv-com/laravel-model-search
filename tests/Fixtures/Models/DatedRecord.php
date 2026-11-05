<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record keyed by a date, a key type a list of keys cannot be narrowed by.
 */
final class DatedRecord extends Model
{
    protected $table = 'dated_records';

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
