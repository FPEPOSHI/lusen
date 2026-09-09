<?php

declare(strict_types=1);

namespace Lusen;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Lusen\Build\BuildCache;
use Lusen\Collect\PageCollector;
use Lusen\Collect\RouteCollector;
use Lusen\Console\BuildCommand;
use Lusen\Console\CheckCommand;
use Lusen\Console\DiffCommand;
use Lusen\Console\McpCommand;
use Lusen\Console\RecordCommand;
use Lusen\Emit\BladeRenderer;
use Lusen\Emit\Contracts\Renderer;
use Lusen\Emit\EmitterRegistry;
use Lusen\Extract\AttributeExtractor;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\ExternalAttributeExtractor;
use Lusen\Extract\ExtractionPipeline;
use Lusen\Extract\Models\MigrationReader;
use Lusen\Extract\Models\ModelLocator;
use Lusen\Extract\Models\ModelSchema;
use Lusen\Extract\RecordedExampleExtractor;
use Lusen\Extract\Resources\ResourceReader;
use Lusen\Extract\RouteExtractor;
use Lusen\Record\Recorder;
use Lusen\Record\Recordings;
use Lusen\Support\Data;
use OutOfBoundsException;

final class LusenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lusen.php', 'lusen');

        // Bound rather than shared on purpose: each of these reads config at
        // resolve time, so changing config after boot - a test, or anything
        // running after config:cache - is actually honoured.

        $this->app->bind(RouteCollector::class, fn (Container $app): RouteCollector => new RouteCollector(
            router: $app->make(Router::class),
            config: $this->routeConfig(),
        ));

        $this->app->bind(BuildCache::class, fn (): BuildCache => new BuildCache(
            path: $this->cachePath(),
            key: $this->cacheKey(),
            enabled: $this->cacheEnabled(),
        ));

        $this->app->bind(ExtractionPipeline::class, fn (Container $app): ExtractionPipeline => new ExtractionPipeline(
            extractors: $this->extractors(),
            cache: $app->make(BuildCache::class),
        ));

        $this->app->bind(Renderer::class, fn (Container $app): Renderer => new BladeRenderer(
            $app->make(ViewFactory::class),
        ));

        $this->app->bind(EmitterRegistry::class, fn (Container $app): EmitterRegistry => new EmitterRegistry(
            output: $this->outputConfig(),
            renderer: $app->make(Renderer::class),
            canonicalOrigin: $this->canonicalOrigin(),
            lastmod: Data::nullableString(Data::map($this->section('lusen'), 'seo'), 'lastmod'),
            mcp: (bool) $this->config()->get('lusen.agents.mcp', true),
        ));

        $this->app->bind(PageCollector::class, fn (): PageCollector => new PageCollector(
            $this->pagesPath(),
            $this->app->basePath(),
        ));

        // Bound explicitly because it takes configuration; the others are
        // resolved straight from the container by the extractor list.
        $this->app->bind(RouteExtractor::class, fn (): RouteExtractor => new RouteExtractor(
            Data::map($this->section('lusen'), 'auth'),
            Data::map($this->section('lusen'), 'versions'),
        ));

        $this->app->bind(ExternalAttributeExtractor::class, fn (): ExternalAttributeExtractor => new ExternalAttributeExtractor(
            $this->externalAttributeNamespaces(),
        ));

        $this->app->bind(RecordedExampleExtractor::class, fn (): RecordedExampleExtractor => new RecordedExampleExtractor(
            Recordings::read($this->recordingPath()),
        ));

        $this->app->bind(ModelSchema::class, fn (): ModelSchema => new ModelSchema(
            new MigrationReader($this->migrationPaths()),
        ));

        $this->app->bind(ModelLocator::class, fn (): ModelLocator => new ModelLocator(
            $this->modelNamespaces(),
        ));

        $this->app->bind(SpecBuilder::class, function (Container $app): SpecBuilder {
            $this->configureResourceReader($app);

            return new SpecBuilder(
                collector: $app->make(RouteCollector::class),
                pipeline: $app->make(ExtractionPipeline::class),
                config: $this->section('lusen'),
                pages: $app->make(PageCollector::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lusen');

        $this->bootRecorder();

        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildCommand::class,
                CheckCommand::class,
                DiffCommand::class,
                McpCommand::class,
                RecordCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/lusen.php' => $this->app->configPath('lusen.php'),
            ], 'lusen-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/lusen'),
            ], 'lusen-views');

            // Starter prose. Editorial pages cannot be derived from routes, so
            // they ship as stubs to edit rather than as generated filler.
            $this->publishes([
                __DIR__.'/../resources/stubs/pages' => $this->pagesPath(),
            ], 'lusen-pages');
        }

        if ($this->runtimeEnabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/lusen.php');
        }
    }

    /**
     * Switches capture on for a test run, and writes what it caught at the end
     * of the process.
     *
     * Driven by an environment variable rather than a config flag, so it is on
     * for exactly one command's child process and cannot be left on by an
     * edit to a config file that somebody then commits.
     *
     * The write happens on shutdown rather than per response: a test suite
     * rebuilds the container between tests, so per-response writes would be
     * hundreds of read-modify-write cycles on the same file to end up where
     * one write at the end lands anyway.
     */
    private function bootRecorder(): void
    {
        if (getenv('LUSEN_RECORD') !== '1') {
            return;
        }

        $path = $this->recordingPath();

        if (! Recorder::started()) {
            Recorder::start(Recordings::read($path));

            register_shutdown_function(static function () use ($path): void {
                $recordings = Recorder::recordings();

                if ($recordings->isEmpty()) {
                    return;
                }

                $directory = dirname($path);

                if (is_dir($directory) || mkdir($directory, 0755, true) || is_dir($directory)) {
                    file_put_contents($path, $recordings->toJson());
                }
            });
        }

        $redact = $this->recordRedactions();

        $this->app->make(Dispatcher::class)->listen(
            RequestHandled::class,
            static function (RequestHandled $event) use ($redact): void {
                Recorder::capture($event->request, $event->response, $redact);
            },
        );
    }

    /**
     * Where recordings live, relative to the project root. The environment
     * wins, because the command sets it for its own child process.
     */
    private function recordingPath(): string
    {
        $fromEnv = getenv('LUSEN_RECORD_PATH');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $configured = Data::string(Data::map($this->section('lusen'), 'record'), 'path', '.lusen-recordings.json');

        return str_starts_with($configured, '/') ? $configured : $this->app->basePath($configured);
    }

    /**
     * @return list<string>
     */
    private function recordRedactions(): array
    {
        return Data::strings(Data::map($this->section('lusen'), 'record'), 'redact');
    }

    /**
     * Instantiates the configured extractor pipeline, skipping anything that
     * is not actually an Extractor so a typo in config degrades to missing
     * detail rather than a broken install.
     *
     * @return list<Extractor>
     */
    private function extractors(): array
    {
        $extractors = [];

        foreach (Data::strings($this->section('lusen'), 'extractors') as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $instance = $this->app->make($class);

            if ($instance instanceof Extractor) {
                $extractors[] = $instance;
            }
        }

        return $this->withExternalAttributes($extractors);
    }

    /**
     * Puts the foreign-attribute reader back if the configured list is missing
     * it.
     *
     * A published `extractors` array is a snapshot of the pipeline as it was
     * the day somebody ran `vendor:publish`, and Laravel merges it over ours
     * whole. Every extractor added since is absent from it, which for this one
     * means an application arriving from another tool quietly gets none of its
     * existing annotations - a support question, not an error. `read_external`
     * is the switch for anyone who genuinely wants them ignored.
     *
     * Inserted before AttributeExtractor, never after: Lusen's own attributes
     * are the last word, and that ordering is an invariant.
     *
     * @param  list<Extractor>  $extractors
     * @return list<Extractor>
     */
    private function withExternalAttributes(array $extractors): array
    {
        if (! $this->readsExternalAttributes()) {
            return $extractors;
        }

        foreach ($extractors as $extractor) {
            if ($extractor instanceof ExternalAttributeExtractor) {
                return $extractors;
            }
        }

        $external = $this->app->make(ExternalAttributeExtractor::class);
        $last = end($extractors);

        if ($last instanceof AttributeExtractor) {
            array_splice($extractors, count($extractors) - 1, 0, [$external]);

            return $extractors;
        }

        return [...$extractors, $external];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeConfig(): array
    {
        return Data::map($this->section('lusen'), 'routes');
    }

    /**
     * @return array<string, mixed>
     */
    private function outputConfig(): array
    {
        return Data::map($this->section('lusen'), 'output');
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        return Data::map([$key => $this->config()->get($key, [])], $key);
    }

    /**
     * Canonical URLs need an absolute origin. Falls back to the API's own base
     * URL, which is right whenever the docs are served from the same host.
     */
    private function canonicalOrigin(): ?string
    {
        $section = $this->section('lusen');

        $origin = Data::nullableString(Data::map($section, 'seo'), 'canonical_origin');

        return $origin ?? Data::nullableString($section, 'base_url');
    }

    /**
     * ResourceReader consults models through static state because it recurses
     * through nested resources; threading a dependency through every level
     * would put a container concern inside a parser.
     */
    private function configureResourceReader(Container $app): void
    {
        if (! $this->modelsEnabled()) {
            ResourceReader::useModels(null, null);

            return;
        }

        ResourceReader::useModels(
            $app->make(ModelSchema::class),
            $app->make(ModelLocator::class),
        );
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        $configured = Data::strings(Data::map($this->section('lusen'), 'models'), 'migrations');

        if ($configured === []) {
            $configured = ['database/migrations'];
        }

        return array_map(
            fn (string $path): string => str_starts_with($path, '/') ? $path : $this->app->basePath($path),
            $configured,
        );
    }

    /**
     * @return list<string>
     */
    private function modelNamespaces(): array
    {
        $configured = Data::strings(Data::map($this->section('lusen'), 'models'), 'namespaces');

        return $configured === [] ? ['App\\Models', 'App'] : $configured;
    }

    private function modelsEnabled(): bool
    {
        $models = Data::map($this->section('lusen'), 'models');

        return ! array_key_exists('enabled', $models) || (bool) $models['enabled'];
    }

    private function cachePath(): string
    {
        $configured = Data::string(Data::map($this->section('lusen'), 'cache'), 'path', '.lusen/cache');

        $directory = str_starts_with($configured, '/')
            ? $configured
            : $this->app->basePath($configured);

        return rtrim($directory, '/').'/endpoints.json';
    }

    /**
     * Everything that changes what extraction produces, without being a file
     * an endpoint reads. A config edit or a reordered extractor list has to
     * invalidate the whole cache, since the stored endpoints were produced by
     * rules that no longer apply.
     */
    /**
     * Invalidates every cached endpoint when anything that shaped it changes:
     * the configuration, and the package itself.
     *
     * The package half is not optional. An upgrade that teaches an extractor
     * to read something new - a response shape it used to miss - would
     * otherwise hand back the endpoints analysed by the old version, and the
     * feature somebody upgraded for would appear not to work.
     */
    /**
     * @return list<string>
     */
    private function externalAttributeNamespaces(): array
    {
        if (! $this->readsExternalAttributes()) {
            return [];
        }

        $attributes = Data::map($this->section('lusen'), 'attributes');
        $external = $attributes['external'] ?? [];

        $configured = is_array($external)
            ? array_filter($external, static fn (mixed $v): bool => is_string($v) && $v !== '')
            : [];

        // Built-ins first and config on top of them, never instead of them.
        // Laravel merges a published config shallowly, so an application that
        // set `external` to its own namespace - or published this file before
        // Lusen knew Scramble - would otherwise lose every vendor Lusen ships
        // support for, and the loss would look like the feature not working.
        return array_values(array_unique([...ExternalAttributeExtractor::VENDORS, ...$configured]));
    }

    /**
     * Whether foreign attributes are read at all.
     *
     * A missing key means yes. That is the whole point: the applications this
     * matters most to are the ones with a `config/lusen.php` published before
     * any of this existed, and a default of "off when unstated" would leave
     * exactly them unsupported.
     */
    private function readsExternalAttributes(): bool
    {
        $attributes = Data::map($this->section('lusen'), 'attributes');

        return ! isset($attributes['read_external']) || $attributes['read_external'] !== false;
    }

    private function cacheKey(): string
    {
        $config = $this->section('lusen');

        unset($config['cache'], $config['output'], $config['ui'], $config['seo']);

        // The recordings are an input to every endpoint, and they are the one
        // input the per-endpoint fingerprint cannot see: it hashes the source
        // files an endpoint was read from, and a recording is read from a
        // JSON file nobody parses. Keying the whole cache on it means
        // `lusen:record` followed by `lusen:build` documents what was
        // recorded, rather than handing back yesterday's examples.
        $recordings = $this->recordingPath();
        $recorded = is_file($recordings) ? (hash_file('xxh128', $recordings) ?: '') : '';

        return hash('xxh128', (json_encode($config) ?: '').'|'.$this->packageVersion().'|'.$recorded);
    }

    /**
     * The installed commit of this package, falling back to its version, then
     * to nothing at all when Composer's runtime API cannot answer - which is
     * the case while running the package's own test suite.
     */
    private function packageVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return '';
        }

        try {
            return InstalledVersions::getReference('fpeposhi/lusen')
                ?? InstalledVersions::getPrettyVersion('fpeposhi/lusen')
                ?? '';
        } catch (OutOfBoundsException) {
            return '';
        }
    }

    private function cacheEnabled(): bool
    {
        $cache = Data::map($this->section('lusen'), 'cache');

        return ! array_key_exists('enabled', $cache) || (bool) $cache['enabled'];
    }

    private function pagesPath(): string
    {
        $configured = Data::string(Data::map($this->section('lusen'), 'pages'), 'path', 'resources/docs');

        return str_starts_with($configured, '/')
            ? $configured
            : $this->app->basePath($configured);
    }

    private function runtimeEnabled(): bool
    {
        return (bool) $this->config()->get('lusen.runtime.enabled', false);
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }
}
