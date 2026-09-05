<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;
use Lusen\Support\RequestModel;
use Lusen\Support\Snippets;

it('describes the same call the printed example prints', function (): void {
    // The two must not be able to disagree: one of them is copied into a
    // terminal and the other is sent from the page.
    $endpoint = new Endpoint(
        id: 'users.show',
        method: HttpMethod::Get,
        uri: 'api/users/{user}',
        parameters: [new Parameter(name: 'user', in: ParameterLocation::Path, schema: new Schema(SchemaType::Integer), required: true)],
        authenticated: true,
        security: new SecurityScheme,
    );

    $model = RequestModel::for($endpoint, 'https://api.test');

    expect(Snippets::curl($endpoint, 'https://api.test'))->toContain($model['url'])
        ->and($model['headers'])->toHaveKey('Authorization')
        ->and($model['url'])->toBe('https://api.test/api/users/1');
});

it('keeps the path as a template, because the browser re-resolves it', function (): void {
    $endpoint = new Endpoint(
        id: 'users.show',
        method: HttpMethod::Get,
        uri: 'api/users/{user}',
        parameters: [new Parameter(name: 'user', in: ParameterLocation::Path, schema: new Schema(SchemaType::Integer), required: true)],
    );

    expect(RequestModel::for($endpoint)['path'])->toBe('/api/users/{user}');
});

it('names every auth header the scheme requires', function (): void {
    // A client id and secret pair is two fields in the form, not one.
    $endpoint = new Endpoint(
        id: 'orders.index',
        method: HttpMethod::Get,
        uri: 'api/orders',
        authenticated: true,
        security: new SecurityScheme(type: SecurityScheme::API_KEY, headers: ['X-Client-Id', 'X-Client-Secret']),
    );

    expect(RequestModel::for($endpoint)['auth'])
        ->toBe(['scheme' => 'apiKey', 'headers' => ['X-Client-Id', 'X-Client-Secret']]);
});

it('leaves an optional filter empty, as the printed example does', function (): void {
    $endpoint = new Endpoint(
        id: 'users.index',
        method: HttpMethod::Get,
        uri: 'api/users',
        parameters: [
            new Parameter(name: 'per_page', in: ParameterLocation::Query, schema: new Schema(SchemaType::Integer)),
            new Parameter(name: 'scope', in: ParameterLocation::Query, schema: new Schema, required: true),
        ],
    );

    $fields = RequestModel::for($endpoint)['fields'];

    expect($fields[0]['name'])->toBe('per_page')
        ->and($fields[0]['value'])->toBe('')
        ->and($fields[1]['value'])->not->toBe('');
});

it('carries the enum a field is limited to', function (): void {
    $endpoint = new Endpoint(
        id: 'orders.index',
        method: HttpMethod::Get,
        uri: 'api/orders',
        parameters: [new Parameter(
            name: 'status',
            in: ParameterLocation::Query,
            schema: Schema::enum(['pending', 'paid']),
            required: true,
        )],
    );

    expect(RequestModel::for($endpoint)['fields'][0]['enum'])->toBe(['pending', 'paid']);
});

it('offers no body field where there is no body', function (): void {
    $endpoint = new Endpoint(id: 'users.index', method: HttpMethod::Get, uri: 'api/users');

    expect(RequestModel::for($endpoint)['body'])->toBeNull();
});
