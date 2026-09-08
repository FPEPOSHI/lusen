<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Diff\VersionDiff;
use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Group;
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

        foreach ($spec->groups as $group) {
            $files[] = EmittedFile::markdown('groups/'.$group->slug().'.md', $this->group($group, $spec));

            foreach ($group->endpoints as $endpoint) {
                $files[] = EmittedFile::markdown(
                    'endpoints/'.$endpoint->slug().'.md',
                    $this->endpoint($endpoint, $spec),
                );
            }
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
            // The heading is the way to the group's own page, as it is on
            // the HTML index.
            $lines[] = "## [{$group->displayName()}]({$this->links->groupMarkdown($group)})";
            $lines[] = '';

            if ($group->description !== null) {
                $lines[] = $group->description;
                $lines[] = '';
            }

            $lines = [...$lines, ...$this->operations($group)];
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
            changes: VersionDiff::forEndpoint($spec, $endpoint),
            changedFrom: VersionDiff::previousVersion($spec, $endpoint->version),
        )];

        return implode("\n", $lines);
    }

    /**
     * The group's page: what the resource is for, and the operations on it,
     * each linking to its own mirror. Lists rather than repeats, like the HTML
     * page it twins.
     */
    public function group(Group $group, ApiSpec $spec): string
    {
        $lines = [
            '---',
            'title: '.$this->quote($group->displayName()),
            'group: '.$this->quote($group->name),
        ];

        if ($group->version !== null) {
            $lines[] = 'api_version: '.$this->quote($group->version);
        }

        $canonical = $this->links->canonicalGroup($group);

        if ($canonical !== null) {
            $lines[] = 'canonical: '.$this->quote($canonical);
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = "# {$group->displayName()}";
        $lines[] = '';
        $lines[] = "Part of the [{$spec->title}]({$this->links->index()}) documentation.";
        $lines[] = '';

        if ($group->description !== null) {
            $lines[] = $group->description;
            $lines[] = '';
        }

        // Repeated here rather than linked: a retrieved page has to stand
        // alone, and these are the two things a reader needs before the
        // first call.
        if ($spec->baseUrl !== null) {
            $lines[] = "Base URL: `{$spec->baseUrl}`";
            $lines[] = '';
        }

        $lines[] = $group->authenticationSummary();
        $lines[] = '';
        $lines[] = '## Operations';
        $lines[] = '';

        return implode("\n", [...$lines, ...$this->operations($group)]);
    }

    /**
     * One line per operation, linking to its mirror. Shared by the index and
     * the group page so the two cannot list the same group differently.
     *
     * @return list<string>
     */
    private function operations(Group $group): array
    {
        $lines = [];

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

        return $lines;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
