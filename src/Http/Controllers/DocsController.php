<?php

declare(strict_types=1);

namespace Lusen\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Lusen\Emit\DiscoveryEmitter;
use Lusen\Emit\LlmsTxtEmitter;
use Lusen\Emit\MarkdownEmitter;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Emit\PostmanEmitter;
use Lusen\Emit\SearchIndexEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Support\Links;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runtime rendering, for local development and auth-gated docs.
 *
 * Renders through the same Blade views the static HtmlEmitter uses, so the
 * two modes cannot drift. Static output remains the default because it needs
 * no PHP on the request path.
 *
 * Every URL a runtime page advertises is served here: the search index the
 * script fetches, the Markdown twin of every page, and each surface the
 * discovery document names. A page that advertises a URL nobody answers is
 * worse than one that says nothing.
 */
final class DocsController
{
    /**
     * What the runtime serves, by emitter name, for the discovery document.
     * No sitemap: at runtime the whole API is one page, and a sitemap of
     * anchors would list one URL.
     */
    private const SURFACES = ['openapi', 'llms', 'markdown', 'spec', 'search', 'postman'];

    public function __construct(private readonly SpecBuilder $builder) {}

    /**
     * Content negotiation lives here: one URL, three representations. An
     * agent that sends `Accept: text/markdown` gets Markdown without having
     * to know about the .md mirror; one that constructs `/docs.md` gets the
     * same bytes from markdown() below. Both paths matter - the first is
     * polite, the second is what a model can guess.
     */
    public function index(Request $request): Response
    {
        $spec = $this->spec();

        if ($this->wants($request, 'application/json')) {
            return $this->json($spec->toArray());
        }

        if ($this->wants($request, 'text/markdown', 'text/plain')) {
            return $this->markdownResponse($this->corpus($spec));
        }

        return response(View::make('lusen::index', [
            'spec' => $spec,
            'links' => $this->links(),
            'docsUrl' => $this->docsUrl(),
        ])->render());
    }

    /**
     * The index's Markdown twin. At runtime the index is the whole API on
     * one page, so its twin is the whole API as Markdown.
     */
    public function markdown(): Response
    {
        return $this->markdownResponse($this->corpus($this->spec()));
    }

    public function specDocument(): Response
    {
        return $this->json($this->spec()->toArray());
    }

    public function openapi(): Response
    {
        return $this->json((new OpenApiEmitter)->document($this->spec()));
    }

    public function postman(): Response
    {
        return $this->json((new PostmanEmitter)->collection($this->spec()));
    }

    /**
     * The search box only appears once the script has fetched this, so
     * without it runtime mode had a search field that never showed.
     */
    public function searchIndex(): Response
    {
        return $this->json((new SearchIndexEmitter($this->links()))->index($this->spec()));
    }

    public function llms(): Response
    {
        return $this->text((new LlmsTxtEmitter($this->links()))->index($this->spec()));
    }

    public function llmsFull(): Response
    {
        return $this->text($this->corpus($this->spec()));
    }

    /**
     * The Markdown twin of one endpoint, at the URL llms.txt and the
     * supersession notices already point at.
     */
    public function endpointMarkdown(string $slug): Response
    {
        $spec = $this->spec();

        foreach ($spec->endpoints() as $endpoint) {
            if ($endpoint->slug() === $slug) {
                return $this->markdownResponse((new MarkdownEmitter($this->links()))->endpoint($endpoint, $spec));
            }
        }

        throw new NotFoundHttpException;
    }

    public function groupMarkdown(string $slug): Response
    {
        $spec = $this->spec();
        $group = $spec->group($slug);

        if ($group === null) {
            throw new NotFoundHttpException;
        }

        return $this->markdownResponse((new MarkdownEmitter($this->links()))->group($group, $spec));
    }

    public function pageMarkdown(string $slug): Response
    {
        $spec = $this->spec();

        foreach ($spec->pages() as $page) {
            if ($page->slug() === $slug) {
                return $this->markdownResponse((new MarkdownEmitter($this->links()))->page($page, $spec));
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * The same document static output writes, over the surfaces this mode
     * serves, so an agent reading it is never sent to a URL only the other
     * mode answers.
     */
    public function discovery(): Response
    {
        $emitter = new DiscoveryEmitter(
            $this->links(),
            self::SURFACES,
            (bool) config('lusen.agents.mcp', true),
        );

        return $this->json($emitter->document($this->spec()));
    }

    /**
     * Anchor mode: at runtime the whole API is one page, so endpoints are
     * fragments rather than files.
     */
    private function links(): Links
    {
        return new Links($this->docsUrl());
    }

    private function spec(): ApiSpec
    {
        return $this->builder->build();
    }

    private function corpus(ApiSpec $spec): string
    {
        return (new LlmsTxtEmitter($this->links()))->full($spec);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): Response
    {
        return response(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            200,
        )->header('Content-Type', 'application/json');
    }

    private function markdownResponse(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/markdown; charset=utf-8');
    }

    private function text(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function wants(Request $request, string ...$types): bool
    {
        $accept = $request->header('Accept');

        if (! is_string($accept) || $accept === '' || str_contains($accept, 'text/html')) {
            return false;
        }

        foreach ($types as $type) {
            if (str_contains($accept, $type)) {
                return true;
            }
        }

        return false;
    }

    private function docsUrl(): string
    {
        $path = config('lusen.runtime.path', 'docs');

        return '/'.trim(is_string($path) ? $path : 'docs', '/');
    }
}
