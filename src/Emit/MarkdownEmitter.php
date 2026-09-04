<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Page;
use Lusen\Support\Links;
use Lusen\Support\Str;

/**
 * A Markdown mirror of every page.
 *
 * The point is retrieval. An agent that lands on an endpoint's HTML URL can
 * swap `.html` for `.md` and get the same content with no markup to wade
 * through; a crawler that prefers Markdown gets it without negotiating.
 *
 * Every file is self-contained - method, path, auth, parameters and a runnable
 * example - because a retrieval model may see exactly one of them.
 */
final readonly class MarkdownEmitter implements Emitter
{
    public function __construct(private Links $links) {}

    public function name(): string
    {
        return 'markdown';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        $files = [EmittedFile::markdown('index.md', $this->index($spec))];

        foreach ($spec->pages() as $page) {
            $files[] = EmittedFile::markdown('pages/'.$page->slug().'.md', $this->page($page, $spec));
        }

        foreach ($spec->endpoints() as $endpoint) {
            $files[] = EmittedFile::markdown(
                'endpoints/'.$endpoint->slug().'.md',
                $this->endpoint($endpoint, $spec),
            );
        }

        return $files;
    }

    public function index(ApiSpec $spec): string
    {
        $lines = ["# {$spec->title}", ''];

        if ($spec->description !== null) {
            $lines[] = $spec->description;
            $lines[] = '';
        }

        $lines[] = "Version {$spec->version}.";

        if ($spec->baseUrl !== null) {
            $lines[] = "Base URL: `{$spec->baseUrl}`";
        }

        $lines[] = '';

        $lines = [...$lines, ...Markdown::versions($spec)];

        foreach ($spec->sections as $section) {
            $lines[] = "## {$section->name}";
            $lines[] = '';

            foreach ($section->pages as $page) {
                $lines[] = sprintf(
                    '- [%s](%s) — %s',
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
                    '- [%s %s](%s)%s',
                    $endpoint->method->value,
                    $endpoint->path(),
                    $this->links->markdown($endpoint),
                    $endpoint->summary === null ? '' : ' — '.Str::summarise($endpoint->summary),
                );
            }

            $lines[] = '';
        }

        $lines[] = '## Machine-readable';
        $lines[] = '';
        $lines[] = "- [OpenAPI 3.1]({$this->links->openapi()})";
        $lines[] = "- [llms.txt]({$this->links->llms()})";
        $lines[] = "- [Full corpus]({$this->links->llmsFull()})";
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The prose page as Markdown. This is the source the HTML was rendered
     * from, so the mirror is exact rather than a reconstruction.
     */
    public function page(Page $page, ApiSpec $spec): string
    {
        $lines = [
            '---',
            'title: '.$this->quote($page->title),
            'page_id: '.$this->quote($page->id),
        ];

        if ($page->section !== null) {
            $lines[] = 'section: '.$this->quote($page->section);
        }

        $canonical = $this->links->canonicalPage($page);

        if ($canonical !== null) {
            $lines[] = 'canonical: '.$this->quote($canonical);
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = "# {$page->title}";
        $lines[] = '';
        $lines[] = "Part of the [{$spec->title}]({$this->links->index()}) documentation.";
        $lines[] = '';
        $lines[] = $page->markdown;

        return implode("\n", $lines);
    }

    public function endpoint(Endpoint $endpoint, ApiSpec $spec): string
    {
        // Front matter, so a static-site pipeline or a RAG chunker has the
        // identifiers without parsing the prose.
        $lines = [
            '---',
            'title: '.$this->quote($endpoint->title()),
            'operation_id: '.$this->quote($endpoint->id),
            'method: '.$endpoint->method->value,
            'path: '.$this->quote($endpoint->path()),
        ];

        if ($endpoint->group !== null) {
            $lines[] = 'group: '.$this->quote($endpoint->group);
        }

        if ($endpoint->version !== null) {
            $lines[] = 'api_version: '.$this->quote($endpoint->version);
        }

        $lines[] = 'authenticated: '.($endpoint->authenticated ? 'true' : 'false');

        if ($endpoint->deprecated) {
            $lines[] = 'deprecated: true';
        }

        $successor = $spec->endpoint($endpoint->supersededBy);

        // In the front matter as well as the prose: a chunker that keeps only
        // the metadata still knows this document has been overtaken.
        if ($successor !== null) {
            $lines[] = 'superseded_by: '.$this->quote($successor->id);
        }

        $canonical = $this->links->canonicalEndpoint($endpoint);

        if ($canonical !== null) {
            $lines[] = 'canonical: '.$this->quote($canonical);
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = "# {$endpoint->title()}";
        $lines[] = '';
        $lines[] = "Part of the [{$spec->title}]({$this->links->index()}) documentation.";
        $lines[] = '';

        // The h1 above is already the summary; Markdown::endpoint would
        // otherwise restate it immediately underneath.
        $lines = [...$lines, ...Markdown::endpoint(
            $endpoint,
            $spec->baseUrl,
            2,
            includeSummary: false,
            successor: $successor,
            successorUrl: $successor === null ? null : $this->links->markdown($successor),
        )];

        return implode("\n", $lines);
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
