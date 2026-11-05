<?php

declare(strict_types=1);

use Happenv\ModelSearch\Tests\TestCase;

// Every test runs inside a rolled-back transaction, except the files that need DDL a transaction
// would hide (CREATE INDEX CONCURRENTLY, another role, a read-only session): they pick
// TruncationTestCase themselves, so they are left out of the directory-wide binding below.
uses(TestCase::class)->in(
    'Unit',
    'Feature/[!P]*',
    'Feature/Postgres/IndexPlanTest.php',
    'Feature/Postgres/TriggersTest.php',
);
