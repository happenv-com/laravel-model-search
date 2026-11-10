<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

/**
 * An order that names its connection, as a model of a multi-database host does (`UsesTenantConnection`): it keeps
 * naming `pgsql` while the migrator runs on its direct variant.
 */
final class ConnectedOrder extends Order
{
    protected $connection = 'pgsql';
}
