<?php

declare(strict_types=1);

use Lusen\Emit\Contracts\Renderer;
use Lusen\Emit\HtmlEmitter;
use Lusen\Support\Links;

/**
 * Records what it was asked to render, so the emitter's structure can be
 * asserted without a booted application. Content is covered by the Feature
 * suite, where real Blade runs.
 */
function recordingRenderer(): Renderer
{
    return new class implements Renderer
    {
        /** @var list<array{view: string, data: array<string, mixed>}> */
        public array $calls = [];

        public function render(string $view, array $data): string
        {
            $this->calls[] = ['view' => $view, 'data' => $data];

            return "<!-- {$view} -->";
        }
    };
}

function htmlEmitter(Renderer $renderer): HtmlEmitter
{
    return new HtmlEmitter(
        $renderer,
        new Links('/docs', static: true, canonicalOrigin: 'https://example.com'),
    );
}

it('emits the shared assets, an index and one page per endpoint', function (): void {
    $paths = array_map(fn ($f): string => $f->path, htmlEmitter(recordingRenderer())->emit(fixtureSpec()));

    expect($paths)->toBe([
        'assets/lusen.css',
        'assets/lusen.js',
        'index.html',
        'endpoints/users-index.html',
        'endpoints/users-store.html',
        'endpoints/users-show.html',
    ]);
});

it('links the stylesheet rather than inlining it into every page', function (): void {
    // Inlining would repeat ~20 KB once per endpoint. One cacheable file is
    // the right trade for a multi-page site.
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect($renderer->calls[0]['data']['cssHref'])->toBe('/docs/assets/lusen.css');
});

it('ships the stylesheet with a css content type', function (): void {
    $css = htmlEmitter(recordingRenderer())->emit(fixtureSpec())[0];

    expect($css->contentType)->toBe('text/css')
        ->and($css->contents)->not->toBe('');
});

it('renders the index and endpoint views', function (): void {
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect(array_column($renderer->calls, 'view'))
        ->toBe(['lusen::index', 'lusen::endpoint', 'lusen::endpoint', 'lusen::endpoint']);
});

it('gives every page its own canonical url', function (): void {
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect($renderer->calls[0]['data']['canonical'])->toBe('https://example.com/docs/index.html')
        ->and($renderer->calls[1]['data']['canonical'])->toBe('https://example.com/docs/endpoints/users-index.html');
});

it('omits the canonical url when no origin is configured', function (): void {
    $renderer = recordingRenderer();
    (new HtmlEmitter($renderer, new Links('/docs', static: true)))->emit(fixtureSpec());

    // A relative canonical is worse than none - search engines treat it as an error.
    expect($renderer->calls[0]['data']['canonical'])->toBeNull();
});

it('leads the page title with the operation, not the api name', function (): void {
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect($renderer->calls[1]['data']['title'])->toBe('List users — Test API');
});

it('points each page at its own markdown mirror', function (): void {
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect($renderer->calls[1]['data']['markdownHref'])->toBe('/docs/endpoints/users-index.md');
});

it('gives each endpoint page its own structured data', function (): void {
    $renderer = recordingRenderer();
    htmlEmitter($renderer)->emit(fixtureSpec());

    expect($renderer->calls[1]['data']['jsonLd'])->toContain('TechArticle')
        ->and($renderer->calls[0]['data']['jsonLd'])->toContain('WebSite');
});

it('does not double the docs path when the origin already contains it', function (): void {
    // A wrong canonical is worse than none, and the mistake is invisible in a
    // browser, so the overlap is stripped rather than trusted.
    $links = new Links('/lusen', static: true, canonicalOrigin: 'https://example.com/lusen');

    expect($links->canonicalIndex())->toBe('https://example.com/lusen/index.html');
});

it('joins a bare origin normally', function (): void {
    $links = new Links('/lusen', static: true, canonicalOrigin: 'https://example.com');

    expect($links->canonicalIndex())->toBe('https://example.com/lusen/index.html');
});
