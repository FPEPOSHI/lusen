<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Emit\Contracts\Renderer;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Page;
use Lusen\Support\Assets;
use Lusen\Support\JsonLd;
use Lusen\Support\Links;
use Lusen\Support\MarkdownDocument;
use Lusen\Support\Navigation;
use Lusen\Support\Str;

/**
 * The static site: an index plus one page per endpoint.
 *
 * One page per endpoint is the whole SEO argument. A single page with sixteen
 * anchors competes with itself for every query and gives a retrieval model one
 * enormous document to chunk; sixteen pages each rank for their own operation
 * and each answer one question completely.
 *
 * Renders through the same Blade views the runtime renderer uses, so the two
 * modes cannot drift.
 */
final readonly class HtmlEmitter implements Emitter
{
    public function __construct(
        private Renderer $renderer,
        private Links $links,
    ) {}

    public function name(): string
    {
        return 'html';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        $files = [
            // Linked rather than inlined for static output: the stylesheet is
            // one small cacheable file shared by every page, where inlining
            // would repeat it once per endpoint. The runtime renderer still
            // inlines, because there it is a single page and there is no
            // second request to save.
            new EmittedFile('assets/lusen.css', Assets::css(), 'text/css'),
            // Linked for the same reason as the stylesheet, and more so now
            // that it carries the playground: inlining it would repeat tens of
            // kilobytes on every endpoint page, where one cached file does.
            new EmittedFile('assets/lusen.js', Assets::js(), 'text/javascript'),
            EmittedFile::html('index.html', $this->index($spec)),
        ];

        $navigation = Navigation::for($spec, $this->links);

        foreach ($spec->pages() as $page) {
            $files[] = EmittedFile::html(
                'pages/'.$page->slug().'.html',
                $this->page($page, $spec, $navigation),
            );
        }

        foreach ($spec->endpoints() as $endpoint) {
            $files[] = EmittedFile::html(
                'endpoints/'.$endpoint->slug().'.html',
                $this->endpoint($endpoint, $spec, $navigation),
            );
        }

        return $files;
    }

    public function index(ApiSpec $spec): string
    {
        return $this->renderer->render('lusen::index', [
            'spec' => $spec,
            'links' => $this->links,
            'docsUrl' => $this->links->base(),
            'cssHref' => $this->links->asset('lusen.css'),
            'jsHref' => $this->links->asset('lusen.js'),
            'canonical' => $this->links->canonicalIndex(),
            'title' => $spec->title,
            'description' => Str::summarise($spec->description ?? $spec->title),
            'jsonLd' => JsonLd::forSpec($spec, $this->links->base()),
        ]);
    }

    /**
     * A prose page: introduction, guide, use case.
     */
    public function page(Page $page, ApiSpec $spec, ?Navigation $navigation = null): string
    {
        $navigation ??= Navigation::for($spec, $this->links);
        $document = MarkdownDocument::render($page->markdown);

        return $this->renderer->render('lusen::page', [
            'spec' => $spec,
            'page' => $page,
            'body' => $document->html,
            'contents' => $document->contents(),
            'pager' => $navigation->aroundPage($page),
            'current' => 'page:'.$page->id,
            'links' => $this->links,
            'docsUrl' => $this->links->base(),
            'cssHref' => $this->links->asset('lusen.css'),
            'jsHref' => $this->links->asset('lusen.js'),
            'canonical' => $this->links->canonicalPage($page),
            'title' => $page->title.' — '.$spec->title,
            'description' => $page->summary(),
            'jsonLd' => JsonLd::forPage($page, $spec, $this->links->base()),
            'markdownHref' => $this->links->pageMarkdown($page),
        ]);
    }

    public function endpoint(Endpoint $endpoint, ApiSpec $spec, ?Navigation $navigation = null): string
    {
        $navigation ??= Navigation::for($spec, $this->links);

        return $this->renderer->render('lusen::endpoint', [
            'pager' => $navigation->aroundEndpoint($endpoint),
            'current' => 'endpoint:'.$endpoint->id,
            'spec' => $spec,
            'endpoint' => $endpoint,
            'links' => $this->links,
            'docsUrl' => $this->links->base(),
            'cssHref' => $this->links->asset('lusen.css'),
            'jsHref' => $this->links->asset('lusen.js'),
            'canonical' => $this->links->canonicalEndpoint($endpoint),
            // The <title> leads with the operation, not the API name: a search
            // result and a browser tab both truncate from the right.
            'title' => $this->qualify($endpoint->title(), $endpoint, $spec).' — '.$spec->title,
            'description' => $this->qualify(
                Str::summarise($endpoint->description ?? $endpoint->summary ?? $endpoint->title()),
                $endpoint,
                $spec,
            ),
            'jsonLd' => JsonLd::forEndpoint($endpoint, $spec, $this->links->base()),
            'markdownHref' => $this->links->markdown($endpoint),
        ]);
    }

    /**
     * Names the version in the one place duplicate content actually costs
     * something.
     *
     * `v1`'s and `v2`'s list of orders share a summary, so an unqualified
     * title and meta description are two pages competing for one query and
     * two identical snippets in a result list. Below two versions there is
     * nothing to tell apart, and the qualifier would be noise on every page.
     */
    private function qualify(string $text, Endpoint $endpoint, ApiSpec $spec): string
    {
        return $spec->isVersioned() && $endpoint->version !== null
            ? "{$text} ({$endpoint->version})"
            : $text;
    }
}
