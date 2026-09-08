<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Support\Links;

/**
 * The discovery document: one guessable URL that names every other surface.
 *
 * llms.txt, the index page and the README all point an agent at
 * `/.well-known/api-docs`, so it has to exist in both modes, and it has to be
 * the same document in both: the runtime renderer used to build its own,
 * and the two had already drifted apart, each naming surfaces the other
 * served and one naming two that nobody did.
 *
 * It lists only what is actually there. A surface whose emitter is off is
 * not a surface, and a sitemap needs absolute URLs, so without a canonical
 * origin there is no sitemap to point at. A discovery document that names a
 * dead URL costs an agent the one request it made to avoid guessing.
 */
final readonly class DiscoveryEmitter implements Emitter
{
    /**
     * What a static build produces by default, by emitter name. The runtime
     * renderer passes its own list, since it serves a different set.
     */
    public const STATIC_SURFACES = ['openapi', 'llms', 'markdown', 'search', 'postman', 'sitemap'];

    /**
     * @param  list<string>  $available  the emitters, by name, whose output exists beside this document
     * @param  bool  $mcp  whether the host application exposes the MCP server
     */
    public function __construct(
        private Links $links,
        private array $available = self::STATIC_SURFACES,
        private bool $mcp = true,
    ) {}

    public function name(): string
    {
        return 'discovery';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        return [EmittedFile::json('.well-known/api-docs', $this->document($spec))];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(ApiSpec $spec): array
    {
        return array_filter([
            'name' => $spec->title,
            'version' => $spec->version,
            'description' => $spec->description,
            'documentation' => $this->links->index(),
            // The one place an agent can learn which versions exist without
            // parsing a page or downloading the whole OpenAPI document.
            'api_versions' => $spec->versions === []
                ? null
                : array_map(static fn (ApiVersion $v): array => $v->toArray(), $spec->versions),
            'surfaces' => $this->surfaces(),
            'endpoints' => count($spec->endpoints()),
            'generator' => 'lusen',
            // Advertised so an agent that found the docs can discover there is
            // a tool interface too, rather than settling for scraping them.
            'mcp' => $this->mcp ? [
                'transport' => 'stdio',
                'command' => 'php artisan lusen:mcp',
            ] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    private function surfaces(): array
    {
        $base = $this->links->base();

        // Surface key => [the emitter that produces it, its URL].
        $all = [
            'openapi' => ['openapi', $this->links->openapi()],
            'llms_txt' => ['llms', $this->links->llms()],
            'llms_full' => ['llms', $this->links->llmsFull()],
            'markdown' => ['markdown', $this->links->indexMarkdown()],
            'spec' => ['spec', $base.'/spec.json'],
            'search_index' => ['search', $this->links->searchIndex()],
            'postman' => ['postman', $base.'/postman.json'],
            'sitemap' => ['sitemap', $base.'/sitemap.xml'],
        ];

        $surfaces = [];

        foreach ($all as $key => [$emitter, $url]) {
            if (! in_array($emitter, $this->available, true)) {
                continue;
            }

            if ($emitter === 'sitemap' && $this->links->canonicalIndex() === null) {
                continue;
            }

            $surfaces[$key] = $url;
        }

        return $surfaces;
    }
}
