<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The whole test runs in one transaction: that is the baseline a drain may run at.
        config(['model-search.drain.allowed_transaction_level' => 1]);
    }
}
