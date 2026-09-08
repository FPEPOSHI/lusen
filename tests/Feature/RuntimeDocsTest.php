<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Tests\Fixtures\UserController;

beforeEach(function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
});

it('renders the docs page as html that stands alone', function (): void {
    $response = $this->get('/docs');

    $response->assertOk()
        ->assertSee('Test API')
        ->assertSee('List users')
        ->assertSee('/api/users');
});

it('advertises every machine-readable surface in the head', function (): void {
    $this->get('/docs')
        ->assertSee('/docs/openapi.json', escape: false)
        ->assertSee('/.well-known/api-docs', escape: false);
});

it('includes json-ld structured data', function (): void {
    $this->get('/docs')->assertSee('application/ld+json', escape: false);
});

it('serves the spec as json when json is asked for', function (): void {
    $this->get('/docs', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('title', 'Test API');
});

it('serves markdown when markdown is asked for', function (): void {
    $response = $this->get('/docs', ['Accept' => 'text/markdown']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/markdown');
    expect($response->getContent())->toContain('### GET /api/users');
});

it('still serves html to a browser that also accepts json', function (): void {
    $this->get('/docs', ['Accept' => 'text/html,application/json'])
        ->assertOk()
        ->assertSee('<!doctype html>', escape: false);
});

it('serves the openapi document', function (): void {
    $this->get('/docs/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0');
});

it('serves llms.txt and llms-full.txt as plain text', function (): void {
    $this->get('/docs/llms.txt')->assertOk()->assertSee('# Test API');
    $this->get('/docs/llms-full.txt')->assertOk()->assertSee('### GET /api/users');
});

it('serves a discovery document pointing at every surface', function (): void {
    $this->get('/.well-known/api-docs')
        ->assertOk()
        ->assertJsonPath('surfaces.openapi', '/docs/openapi.json')
        ->assertJsonPath('surfaces.llms_txt', '/docs/llms.txt')
        ->assertJsonPath('generator', 'lusen');
});

it('advertises the mcp server in the discovery document', function (): void {
    $this->get('/.well-known/api-docs')
        ->assertOk()
        ->assertJsonPath('mcp.transport', 'stdio')
        ->assertJsonPath('mcp.command', 'php artisan lusen:mcp');
});

it('omits mcp from discovery when it is disabled', function (): void {
    config()->set('lusen.agents.mcp', false);

    $this->get('/.well-known/api-docs')->assertOk()->assertJsonMissingPath('mcp');
});

it('answers every surface the discovery document names', function (): void {
    // The document exists so an agent need not guess. Runtime used to name
    // two URLs nothing served, and a sitemap it does not have.
    $surfaces = $this->get('/.well-known/api-docs')->assertOk()->json('surfaces');

    expect($surfaces)->toHaveKeys(['openapi', 'llms_txt', 'llms_full', 'markdown', 'spec', 'search_index', 'postman'])
        ->and($surfaces)->not->toHaveKey('sitemap');

    foreach ($surfaces as $url) {
        $this->get($url)->assertOk();
    }
});

it('serves the search index, so the search box can appear', function (): void {
    // The script reveals the box only once it has fetched this; without the
    // route, runtime mode had a search field that never showed.
    $items = $this->get('/docs/search-index.json')->assertOk()->json('items');

    expect($items)->not->toBeEmpty()
        ->and($items[0]['url'])->toStartWith('#');
});

it('serves the whole api as markdown at the url a model adds .md to', function (): void {
    $response = $this->get('/docs.md')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/markdown')
        ->and($response->getContent())->toContain('### GET /api/users');
});

it('serves the markdown twin of every page at the path static output would write', function (): void {
    // llms.txt and the links between pages already point here.
    $this->get('/docs/endpoints/users-index.md')->assertOk()->assertSee('# List users');
    $this->get('/docs/groups/users.md')->assertOk()->assertSee('# Users');
    $this->get('/docs/pages/introduction.md')->assertOk()->assertSee('# Introduction');
});

it('answers 404 for a twin of a page that does not exist', function (): void {
    $this->get('/docs/endpoints/nope.md')->assertNotFound();
    $this->get('/docs/groups/nope.md')->assertNotFound();
    $this->get('/docs/pages/nope.md')->assertNotFound();
});

it('serves the spec and the postman collection', function (): void {
    $this->get('/docs/spec.json')->assertOk()->assertJsonPath('title', 'Test API');
    $this->get('/docs/postman.json')->assertOk()->assertJsonStructure(['info', 'item']);
});
