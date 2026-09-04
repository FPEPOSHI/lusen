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
