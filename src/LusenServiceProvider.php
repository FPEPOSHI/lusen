<?php

declare(strict_types=1);

namespace Lusen;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Lusen\Build\BuildCache;
use Lusen\Collect\PageCollector;
use Lusen\Collect\RouteCollector;
use Lusen\Console\BuildCommand;
use Lusen\Console\CheckCommand;
use Lusen\Console\DiffCommand;
use Lusen\Console\McpCommand;
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
use Lusen\Extract\Resources\ResourceReader;
use Lusen\Extract\RouteExtractor;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildCommand::class,
                CheckCommand::class,
                DiffCommand::class,
                McpCommand::class,
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

        return hash('xxh128', (json_encode($config) ?: '').'|'.$this->packageVersion());
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
