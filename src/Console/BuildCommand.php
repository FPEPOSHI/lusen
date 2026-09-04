<?php

declare(strict_types=1);

namespace Lusen\Console;

use Illuminate\Console\Command;
use Lusen\Build\BuildCache;
use Lusen\Emit\Contracts\Emitter;
use Lusen\Emit\EmittedFile;
use Lusen\Emit\EmitterRegistry;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Support\Writer;

final class BuildCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lusen:build
        {--only=* : Emit only these surfaces, e.g. --only=openapi --only=llms}
        {--dry-run : Analyse and report without writing anything}
        {--path= : Override the output directory}
        {--fresh : Ignore the build cache and re-analyse everything}';

    /**
     * @var string
     */
    protected $description = 'Analyse this application\'s API routes and emit the documentation surfaces';

    public function handle(): int
    {
        $started = microtime(true);

        // Resolved here rather than method-injected: --fresh has to be applied
        // before anything reads the cache config, and method injection happens
        // before handle() runs.
        if ($this->option('fresh')) {
            config()->set('lusen.cache.enabled', false);
        }

        // Built fresh, then shared for the rest of this run. Fresh, because a
        // previous run in the same process must not hand back a cache built
        // from the config as it was then. Shared, because resolving twice
        // would give the pipeline one instance and the report another - and
        // the report would describe a build that never happened.
        app()->forgetInstance(BuildCache::class);

        $cache = app(BuildCache::class);
        app()->instance(BuildCache::class, $cache);

        $registry = app(EmitterRegistry::class);

        $spec = app(SpecBuilder::class)->build();
        $endpointCount = count($spec->endpoints());

        if ($endpointCount === 0) {
            $this->components->warn(
                'No routes matched. Check the `routes.include` patterns in config/lusen.php.',
            );

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Documented %d endpoint%s across %d group%s in %s.',
            $endpointCount,
            $endpointCount === 1 ? '' : 's',
            count($spec->groups),
            count($spec->groups) === 1 ? '' : 's',
            $this->elapsed($started),
        ));

        $this->reportCache($cache);

        foreach ($registry->missing() as $name) {
            $this->components->warn("No emitter registered for [{$name}] - skipping.");
        }

        $files = $this->collectFiles($spec, $this->selectEmitters($registry));

        if ($files === []) {
            $this->components->warn('No emitters enabled; nothing to write.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->report($files);
        }

        return $this->write($files);
    }

    /**
     * @param  list<Emitter>  $emitters
     * @return list<EmittedFile>
     */
    private function collectFiles(ApiSpec $spec, array $emitters): array
    {
        $files = [];

        foreach ($emitters as $emitter) {
            foreach ($emitter->emit($spec) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<Emitter>
     */
    private function selectEmitters(EmitterRegistry $registry): array
    {
        /** @var list<string> $only */
        $only = (array) $this->option('only');

        $emitters = $registry->enabled();

        if ($only === []) {
            return $emitters;
        }

        return array_values(array_filter(
            $emitters,
            static fn (Emitter $e): bool => in_array($e->name(), $only, true),
        ));
    }

    /**
     * @param  list<EmittedFile>  $files
     */
    private function report(array $files): int
    {
        $rows = array_map(
            static fn (EmittedFile $f): array => [$f->path, number_format($f->bytes()).' B'],
            $files,
        );

        $this->table(['Would write', 'Size'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  list<EmittedFile>  $files
     */
    private function write(array $files): int
    {
        $writer = new Writer($this->outputPath());
        $result = $writer->writeAll($files);

        $this->components->info(sprintf(
            '%d file%s written, %d unchanged (%s) → %s',
            $result['written'],
            $result['written'] === 1 ? '' : 's',
            $result['skipped'],
            $this->humanBytes($result['bytes']),
            $writer->root(),
        ));

        return self::SUCCESS;
    }

    /**
     * Says how much work was skipped, so the cache is visibly doing something
     * rather than being a silent behaviour that is hard to trust.
     */
    private function reportCache(BuildCache $cache): void
    {
        if (! $cache->isEnabled()) {
            return;
        }

        ['hits' => $hits, 'misses' => $misses] = $cache->stats();

        if ($hits === 0) {
            return;
        }

        $this->components->info(sprintf(
            '%d endpoint%s reused from cache, %d re-analysed.',
            $hits,
            $hits === 1 ? '' : 's',
            $misses,
        ));
    }

    private function elapsed(float $started): string
    {
        $seconds = microtime(true) - $started;

        return $seconds < 1
            ? round($seconds * 1000).'ms'
            : round($seconds, 2).'s';
    }

    private function outputPath(): string
    {
        $override = $this->option('path');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $configured = config('lusen.output.path', 'public/docs');

        $path = is_string($configured) ? $configured : 'public/docs';

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
