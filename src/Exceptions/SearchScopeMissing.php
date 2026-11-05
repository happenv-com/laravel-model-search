<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

use LogicException;

/**
 * Raised when a search is executed without within() or an explicit withoutScope(reason): searching is fail-closed.
 */
final class SearchScopeMissing extends LogicException {}
