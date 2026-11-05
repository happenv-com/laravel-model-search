<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Indexing;

/**
 * @internal
 */
enum InstallOutcome: string
{
    /** The type had no state row; a rebuild is pending. */
    case Installed = 'installed';

    /** The version went up; a rebuild is pending and the index keeps serving meanwhile. */
    case Updated = 'updated';

    /** Same version, same fingerprint: nothing to do. */
    case Unchanged = 'unchanged';
}
