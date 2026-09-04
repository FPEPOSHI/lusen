<?php

declare(strict_types=1);

use Lusen\Emit\DiscoveryEmitter;
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

    expect($surfaces)->toBe([
        'openapi' => '/docs/openapi.json',
        'llms_txt' => '/docs/llms.txt',
        'llms_full' => '/docs/llms-full.txt',
        'search_index' => '/docs/search-index.json',
        'postman' => '/docs/postman.json',
        'sitemap' => '/docs/sitemap.xml',
    ]);
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
