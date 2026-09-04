<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Group;
use Lusen\Support\Links;
use Lusen\Support\Str;

/**
 * llms.txt and llms-full.txt, per the llmstxt.org convention.
 *
 * Two files with different jobs:
 *
 *   llms.txt       a curated index - one line per endpoint, linking to that
 *                  endpoint's Markdown page. Cheap for a model to read when
 *                  deciding what to fetch.
 *   llms-full.txt  the entire API in one file, for a model that would rather
 *                  ingest everything once than make N requests.
 *
 * Both are plain Markdown on purpose. The whole point is that a retrieval
 * model gets the API without executing JavaScript or parsing our HTML.
 */
final class LlmsTxtEmitter implements Emitter
{
    /**
     * Set for the duration of a render so the per-endpoint sections can build
     * absolute example URLs without every private method taking the spec.
     */
    private ?string $baseUrl = null;

    public function __construct(private readonly Links $links = new Links) {}

    public function name(): string
    {
        return 'llms';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        return [
            new EmittedFile('llms.txt', $this->index($spec), 'text/plain; charset=utf-8'),
            new EmittedFile('llms-full.txt', $this->full($spec), 'text/plain; charset=utf-8'),
        ];
    }

    public function index(ApiSpec $spec): string
    {
        $lines = ["# {$spec->title}", ''];

        $lines[] = '> '.Str::summarise(
            $spec->description ?? "HTTP API reference for {$spec->title}, version {$spec->version}.",
            300,
        );
        $lines[] = '';

        if ($spec->baseUrl !== null) {
            $lines[] = "All endpoints are relative to `{$spec->baseUrl}`.";
            $lines[] = '';
        }

        $lines = [...$lines, ...Markdown::versions($spec)];

        foreach ($spec->sections as $section) {
            $lines[] = "## {$section->name}";
            $lines[] = '';

            foreach ($section->pages as $page) {
                $lines[] = sprintf(
                    '- [%s](%s): %s',
                    $page->title,
                    $this->links->pageMarkdown($page),
                    $page->summary(),
                );
            }

            $lines[] = '';
        }

        foreach ($spec->groups as $group) {
            $lines[] = "## {$group->displayName()}";
            $lines[] = '';

            if ($group->description !== null) {
                $lines[] = $group->description;
                $lines[] = '';
            }

            foreach ($group->endpoints as $endpoint) {
                $lines[] = sprintf(
                    '- [%s %s](%s): %s',
                    $endpoint->method->value,
                    $endpoint->path(),
                    $this->markdownUrl($endpoint),
                    Str::summarise($endpoint->summary ?? $endpoint->title()),
                );
            }

            $lines[] = '';
        }

        $lines[] = '## Machine-readable';
        $lines[] = '';
        $lines[] = "- [OpenAPI 3.1]({$this->links->base()}/openapi.json): complete machine-readable specification.";
        $lines[] = "- [Full text]({$this->links->base()}/llms-full.txt): every endpoint in one file.";
        $lines[] = "- [Discovery]({$this->links->discovery()}): index of every documentation surface.";
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function full(ApiSpec $spec): string
    {
        $this->baseUrl = $spec->baseUrl;

        $lines = ["# {$spec->title}", ''];
        $lines[] = "Version {$spec->version}.";

        if ($spec->baseUrl !== null) {
            $lines[] = "Base URL: `{$spec->baseUrl}`";
        }

        $lines[] = '';

        if ($spec->description !== null) {
            $lines[] = $spec->description;
            $lines[] = '';
        }

        $lines = [...$lines, ...Markdown::versions($spec)];

        // Prose before reference: a model reading this top to bottom should
        // learn what the API is for before it learns the operation names.
        foreach ($spec->sections as $section) {
            $lines[] = "## {$section->name}";
            $lines[] = '';

            foreach ($section->pages as $page) {
                $lines[] = "### {$page->title}";
                $lines[] = '';
                $lines[] = $page->markdown;
                $lines[] = '';
            }
        }

        foreach ($spec->groups as $group) {
            $lines = [...$lines, ...$this->groupSection($group, $spec)];
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function groupSection(Group $group, ApiSpec $spec): array
    {
        // The version belongs in the heading here, not in a heading of its
        // own: this is one long document, and a model chunking it keeps the
        // nearest heading rather than the section three levels up.
        $lines = ["## {$group->displayName()}", ''];

        if ($group->description !== null) {
            $lines[] = $group->description;
            $lines[] = '';
        }

        foreach ($group->endpoints as $endpoint) {
            $successor = $spec->endpoint($endpoint->supersededBy);

            $lines = [...$lines, ...Markdown::endpoint(
                $endpoint,
                $this->baseUrl,
                3,
                successor: $successor,
                successorUrl: $successor === null ? null : $this->links->markdown($successor),
            )];
        }

        return $lines;
    }

    private function markdownUrl(Endpoint $endpoint): string
    {
        return $this->links->markdown($endpoint);
    }
}
