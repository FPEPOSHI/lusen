<?php

declare(strict_types=1);

use Lusen\Emit\Contracts\Renderer;
use Lusen\Emit\DiscoveryEmitter;
use Lusen\Emit\EmitterRegistry;
use Lusen\Support\Links;

function discovery(): DiscoveryEmitter
{
    return new DiscoveryEmitter(new Links('/docs', static: true));
}

it('writes the file the docs advertise', function (): void {
    // llms.txt, the index and the README all point here; a static deployment
    // used to answer 404.
    $file = discovery()->emit(fixtureSpec())[0];

    expect($file->path)->toBe('.well-known/api-docs')
        ->and($file->contentType)->toBe('application/json');
});

it('names every other surface', function (): void {
    $surfaces = discovery()->document(fixtureSpec())['surfaces'];

    // No sitemap: this Links has no canonical origin, so no sitemap was
    // written, and naming one would send an agent to a dead URL.
    expect($surfaces)->toBe([
        'openapi' => '/docs/openapi.json',
        'llms_txt' => '/docs/llms.txt',
        'llms_full' => '/docs/llms-full.txt',
        'markdown' => '/docs/index.md',
        'search_index' => '/docs/search-index.json',
        'postman' => '/docs/postman.json',
    ]);
});

it('names the sitemap only where one is written', function (): void {
    $with = new DiscoveryEmitter(new Links('/docs', static: true, canonicalOrigin: 'https://example.com'));

    expect($with->document(fixtureSpec())['surfaces']['sitemap'])->toBe('/docs/sitemap.xml')
        ->and(discovery()->document(fixtureSpec())['surfaces'])->not->toHaveKey('sitemap');
});

it('names only the surfaces whose emitters are switched on', function (): void {
    $emitter = new DiscoveryEmitter(new Links('/docs', static: true), ['openapi', 'llms']);

    expect(array_keys($emitter->document(fixtureSpec())['surfaces']))
        ->toBe(['openapi', 'llms_txt', 'llms_full']);
});

it('advertises the mcp server unless it is turned off', function (): void {
    $on = discovery()->document(fixtureSpec());
    $off = (new DiscoveryEmitter(new Links('/docs', static: true), mcp: false))->document(fixtureSpec());

    expect($on['mcp'])->toBe(['transport' => 'stdio', 'command' => 'php artisan lusen:mcp'])
        ->and($off)->not->toHaveKey('mcp');
});

it('names the surfaces the runtime serves, at the urls it serves them', function (): void {
    // The same emitter in anchor mode: the index's twin is /docs.md, the spec
    // is a URL of its own, and there is no sitemap of a one-page site.
    $runtime = new DiscoveryEmitter(new Links('/docs'), ['openapi', 'llms', 'markdown', 'spec', 'search', 'postman', 'sitemap']);

    $surfaces = $runtime->document(fixtureSpec())['surfaces'];

    expect($surfaces['markdown'])->toBe('/docs.md')
        ->and($surfaces['spec'])->toBe('/docs/spec.json')
        ->and($surfaces['search_index'])->toBe('/docs/search-index.json')
        ->and($surfaces)->not->toHaveKey('sitemap');
});

it('never names a surface the build did not write', function (): void {
    // The invariant the document exists for, checked against the emitters
    // themselves rather than against a list somebody has to keep in step.
    $renderer = new class implements Renderer
    {
        public function render(string $view, array $data): string
        {
            return '';
        }
    };

    $registry = new EmitterRegistry(
        output: ['url' => '/docs', 'emitters' => ['html', 'markdown', 'openapi', 'llms', 'sitemap', 'search', 'postman', 'discovery']],
        renderer: $renderer,
        canonicalOrigin: 'https://example.com',
    );

    $written = [];
    $document = null;

    foreach ($registry->enabled() as $emitter) {
        foreach ($emitter->emit(fixtureSpec()) as $file) {
            $written[] = $file->path;

            if ($file->path === '.well-known/api-docs') {
                $document = json_decode($file->contents, true);
            }
        }
    }

    expect($document)->not->toBeNull();

    foreach ($document['surfaces'] as $url) {
        expect($written)->toContain(substr($url, strlen('/docs/')));
    }
});

it('reports the api identity and size', function (): void {
    $document = discovery()->document(fixtureSpec());

    expect($document['name'])->toBe('Test API')
        ->and($document['version'])->toBe('2.1.0')
        ->and($document['endpoints'])->toBe(3)
        ->and($document['generator'])->toBe('lusen');
});

it('points at the docs root it was built for', function (): void {
    // Static output cannot assume it owns the domain.
    expect((new Links('/docs', static: true))->discovery())->toBe('/docs/.well-known/api-docs')
        ->and((new Links('/docs'))->discovery())->toBe('/.well-known/api-docs');
});
