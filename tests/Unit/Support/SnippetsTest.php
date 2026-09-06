<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;
use Lusen\Support\RequestModel;
use Lusen\Support\Snippets;

it('substitutes path placeholders with example values', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/users/{user}')->withParameters([
        new Parameter('user', ParameterLocation::Path, Schema::integer(), true),
    ]);

    expect(RequestModel::url($endpoint, 'https://api.test'))->toBe('https://api.test/api/users/1');
});

it('handles laravel optional path syntax', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/users/{user?}')->withParameters([
        new Parameter('user', ParameterLocation::Path, Schema::integer()),
    ]);

    expect(RequestModel::url($endpoint))->toBe('/api/users/1');
});

it('puts only required query parameters in the url', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/users')->withParameters([
        new Parameter('scope', ParameterLocation::Query, Schema::string(), true),
        new Parameter('per_page', ParameterLocation::Query, Schema::integer()),
    ]);

    expect(RequestModel::url($endpoint))->toBe('/api/users?scope=scope')
        ->and(RequestModel::url($endpoint))->not->toContain('per_page');
});

it('adds a bearer header only for authenticated endpoints', function (): void {
    $open = Endpoint::make(HttpMethod::Get, 'api/ping');
    $closed = $open->with(authenticated: true);

    expect(Snippets::curl($closed))->toContain("-H 'Authorization: Bearer YOUR_TOKEN'")
        ->and(Snippets::curl($open))->not->toContain('Authorization');
});

it('always asks for json', function (): void {
    expect(Snippets::curl(Endpoint::make(HttpMethod::Get, 'api/ping')))
        ->toContain("-H 'Accept: application/json'");
});

it('adds a content-type and body only when there are body parameters', function (): void {
    $withBody = Endpoint::make(HttpMethod::Post, 'api/users')->withParameters([
        new Parameter('email', ParameterLocation::Body, Schema::string('email'), true),
    ]);

    expect(Snippets::curl($withBody))
        ->toContain("-H 'Content-Type: application/json'")
        ->toContain('"email": "jane@example.com"')
        ->and(Snippets::curl(Endpoint::make(HttpMethod::Get, 'api/users')))
        ->not->toContain('Content-Type');
});

it('includes declared header parameters', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/ping')->withParameters([
        new Parameter('X-Account', ParameterLocation::Header, Schema::string(), true),
    ]);

    expect(Snippets::curl($endpoint))->toContain("-H 'X-Account:");
});

it('emits a runnable multi-line curl command', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/users')
        ->with(authenticated: true)
        ->withParameters([
            new Parameter('email', ParameterLocation::Body, Schema::string('email'), true),
        ]);

    $curl = Snippets::curl($endpoint, 'https://api.test');

    expect($curl)->toStartWith("curl -X POST 'https://api.test/api/users'")
        ->and($curl)->toContain(" \\\n");
});

it('emits a fetch call for javascript', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/users')->withParameters([
        new Parameter('email', ParameterLocation::Body, Schema::string('email'), true),
    ]);

    $js = Snippets::javascript($endpoint, 'https://api.test');

    expect($js)->toStartWith("const response = await fetch('https://api.test/api/users', {")
        ->toContain("method: 'POST'")
        ->toContain('JSON.stringify(')
        ->toContain('await response.json()');
});

it('url-encodes example values in the path', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/users/{name}')->withParameters([
        new Parameter('name', ParameterLocation::Path, Schema::string(), true),
    ]);

    expect(RequestModel::url($endpoint))->toBe('/api/users/Jane%20Doe');
});

it('only offers languages it can actually produce', function (): void {
    // The config must not be able to promise a snippet that does not exist.
    expect(Snippets::languages(['curl', 'javascript', 'python', 'cobol']))
        ->toBe(['curl' => 'cURL', 'javascript' => 'JavaScript']);
});

it('keeps the configured order', function (): void {
    expect(array_keys(Snippets::languages(['javascript', 'curl'])))->toBe(['javascript', 'curl']);
});

it('falls back to curl for nonsense configuration', function (): void {
    expect(Snippets::languages([]))->toBe(['curl' => 'cURL'])
        ->and(Snippets::languages('nope'))->toBe(['curl' => 'cURL'])
        ->and(Snippets::languages(['python']))->toBe(['curl' => 'cURL']);
});

it('renders the language it is asked for', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Get, 'api/ping');

    expect(Snippets::render('javascript', $endpoint))->toStartWith('const response')
        ->and(Snippets::render('curl', $endpoint))->toStartWith('curl ')
        ->and(Snippets::render('unknown', $endpoint))->toStartWith('curl ');
});

it('offers both php clients, and drops a language it cannot render', function (): void {
    expect(Snippets::languages(['curl', 'laravel', 'guzzle']))
        ->toBe(['curl' => 'cURL', 'laravel' => 'PHP (Laravel)', 'guzzle' => 'PHP (Guzzle)']);

    // The config must not be able to promise a snippet that does not exist.
    expect(Snippets::languages(['curl', 'kotlin']))->toBe(['curl' => 'cURL']);
});

it('names the syntax each snippet is highlighted as', function (): void {
    // In Snippets rather than in the Blade that renders the tabs, or the next
    // language added is coloured as JavaScript until somebody notices.
    expect(Snippets::syntax('laravel'))->toBe('php')
        ->and(Snippets::syntax('guzzle'))->toBe('php')
        ->and(Snippets::syntax('javascript'))->toBe('javascript')
        ->and(Snippets::syntax('curl'))->toBe('bash');
});

it('writes a laravel http call with the headers the endpoint requires', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/users', 'users.store')->with(
        authenticated: true,
        parameters: [
            new Parameter('email', ParameterLocation::Body, Schema::string()->withExample('ada@example.com'), required: true),
        ],
    );

    $php = Snippets::laravel($endpoint, 'https://api.test');

    expect($php)->toContain('use Illuminate\Support\Facades\Http;')
        ->toContain("'Authorization' => 'Bearer YOUR_TOKEN',")
        ->toContain("->post('https://api.test/api/users', [")
        ->toContain("'email' => 'ada@example.com',")
        ->toContain('$data = $response->json();');
});

it('calls straight through when there is no body to send', function (): void {
    $php = Snippets::laravel(Endpoint::make(HttpMethod::Get, 'api/users'), 'https://api.test');

    expect($php)->toContain("->get('https://api.test/api/users');")
        ->not->toContain('[]');
});

it('writes a guzzle request with headers and a json body', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/users', 'users.store')->with(
        parameters: [
            new Parameter('tags', ParameterLocation::Body, Schema::arrayOf(Schema::string()->withExample('vip'))),
        ],
    );

    $php = Snippets::guzzle($endpoint, 'https://api.test');

    expect($php)->toContain('use GuzzleHttp\Client;')
        ->toContain("\$client->request('POST', 'https://api.test/api/users', [")
        ->toContain("'json' => [")
        ->toContain("'tags' => [")
        ->toContain('$data = json_decode((string) $response->getBody(), true);');
});

it('escapes a quote in an example rather than breaking the snippet', function (): void {
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/users')->withParameters([
        new Parameter('name', ParameterLocation::Body, Schema::string()->withExample("O'Brien"), required: true),
    ]);

    // A snippet that will not parse is worse than no snippet at all.
    expect(Snippets::laravel($endpoint))->toContain("'name' => 'O\\'Brien',")
        ->and(Snippets::guzzle($endpoint))->toContain("'name' => 'O\\'Brien',");
});
