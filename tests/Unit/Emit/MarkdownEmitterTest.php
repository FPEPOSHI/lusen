<?php

declare(strict_types=1);

use Lusen\Emit\MarkdownEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;
use Lusen\Support\Links;

function markdown(): MarkdownEmitter
{
    return new MarkdownEmitter(new Links('/docs', static: true, canonicalOrigin: 'https://example.com'));
}

it('emits an index plus one file per endpoint', function (): void {
    $paths = array_map(fn ($f): string => $f->path, markdown()->emit(fixtureSpec()));

    expect($paths)->toBe([
        'index.md',
        'endpoints/users-index.md',
        'endpoints/users-store.md',
        'endpoints/users-show.md',
    ]);
});

it('names each file so swapping the extension reaches the html page', function (): void {
    // The .html/.md symmetry is what lets an agent construct the other
    // representation without a discovery step.
    $links = new Links('/docs', static: true);
    $endpoint = fixtureSpec()->endpoint('users.index');

    expect($links->markdown($endpoint))->toBe('/docs/endpoints/users-index.md')
        ->and($links->endpoint($endpoint))->toBe('/docs/endpoints/users-index.html');
});

it('opens each page with front matter carrying the stable identifiers', function (): void {
    $page = markdown()->endpoint(fixtureSpec()->endpoint('users.store'), fixtureSpec());

    expect($page)->toStartWith("---\n")
        ->toContain('operation_id: "users.store"')
        ->toContain('method: POST')
        ->toContain('path: "/api/users"')
        ->toContain('group: "Users"')
        ->toContain('authenticated: true')
        ->toContain('canonical: "https://example.com/docs/endpoints/users-store.html"');
});

it('marks a page as deprecated only when it is', function (): void {
    $spec = fixtureSpec();

    expect(markdown()->endpoint($spec->endpoint('users.index'), $spec))->not->toContain('deprecated:');
});

it('repeats auth and the full url on every page', function (): void {
    $spec = fixtureSpec();

    expect(markdown()->endpoint($spec->endpoint('users.store'), $spec))
        ->toContain('Authentication: required (bearer token).')
        ->toContain('Full URL: `https://api.test/api/users`')
        ->and(markdown()->endpoint($spec->endpoint('users.index'), $spec))
        ->toContain('Authentication: not required.');
});

it('carries a runnable request example onto every page', function (): void {
    $spec = fixtureSpec();

    expect(markdown()->endpoint($spec->endpoint('users.store'), $spec))
        ->toContain('### Example request')
        ->toContain('curl -X POST');
});

it('renders parameter tables with nested fields flattened', function (): void {
    $spec = fixtureSpec();

    expect(markdown()->endpoint($spec->endpoint('users.store'), $spec))
        ->toContain('### Body parameters')
        ->toContain('| `email` | string<email> | yes |');
});

it('links the index to every endpoint mirror', function (): void {
    expect(markdown()->index(fixtureSpec()))
        ->toContain('- [GET /api/users](/docs/endpoints/users-index.md) — List users')
        ->toContain('## Machine-readable');
});

it('escapes quotes in front matter values', function (): void {
    $spec = new ApiSpec('Quotes', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/x', 'x.show')
                ->with(summary: 'A "quoted" summary'),
        ]),
    ]);

    expect(markdown()->endpoint($spec->endpoint('x.show'), $spec))
        ->toContain('title: "A \"quoted\" summary"');
});
