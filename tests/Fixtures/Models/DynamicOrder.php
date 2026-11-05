<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests\Fixtures\Models;

/**
 * An order whose relations are registered at runtime, the way a modular extension adds them to a model it
 * does not own; the test registers them with `resolveRelationUsing()`.
 */
final class DynamicOrder extends Order {}
