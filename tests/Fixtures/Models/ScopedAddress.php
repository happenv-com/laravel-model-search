<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

use Happenv\ModelSearch\Tests\Fixtures\Scopes\NamedAddressScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The addresses table seen through a global scope.
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $phone
 */
#[ScopedBy(NamedAddressScope::class)]
final class ScopedAddress extends Model
{
    /** @use HasUuids<ScopedAddress> */
    use HasUuids;

    protected $table = 'addresses';

    protected $guarded = [];
}
