<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use LogicException;

/**
 * Raised when a partition value is missing for a partitioned definition, or given for one that is not partitioned.
 */
final class PartitionMissing extends LogicException implements ModelSearchException {}
