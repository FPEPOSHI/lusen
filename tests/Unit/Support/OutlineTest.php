<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Support\Outline;

function outlineEndpoint(): Endpoint
{
    return new Endpoint(id: 'users.index', method: HttpMethod::Get, uri: 'api/users');
}

it('names a parameter section after the place the parameters go', function (): void {
    expect(Outline::parameterHeading(ParameterLocation::Body))->toBe('Body parameters')
        ->and(Outline::parameterHeading(ParameterLocation::Query))->toBe('Query parameters');
});

it('prefixes anchors with the endpoint, because runtime renders them all on one page', function (): void {
    expect(Outline::id(outlineEndpoint(), 'Responses'))->toBe('users-index-responses')
        ->and(Outline::id(outlineEndpoint(), 'Example request'))->toBe('users-index-example-request');
});
