<?php

declare(strict_types=1);

use Lusen\Ir\SecurityScheme;

it('states scopes in the sentence a page prints', function (): void {
    $scheme = new SecurityScheme(type: SecurityScheme::BEARER, scopes: ['orders:write']);

    expect($scheme->instruction())->toBe('Send a bearer token with the `orders:write` scope in the Authorization header.')
        ->and($scheme->phrase())->toBe('bearer token with the `orders:write` scope');
});

it('keeps the plain bearer sentence unchanged', function (): void {
    expect((new SecurityScheme)->instruction())->toBe('Send a bearer token in the Authorization header.')
        ->and((new SecurityScheme)->phrase())->toBe('bearer token');
});

it('names the header for an api key and the credentials for basic auth', function (): void {
    expect((new SecurityScheme(type: SecurityScheme::API_KEY, headers: ['X-Client-Id']))->instruction())
        ->toBe('Send an API key in the `X-Client-Id` header.')
        ->and((new SecurityScheme(type: SecurityScheme::BASIC))->instruction())
        ->toBe('Send HTTP basic credentials in the Authorization header.')
        ->and((new SecurityScheme(type: SecurityScheme::BASIC))->phrase())
        ->toBe('HTTP basic authentication');
});
