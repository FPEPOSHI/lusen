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
