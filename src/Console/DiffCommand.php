<?php

declare(strict_types=1);

namespace Lusen\Console;

use Illuminate\Console\Command;
use Lusen\Diff\Baseline;
use Lusen\Diff\Change;
use Lusen\Diff\Severity;
use Lusen\Diff\SpecDiff;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;

/**
 * Compares this build of the API against a recorded baseline.
 *
 * The documentation knows every parameter, every response field and every
 * validation rule, which makes it the one artefact in the repository that can
 * answer "does this branch break somebody's client?" before a client finds
 * out. Record a baseline on the release branch, run this on pull requests
 * with --strict, and a removed response field stops being something a
 * consumer discovers in production.
 */
final class DiffCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lusen:diff
        {--against= : Baseline file to compare against}
        {--save : Record this build as the baseline and write nothing else}
        {--strict : Exit non-zero when anything breaking changed}
        {--json : Report as JSON, for a script rather than a person}';

    /**
     * @var string
     */
    protected $description = 'Report what changed since the recorded baseline, and what of it breaks a client';

    public function handle(SpecBuilder $builder): int
    {
        $path = $this->path();
        $spec = $builder->build();

        if ($this->option('save')) {
            return $this->save($spec, $path);
        }

        if (! is_file($path)) {
            return $this->missing($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->components->error("Could not read the baseline at [{$path}].");

            return self::FAILURE;
        }

        $changes = SpecDiff::between(Baseline::decode($contents), $spec);

        return $this->option('json')
            ? $this->reportJson($changes, $path)
            : $this->report($changes, $path);
    }

    /**
     * @param  list<Change>  $changes
     */
    private function report(array $changes, string $path): int
    {
        if ($changes === []) {
            $this->components->info('Nothing changed since the baseline.');

            return self::SUCCESS;
        }

        // Grouped by severity rather than by endpoint: the question this
        // command exists to answer is "is any of this going to break
        // somebody", and an answer interleaved with additions is one nobody
        // reads to the end of.
        foreach (Severity::cases() as $severity) {
            $matching = array_values(array_filter(
                $changes,
                static fn (Change $change): bool => $change->severity === $severity,
            ));

            if ($matching === []) {
                continue;
            }

            $this->newLine();
            $this->components->twoColumnDetail(
                "<fg={$severity->tone()};options=bold>{$severity->label()}</>",
                count($matching).' change'.(count($matching) === 1 ? '' : 's'),
            );

            // No indent: the component trims one. The blank line above each
            // heading is what separates the grades in a CI log with no
            // colour, which is where this output is usually read.
            foreach ($matching as $change) {
                $this->components->twoColumnDetail(
                    "<fg={$severity->tone()}>{$change->subject}</>",
                    $change->detail,
                );
            }
        }

        $this->newLine();

        $breaking = array_filter($changes, static fn (Change $c): bool => $c->isBreaking());

        if ($breaking === []) {
            $this->components->info(sprintf(
                '%d change%s since %s, none of them breaking.',
                count($changes),
                count($changes) === 1 ? '' : 's',
                $this->relative($path),
            ));

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d of %d changes break a client written against %s.',
            count($breaking),
            count($changes),
            $this->relative($path),
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<Change>  $changes
     */
    private function reportJson(array $changes, string $path): int
    {
        $this->output->writeln((string) json_encode([
            'baseline' => $this->relative($path),
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $changes),
            'summary' => SpecDiff::tally($changes),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return SpecDiff::breaks($changes) && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function save(ApiSpec $spec, string $path): int
    {
        if ($spec->endpoints() === []) {
            $this->components->warn(
                'No routes matched, so there is nothing to record. Check the `routes.include` patterns in config/lusen.php.',
            );

            return self::SUCCESS;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create [{$directory}].");

            return self::FAILURE;
        }

        if (file_put_contents($path, Baseline::encode($spec)) === false) {
            $this->components->error("Could not write the baseline to [{$path}].");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Recorded the baseline at %s. Commit it, and future runs report what changed since.',
            $this->relative($path),
        ));

        return self::SUCCESS;
    }

    /**
     * A first run has nothing to compare against, and failing there would
     * turn adding this command to CI into a red build before anybody had the
     * chance to record anything.
     */
    private function missing(string $path): int
    {
        $relative = $this->relative($path);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'baseline' => $relative,
                'recorded' => false,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->warn(
            "No baseline at {$relative}. Run `php artisan lusen:diff --save` to record one.",
        );

        return self::SUCCESS;
    }

    private function path(): string
    {
        $option = $this->option('against');
        $configured = config('lusen.diff.baseline');

        $path = is_string($option) && $option !== ''
            ? $option
            : (is_string($configured) && $configured !== '' ? $configured : '.lusen-baseline.json');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * Reported relative to the project root: an absolute path in CI output is
     * a runner's temp directory and tells the reader nothing.
     */
    private function relative(string $path): string
    {
        $root = base_path().'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
