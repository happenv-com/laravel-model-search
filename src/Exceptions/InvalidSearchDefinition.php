<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use LogicException;

/**
 * Base class for every mistake in a search definition, extension or registration.
 */
class InvalidSearchDefinition extends LogicException {}
