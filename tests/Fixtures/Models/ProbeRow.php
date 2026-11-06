<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $order_id
 * @property string|null $label
 * @property int|null $level
 * @property bool|null $flag
 * @property float|null $score
 */
final class ProbeRow extends Model
{
    /** @use HasUuids<ProbeRow> */
    use HasUuids;

    protected $table = 'probe_rows';

    protected $guarded = [];

    public $timestamps = false;
}
