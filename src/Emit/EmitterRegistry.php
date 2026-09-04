<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Emit\Contracts\Renderer;
use Lusen\Support\Links;

/**
 * Resolves the emitters named in config into instances.
 *
 * Kept separate from the service provider so the set of surfaces can be
 * assembled and asserted on without booting an application, and so adding an
 * emitter is a one-line change in one place.
 */
final class EmitterRegistry
{
    /**
     * @var array<string, callable(): Emitter>
     */
    private array $factories = [];

    /**
     * @param  array<string, mixed>  $output  the `output` section of config/lusen.php
     * @param  Renderer|null  $renderer  required only by emitters that render Blade
     */
    public function __construct(
        private readonly array $output = [],
        ?Renderer $renderer = null,
        ?string $canonicalOrigin = null,
        ?string $lastmod = null,
    ) {
        // Static output addresses pages as files; the runtime renderer uses
        // anchors. Emitters only ever produce the static shape.
        $links = new Links($this->docsUrl(), static: true, canonicalOrigin: $canonicalOrigin);

        $this->factories = [
            'openapi' => static fn (): Emitter => new OpenApiEmitter,
            'llms' => static fn (): Emitter => new LlmsTxtEmitter($links),
            'markdown' => static fn (): Emitter => new MarkdownEmitter($links),
            'sitemap' => static fn (): Emitter => new SitemapEmitter($links, $lastmod),
            'search' => static fn (): Emitter => new SearchIndexEmitter($links),
            'postman' => static fn (): Emitter => new PostmanEmitter,
            'discovery' => static fn (): Emitter => new DiscoveryEmitter($links),
        ];

        if ($renderer !== null) {
            $this->factories['html'] = static fn (): Emitter => new HtmlEmitter($renderer, $links);
        }
    }

    /**
     * @param  callable(): Emitter  $factory
     */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /**
     * Enabled emitters, in config order.
     *
     * A configured name with no registered factory is skipped rather than
     * fatal: surfaces land incrementally, and a config that mentions one that
     * does not exist yet should still produce docs.
     *
     * @return list<Emitter>
     */
    public function enabled(): array
    {
        $emitters = [];

        foreach ($this->enabledNames() as $name) {
            $factory = $this->factories[$name] ?? null;

            if ($factory !== null) {
                $emitters[] = $factory();
            }
        }

        return $emitters;
    }

    /**
     * Names that are configured but have no implementation. The build command
     * reports these so a typo is visible instead of silent.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->enabledNames(),
            fn (string $name): bool => ! isset($this->factories[$name]),
        ));
    }

    /**
     * @return list<string>
     */
    public function registered(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @return list<string>
     */
    private function enabledNames(): array
    {
        $names = $this->output['emitters'] ?? [];

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_filter($names, static fn (mixed $n): bool => is_string($n)));
    }

    private function docsUrl(): string
    {
        $url = $this->output['url'] ?? '/docs';

        return is_string($url) && $url !== '' ? $url : '/docs';
    }
}
