<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Exceptions;

/**
 * Raised when a query asks for something no driver can answer, for a reason the message names.
 * It never carries the phrase.
 */
final class UnsupportedQuery extends InvalidSearchDefinition {}
