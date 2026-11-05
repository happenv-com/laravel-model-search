<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Happenv\ModelSearch\Tests\Fixtures\Scopes\RecentAddressScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $phone
 */
#[ScopedBy(RecentAddressScope::class)]
final class RecentAddress extends Model
{
    /** @use HasUuids<RecentAddress> */
    use HasUuids;

    protected $table = 'addresses';

    protected $guarded = [];
}
