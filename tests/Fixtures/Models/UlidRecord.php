<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A record with a fixed-width ULID key, to exercise key columns that are neither uuid, bigint nor text.
 *
 * @property string $id
 * @property string|null $code
 */
final class UlidRecord extends Model
{
    /** @use HasUlids<UlidRecord> */
    use HasUlids;

    protected $table = 'ulid_records';

    protected $guarded = [];
}
