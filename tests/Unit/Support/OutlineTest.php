<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\Outline;

function outlineEndpoint(array $parameters = [], array $responses = []): Endpoint
{
    return new Endpoint(
        id: 'users.index',
        method: HttpMethod::Get,
        uri: 'api/users',
        parameters: $parameters,
        responses: $responses,
    );
}

it('lists only the sections the endpoint actually has', function (): void {
    $sections = Outline::forEndpoint(outlineEndpoint());

    // Every endpoint gets an example; nothing else is guaranteed.
    expect(array_column($sections, 'text'))->toBe(['Example request']);
});

it('names each section and anchors it under the endpoint', function (): void {
    $sections = Outline::forEndpoint(outlineEndpoint(
        parameters: [new Parameter(name: 'per_page', in: ParameterLocation::Query, schema: new Schema)],
        responses: [new Response(status: 200)],
    ));

    expect($sections)->toBe([
        ['level' => 2, 'text' => 'Query parameters', 'id' => 'users-index-query-parameters'],
        ['level' => 2, 'text' => 'Example request', 'id' => 'users-index-example-request'],
        ['level' => 2, 'text' => 'Responses', 'id' => 'users-index-responses'],
    ]);
});

it('orders parameter sections the way the page renders them', function (): void {
    $sections = Outline::forEndpoint(outlineEndpoint(parameters: [
        new Parameter(name: 'name', in: ParameterLocation::Body, schema: new Schema),
        new Parameter(name: 'per_page', in: ParameterLocation::Query, schema: new Schema),
        new Parameter(name: 'user', in: ParameterLocation::Path, schema: new Schema),
    ]));

    expect(array_column($sections, 'text'))
        ->toBe(['Path parameters', 'Query parameters', 'Body parameters', 'Example request']);
});

it('prefixes anchors with the endpoint, because runtime renders them all on one page', function (): void {
    expect(Outline::id(outlineEndpoint(), 'Responses'))->toBe('users-index-responses');
});
