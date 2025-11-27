<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test {--filter= : Filter the tests by name} {--testsuite= : Run a specific test suite}';

    /**
     * The console command description.
     */
    protected $description = 'Run the project\'s PHPUnit test suite.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $phpunit = base_path('vendor/bin/phpunit');

        if (! is_file($phpunit)) {
            $this->error('PHPUnit is not installed. Run "composer install" and try again.');

            return static::FAILURE;
        }

        $command = escapeshellarg($phpunit);

        if ($filter = $this->option('filter')) {
            $command .= ' --filter ' . escapeshellarg($filter);
        }

        if ($suite = $this->option('testsuite')) {
            $command .= ' --testsuite ' . escapeshellarg($suite);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }
}
