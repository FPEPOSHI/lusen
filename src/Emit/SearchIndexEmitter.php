<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Page;
use Lusen\Ir\Parameter;
use Lusen\Support\Links;
use Lusen\Support\MarkdownDocument;
use Lusen\Support\Str;

/**
 * A prebuilt search index, so search needs no server.
 *
 * Everything else about these docs is static files; making search the one
 * thing that requires a running service, an API key and a third party would
 * undo that. A JSON file the page filters in the browser costs nothing to
 * host and works behind a firewall.
 *
 * The index carries enough text to find a page and no more. Full bodies would
 * grow it without improving results - somebody searching documentation is
 * looking for a title, a path or a parameter name.
 */
final readonly class SearchIndexEmitter implements Emitter
{
    /**
     * Truncation point for an entry's searchable text. Generous enough for
     * summaries, headings and parameter names; short enough that a large API
     * still produces an index worth downloading.
     */
    private const TEXT_LIMIT = 400;

    public function __construct(private Links $links) {}

    public function name(): string
    {
        return 'search';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        return [EmittedFile::json('search-index.json', $this->index($spec))];
    }

    /**
     * @return array{version: string, items: list<array<string, string>>}
     */
    public function index(ApiSpec $spec): array
    {
        $items = [];

        foreach ($spec->pages() as $page) {
            $items[] = $this->page($page);
        }

        foreach ($spec->endpoints() as $endpoint) {
            $items[] = $this->endpoint($endpoint);
        }

        return ['version' => $spec->version, 'items' => $items];
    }

    /**
     * @return array<string, string>
     */
    private function page(Page $page): array
    {
        // Headings are the best short summary of what a prose page covers,
        // and they double as the thing a reader was probably looking for.
        $headings = array_map(
            static fn (array $heading): string => $heading['text'],
            MarkdownDocument::render($page->markdown)->contents(),
        );

        return array_filter([
            'title' => $page->title,
            'url' => $this->links->page($page),
            'kind' => 'page',
            'context' => $page->section ?? '',
            'text' => $this->trim($page->summary().' '.implode(' ', $headings)),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function endpoint(Endpoint $endpoint): array
    {
        $parameters = array_map(
            static fn (Parameter $parameter): string => $parameter->name,
            $endpoint->parameters,
        );

        return array_filter([
            'title' => $endpoint->title(),
            'url' => $this->links->endpoint($endpoint),
            'kind' => 'endpoint',
            'method' => $endpoint->method->value,
            'path' => $endpoint->path(),
            // Two versions of "List orders" are two results, and a result
            // list that cannot tell them apart is worse than no search.
            'context' => implode(' · ', array_filter([$endpoint->group, $endpoint->version])),
            'text' => $this->trim(implode(' ', array_filter([
                $endpoint->summary,
                $endpoint->description,
                // Searchable even when the path does not carry it, which is
                // the whole situation of a header-versioned API.
                $endpoint->version,
                implode(' ', $parameters),
            ]))),
        ], static fn (string $value): bool => $value !== '');
    }

    private function trim(string $text): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($flat) > self::TEXT_LIMIT
            ? Str::summarise($flat, self::TEXT_LIMIT)
            : $flat;
    }
}
