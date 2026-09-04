<?php

declare(strict_types=1);

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Example;
use Lusen\Ir\Group;
use Lusen\Ir\Page;
use Lusen\Ir\RateLimit;
use Lusen\Ir\Response;
use Lusen\Pages\DefaultPages;

/**
 * A spec shaped to exercise the derivations: mixed auth, a group with no
 * description, and real error statuses including a 429.
 */
function pagesSpec(): ApiSpec
{
    $list = Endpoint::make(HttpMethod::Get, 'api/widgets', 'widgets.index')
        ->with(summary: 'List widgets', group: 'Widgets', responses: [
            new Response(200, 'A page of widgets.'),
            new Response(429, 'Too many requests.'),
        ]);

    $create = Endpoint::make(HttpMethod::Post, 'api/widgets', 'widgets.store')
        ->with(summary: 'Create a widget', group: 'Widgets', authenticated: true, responses: [
            new Response(201, 'Created.'),
            new Response(422, 'Validation failed.', examples: [
                new Example('Invalid', ['message' => 'The name field is required.']),
            ]),
        ]);

    $delete = Endpoint::make(HttpMethod::Delete, 'api/widgets/{widget}', 'widgets.destroy')
        ->with(summary: 'Delete a widget', group: 'Widgets', authenticated: true);

    return new ApiSpec(
        title: 'Widget API',
        version: '3.0.0',
        groups: [new Group('Widgets', [$list, $create, $delete])],
        description: 'Everything about widgets.',
        baseUrl: 'https://api.widgets.test',
    );
}

function generated(ApiSpec $spec, array $authored = []): array
{
    $pages = [];

    foreach (DefaultPages::fill($spec, $authored) as $page) {
        $pages[$page->id] = $page;
    }

    return $pages;
}

it('derives an introduction from what the spec knows', function (): void {
    $introduction = generated(pagesSpec())['introduction'];

    expect($introduction->section)->toBe('Getting started')
        ->and($introduction->markdown)
        ->toContain('Everything about widgets.')
        ->toContain('| Version | `3.0.0` |')
        ->toContain('| Base URL | `https://api.widgets.test` |')
        ->toContain('3 across 1 group');
});

it('describes a group from the verbs it exposes when it has no description', function (): void {
    expect(generated(pagesSpec())['introduction']->markdown)
        ->toContain('**Widgets** — Read, create, delete widgets.');
});

it('prefers the group description when there is one', function (): void {
    expect(generated(fixtureSpec())['introduction']->markdown)
        ->toContain('**Users** — Create and read user accounts.');
});

it('shows a runnable first request in the introduction', function (): void {
    expect(generated(pagesSpec())['introduction']->markdown)
        ->toContain('curl -X GET')
        ->toContain('https://api.widgets.test/api/widgets');
});

it('derives an authentication page matching the detected middleware', function (): void {
    expect(generated(pagesSpec())['authentication']->markdown)
        ->toContain('Authorization: Bearer YOUR_TOKEN')
        ->toContain('2 of 3 endpoints require authentication.')
        ->toContain('- `GET /api/widgets`');
});

it('omits the authentication page when nothing requires auth', function (): void {
    // Better an absent page than one describing a scheme this API may not use.
    $open = new ApiSpec('Open', groups: [
        new Group('G', [Endpoint::make(HttpMethod::Get, 'api/ping')]),
    ]);

    expect(generated($open))->not->toHaveKey('authentication');
});

it('builds an error reference from the statuses actually returned', function (): void {
    $errors = generated(pagesSpec())['errors']->markdown;

    expect($errors)->toContain('| `422` |')
        ->toContain('| `429` |')
        ->and($errors)->not->toContain('| `404` |')
        ->and($errors)->not->toContain('| `200` |');
});

it('gives each status its general meaning, not one endpoint wording', function (): void {
    // "Validation failed." is true of that one endpoint; the reference table
    // is a statement about the status code itself.
    $errors = generated(pagesSpec())['errors']->markdown;

    expect($errors)->toContain('| `422` | Unprocessable Entity |')
        ->and($errors)->not->toContain('| `422` | Validation failed. |');
});

it('shows a real error body when the spec has one', function (): void {
    expect(generated(pagesSpec())['errors']->markdown)
        ->toContain('The name field is required.');
});

it('points at the rate limiting page only when the api returns 429', function (): void {
    expect(generated(pagesSpec())['errors']->markdown)->toContain('[Rate limiting](rate-limiting)')
        ->and(generated(fixtureSpec()))->not->toHaveKey('errors');
});

it('omits the errors page when no endpoint documents a failure', function (): void {
    $clean = new ApiSpec('Clean', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/ping')->withResponses([new Response(200, 'OK')]),
        ]),
    ]);

    expect(generated($clean))->not->toHaveKey('errors');
});

it('never overwrites a page the author wrote', function (): void {
    $authored = [new Page(id: 'introduction', title: 'Introduction', markdown: 'Mine.')];

    expect(generated(pagesSpec(), $authored))->not->toHaveKey('introduction')
        ->and(generated(pagesSpec(), $authored))->toHaveKey('authentication');
});

it('orders the generated pages so introduction comes first', function (): void {
    $orders = array_map(fn (Page $p): int => $p->order, DefaultPages::fill(pagesSpec(), []));

    expect($orders)->toBe([10, 20, 30, 40]);
});

it('builds a rate limiting page from throttle middleware', function (): void {
    $spec = new ApiSpec('Throttled', groups: [
        new Group('Widgets', [
            Endpoint::make(HttpMethod::Get, 'api/widgets', 'w.index')
                ->with(group: 'Widgets', rateLimit: new RateLimit(maxAttempts: 60)),
            Endpoint::make(HttpMethod::Post, 'api/uploads', 'u.store')
                ->with(group: 'Uploads', rateLimit: new RateLimit(maxAttempts: 5, perMinutes: 10)),
        ]),
    ]);

    $page = generated($spec)['rate-limiting']->markdown;

    expect($page)->toContain('| 60 requests per minute | Widgets |')
        ->toContain('| 5 requests per 10 minutes | Uploads |')
        ->toContain('Retry-After');
});

it('states a single global limit as a sentence rather than a table', function (): void {
    $limit = new RateLimit(maxAttempts: 60);

    $spec = new ApiSpec('Uniform', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/a', 'a')->with(rateLimit: $limit),
            Endpoint::make(HttpMethod::Get, 'api/b', 'b')->with(rateLimit: $limit),
        ]),
    ]);

    expect(generated($spec)['rate-limiting']->markdown)
        ->toContain('Every endpoint allows **60 requests per minute**.')
        ->and(generated($spec)['rate-limiting']->markdown)->not->toContain('| Limit |');
});

it('still writes a rate limiting page when only a 429 response reveals it', function (): void {
    expect(generated(pagesSpec()))->toHaveKey('rate-limiting');
});

it('omits rate limiting entirely when nothing throttles and nothing returns 429', function (): void {
    // Claiming an API is rate limited would be a guess about someone's
    // infrastructure, not a fact from their code.
    $spec = new ApiSpec('Open', groups: [
        new Group('G', [Endpoint::make(HttpMethod::Get, 'api/ping')]),
    ]);

    expect(generated($spec))->not->toHaveKey('rate-limiting');
});

it('shows a public GET as the first request, never a destructive verb', function (): void {
    // A DELETE at the top of an introduction is a hostile thing to hand a reader.
    $markdown = generated(pagesSpec())['introduction']->markdown;

    expect($markdown)->toContain("curl -X GET 'https://api.widgets.test/api/widgets'")
        ->toContain('needs no credentials, so you can run it right now')
        ->and($markdown)->not->toContain('curl -X DELETE');
});

it('falls back to an authenticated GET when nothing is public', function (): void {
    $spec = new ApiSpec('Closed', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Delete, 'api/things/{thing}')->with(authenticated: true),
            Endpoint::make(HttpMethod::Get, 'api/things')->with(authenticated: true),
        ]),
    ]);

    expect(generated($spec)['introduction']->markdown)->toContain('curl -X GET');
});
