<?php

declare(strict_types=1);

namespace Lusen\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Lusen\Emit\LlmsTxtEmitter;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Support\Links;

/**
 * Runtime rendering, for local development and auth-gated docs.
 *
 * Renders through the same Blade views the static HtmlEmitter uses, so the
 * two modes cannot drift. Static output remains the default because it needs
 * no PHP on the request path.
 */
final class DocsController
{
    public function __construct(private readonly SpecBuilder $builder) {}

    /**
     * Content negotiation lives here: one URL, three representations. An
     * agent that sends `Accept: text/markdown` gets Markdown without having
     * to know about the .md mirror; one that constructs `/docs.md` gets the
     * same bytes. Both paths matter - the first is polite, the second is what
     * a model can guess.
     */
    public function index(Request $request): Response
    {
        $spec = $this->spec();

        if ($this->wants($request, 'application/json')) {
            return response($spec->toJson(JSON_PRETTY_PRINT), 200)
                ->header('Content-Type', 'application/json');
        }

        if ($this->wants($request, 'text/markdown', 'text/plain')) {
            return response((new LlmsTxtEmitter($this->links()))->full($spec), 200)
                ->header('Content-Type', 'text/markdown; charset=utf-8');
        }

        return response(View::make('lusen::index', [
            'spec' => $spec,
            'links' => $this->links(),
            'docsUrl' => $this->docsUrl(),
        ])->render());
    }

    public function openapi(): Response
    {
        $document = (new OpenApiEmitter)->document($this->spec());

        return response(
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            200,
        )->header('Content-Type', 'application/json');
    }

    public function llms(): Response
    {
        return response((new LlmsTxtEmitter($this->links()))->index($this->spec()), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function llmsFull(): Response
    {
        return response((new LlmsTxtEmitter($this->links()))->full($this->spec()), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Points every machine consumer at every surface from one well-known
     * place, so an agent needs exactly one guessable URL to orient itself.
     */
    public function discovery(): Response
    {
        $base = rtrim($this->docsUrl(), '/');
        $spec = $this->spec();

        return response(array_filter([
            'name' => $spec->title,
            'version' => $spec->version,
            'description' => $spec->description,
            'documentation' => $base,
            'surfaces' => [
                'openapi' => "{$base}/openapi.json",
                'llms_txt' => "{$base}/llms.txt",
                'llms_full' => "{$base}/llms-full.txt",
                'markdown' => "{$base}.md",
                'spec' => "{$base}/spec.json",
            ],
            'generator' => 'lusen',
            // Advertised so an agent that found the docs can discover there is
            // a tool interface too, rather than settling for scraping them.
            'mcp' => config('lusen.agents.mcp', true) ? [
                'transport' => 'stdio',
                'command' => 'php artisan lusen:mcp',
            ] : null,
        ], static fn (mixed $value): bool => $value !== null), 200);
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
