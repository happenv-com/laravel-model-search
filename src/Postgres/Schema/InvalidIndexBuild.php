<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Schema;

use RuntimeException;

/**
 * A concurrent index build returned without an error but left the index invalid. Internal to the
 * sync: it is treated like a lock timeout (the leftover is dropped, the build retried).
 */
final class InvalidIndexBuild extends RuntimeException {}
