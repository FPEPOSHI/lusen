<?php

declare(strict_types=1);

namespace Lusen\Console;

use Illuminate\Console\Command;
use Lusen\Record\Recordings;

/**
 * Captures real responses by running the application's own test suite.
 *
 * The suite already exercises every endpoint worth documenting and already
 * boots the application, which is the one thing a docs build must never do.
 * So recording borrows the tests: this runs them with capture switched on and
 * writes what came back to a file the build reads.
 *
 * Nothing here is required. Without a recording file every example is
 * generated from the schema, exactly as before.
 *
 * Run with `passthru()` rather than symfony/process, which this package does
 * not require and would have to start requiring for one command that many
 * installs never run. The MCP server is written the same way and for the same
 * reason. The command comes from this application's own config file, so it is
 * run as written - the same trust a composer script gets.
 */
final class RecordCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lusen:record
        {--fresh : Re-record everything rather than filling in what is missing}
        {--clear : Delete the recordings and write nothing}
        {--using= : The command to run, if it is not the configured one}';

    /**
     * @var string
     */
    protected $description = 'Run the test suite with capture on, and record the responses it produces';

    public function handle(): int
    {
        $path = $this->path();

        if ($this->option('clear')) {
            return $this->clear($path);
        }

        $existing = $this->option('fresh') ? Recordings::empty() : Recordings::read($path);
        $before = $existing->count();

        $command = $this->command();

        $this->components->info(sprintf('Recording responses from `%s`.', $command));

        // The child inherits this process's environment, which is how the
        // service provider inside the test run learns to switch capture on.
        putenv('LUSEN_RECORD=1');
        putenv('LUSEN_RECORD_PATH='.$path);

        // passthru, so the suite's own output arrives as it happens: this can
        // run for minutes and a silent terminal reads as a hang.
        $status = 0;
        passthru($command, $status);

        $recordings = Recordings::read($path);

        if ($status !== 0) {
            // The suite failing is not the same as recording failing, and what
            // it managed to capture before it went red is still worth keeping.
            $this->components->warn(sprintf(
                'The suite exited %d. %d recordings were kept; a failing test records nothing after it fails.',
                $status,
                $recordings->count(),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Recorded %d response%s across %d operation%s (%d new). Commit %s.',
            $recordings->count(),
            $recordings->count() === 1 ? '' : 's',
            $recordings->operations(),
            $recordings->operations() === 1 ? '' : 's',
            max(0, $recordings->count() - $before),
            $this->relative($path),
        ));

        return self::SUCCESS;
    }

    private function clear(string $path): int
    {
        if (! is_file($path)) {
            $this->components->info('There was nothing recorded.');

            return self::SUCCESS;
        }

        unlink($path);

        $this->components->info(sprintf('Deleted %s. Examples fall back to the schema.', $this->relative($path)));

        return self::SUCCESS;
    }

    private function command(): string
    {
        $using = $this->option('using');
        $configured = config('lusen.record.command');

        $command = is_string($using) && $using !== ''
            ? $using
            : (is_string($configured) && $configured !== '' ? $configured : 'vendor/bin/pest');

        return $command;
    }

    private function path(): string
    {
        $configured = config('lusen.record.path');
        $path = is_string($configured) && $configured !== '' ? $configured : '.lusen-recordings.json';

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function relative(string $path): string
    {
        $root = base_path().'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
