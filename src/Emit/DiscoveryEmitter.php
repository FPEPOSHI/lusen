<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Support\Links;

/**
 * The discovery document, as a file.
 *
 * llms.txt, the index page and the README all point an agent at
 * `/.well-known/api-docs`. Until this existed that URL answered only when the
 * runtime renderer was enabled, so every static deployment advertised a dead
 * link to precisely the audience the package is trying to serve.
 *
 * One guessable URL that names every other surface is the whole point; it has
 * to be there in both modes.
 */
final readonly class DiscoveryEmitter implements Emitter
{
    public function __construct(private Links $links) {}

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
            'surfaces' => array_filter([
                'openapi' => $this->links->openapi(),
                'llms_txt' => $this->links->llms(),
                'llms_full' => $this->links->llmsFull(),
                'search_index' => $this->links->searchIndex(),
                'postman' => $this->links->base().'/postman.json',
                'sitemap' => $this->links->base().'/sitemap.xml',
            ]),
            'endpoints' => count($spec->endpoints()),
            'generator' => 'lusen',
        ], static fn (mixed $value): bool => $value !== null);
    }
}
