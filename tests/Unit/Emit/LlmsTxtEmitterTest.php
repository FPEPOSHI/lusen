<?php

declare(strict_types=1);

use Lusen\Emit\LlmsTxtEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;
use Lusen\Support\Links;

it('emits both the index and the full corpus', function (): void {
    $files = (new LlmsTxtEmitter)->emit(fixtureSpec());

    expect(array_map(fn ($f) => $f->path, $files))->toBe(['llms.txt', 'llms-full.txt']);
});

it('opens the index with the title and a blockquote summary', function (): void {
    $index = (new LlmsTxtEmitter)->index(fixtureSpec());

    expect($index)->toStartWith("# Test API\n\n> Fixture API used by the test suite.");
});

it('links each endpoint in the index to its markdown mirror', function (): void {
    $index = (new LlmsTxtEmitter(new Links('/docs')))->index(fixtureSpec());

    expect($index)->toContain('- [GET /api/users](/docs/endpoints/users-index.md): List users');
});

it('points the index at every machine-readable surface', function (): void {
    $index = (new LlmsTxtEmitter(new Links('/docs')))->index(fixtureSpec());

    expect($index)->toContain('/docs/openapi.json')
        ->toContain('/docs/llms-full.txt')
        ->toContain('/.well-known/api-docs');
});

it('states the auth requirement on every endpoint so a fragment stands alone', function (): void {
    $full = (new LlmsTxtEmitter)->full(fixtureSpec());

    expect($full)->toContain('Authentication: required (bearer token).')
        ->toContain('Authentication: not required.');
});

it('renders a markdown table per parameter location', function (): void {
    $full = (new LlmsTxtEmitter)->full(fixtureSpec());

    expect($full)->toContain('#### Query parameters')
        ->toContain('| `per_page` | integer | no | Results per page. |')
        ->toContain('#### Body parameters')
        ->toContain('| `email` | string<email> | yes |');
});

it('includes response examples as fenced json', function (): void {
    $full = (new LlmsTxtEmitter)->full(fixtureSpec());

    expect($full)->toContain('```json')
        ->toContain('"data"');
});

it('escapes pipes in descriptions so tables survive', function (): void {
    $spec = new ApiSpec('Pipes', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/thing')->withParameters([
                new Parameter(
                    name: 'mode',
                    in: ParameterLocation::Query,
                    schema: Schema::string(),
                    description: 'Either a | or b',
                ),
            ]),
        ]),
    ]);

    expect((new LlmsTxtEmitter)->full($spec))->toContain('Either a \\| or b');
});

it('advertises a discovery url that actually exists in this mode', function (): void {
    // Static output writes .well-known under the docs root, so llms.txt must
    // point there rather than at the domain root.
    $static = new LlmsTxtEmitter(new Links('/docs', static: true));

    expect($static->index(fixtureSpec()))->toContain('](/docs/.well-known/api-docs)')
        ->and((new LlmsTxtEmitter(new Links('/docs')))->index(fixtureSpec()))
        ->toContain('](/.well-known/api-docs)');
});
