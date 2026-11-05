<?php

declare(strict_types=1);

use Happenv\ModelSearch\Tests\TestCase;

require_once __DIR__.'/Helpers.php';

// Every test runs inside a rolled-back transaction, except the files that need DDL a transaction
// would hide (CREATE INDEX CONCURRENTLY, another role, a read-only session): they pick
// TruncationTestCase themselves, so they are left out of the directory-wide binding below.
uses(TestCase::class)->in(
    'Unit',
    'Feature/[!P]*',
    'Feature/Postgres/CandidateBranchesTest.php',
    'Feature/Postgres/IndexPlanTest.php',
    'Feature/Postgres/IndexerTest.php',
    'Feature/Postgres/IndexStateCacheTest.php',
    'Feature/Postgres/ApplyTest.php',
    'Feature/Postgres/CandidatesTest.php',
    'Feature/Postgres/PaletteRoundOneTest.php',
    'Feature/Postgres/PaletteRoundTwoTest.php',
    'Feature/Postgres/PlanShapeTest.php',
    'Feature/Postgres/PrivacyGateTest.php',
    'Feature/Postgres/SearchSessionNestedTest.php',
    'Feature/Postgres/SimilarTest.php',
    'Feature/Postgres/TriggersTest.php',
);
