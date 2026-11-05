<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Console;

use Happenv\ModelSearch\Postgres\Doctor\Doctor;
use Illuminate\Console\Command;

/**
 * Prints what is wrong with the search index and the database server, and fails when anything is.
 *
 * The package does not alarm anyone: schedule this, or call {@see Doctor::inspect()}, and send
 * the findings wherever your own alerts go.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'model-search:doctor';

    protected $description = 'Check the search index, its triggers and indexes, and whether the database server could log a search phrase';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->inspect();

        foreach ($findings as $finding) {
            $this->components->error(sprintf('[%s] %s', $finding->code, $finding->message));
        }

        if ($findings === []) {
            $this->components->info('Nothing wrong found.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
