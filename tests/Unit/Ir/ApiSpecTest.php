<?php

declare(strict_types=1);

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\RateLimit;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;

it('round-trips through an array without losing anything', function (): void {
    $spec = fixtureSpec();

    expect(ApiSpec::fromArray($spec->toArray())->toArray())->toBe($spec->toArray());
});

it('round-trips through json', function (): void {
    $spec = fixtureSpec();

    expect(ApiSpec::fromJson($spec->toJson())->toJson())->toBe($spec->toJson());
});

it('hashes deterministically so unchanged rebuilds are byte-identical', function (): void {
    expect(fixtureSpec()->hash())->toBe(fixtureSpec()->hash());
});

it('changes its hash when anything in the spec changes', function (): void {
    $spec = fixtureSpec();
    $renamed = new ApiSpec(title: 'Other API', version: $spec->version, groups: $spec->groups);

    expect($renamed->hash())->not->toBe($spec->hash());
});

it('flattens endpoints across groups in group order', function (): void {
    $spec = fixtureSpec();

    expect($spec->endpoints())->toHaveCount(3)
        ->and($spec->endpoints()[0]->id)->toBe('users.index');
});

it('returns no endpoints for a spec with no groups', function (): void {
    expect((new ApiSpec('Empty'))->endpoints())->toBe([]);
});

it('looks endpoints up by their stable id', function (): void {
    expect(fixtureSpec()->endpoint('users.show')?->summary)->toBe('Show a user')
        ->and(fixtureSpec()->endpoint('nope'))->toBeNull();
});

it('looks groups up by slug', function (): void {
    expect(fixtureSpec()->group('users')?->name)->toBe('Users');
});

it('omits empty values from the serialised form', function (): void {
    $array = Endpoint::make(HttpMethod::Get, 'api/ping')->toArray();

    expect($array)->not->toHaveKeys(['parameters', 'responses', 'authenticated', 'tags']);
});

it('derives a stable id from the route name when there is one', function (): void {
    expect(Endpoint::deriveId(HttpMethod::Get, 'api/users/{user}', 'users.show'))->toBe('users.show');
});

it('derives a stable id from method and uri when there is no route name', function (): void {
    expect(Endpoint::deriveId(HttpMethod::Get, 'api/users/{user}'))->toBe('get-api-users-user');
});

it('builds a filesystem-safe slug from the id', function (): void {
    expect(Endpoint::make(HttpMethod::Get, 'api/users', 'users.index')->slug())->toBe('users-index');
});

it('titles an endpoint from its summary, falling back to method and path', function (): void {
    expect(Endpoint::make(HttpMethod::Delete, 'api/users/{user}')->title())->toBe('DELETE /api/users/{user}')
        ->and(Endpoint::make(HttpMethod::Get, 'api/users')->with(summary: 'List users')->title())->toBe('List users');
});

it('separates parameters by location', function (): void {
    $endpoint = fixtureSpec()->endpoint('users.store');

    expect($endpoint?->parametersIn(ParameterLocation::Body))->toHaveCount(2)
        ->and($endpoint?->parametersIn(ParameterLocation::Query))->toBe([])
        ->and($endpoint?->hasBody())->toBeTrue();
});

it('labels schemas for the parameter table', function (): void {
    expect(Schema::string('email')->label())->toBe('string<email>')
        ->and(Schema::enum(['admin', 'member'])->label())->toBe('string, one of admin, member')
        ->and((new Schema(type: SchemaType::Integer, nullable: true, constraints: ['min' => 1]))->label())
        ->toBe('integer, min 1, nullable');
});

it('falls back to a standard reason phrase when a response has no description', function (): void {
    expect((new Response(422))->label())->toBe('Unprocessable Entity')
        ->and((new Response(200, 'All good'))->label())->toBe('All good');
});

it('round-trips an endpoint with a rate limit and a security scheme', function (): void {
    // Both were being serialised and silently dropped on the way back, which
    // no round-trip test covered because the fixture spec has neither.
    $endpoint = Endpoint::make(HttpMethod::Post, 'api/uploads', 'uploads.store')
        ->with(
            authenticated: true,
            rateLimit: new RateLimit(maxAttempts: 5, perMinutes: 10),
            security: new SecurityScheme(type: 'oauth2', scopes: ['uploads:write']),
        );

    $restored = Endpoint::fromArray($endpoint->toArray());

    expect($restored->rateLimit?->label())->toBe('5 requests per 10 minutes')
        ->and($restored->security?->type)->toBe('oauth2')
        ->and($restored->security?->scopes)->toBe(['uploads:write'])
        ->and($restored->toArray())->toBe($endpoint->toArray());
});

it('round-trips response headers', function (): void {
    $response = new Response(429, 'Too many requests.', headers: [
        'Retry-After' => Schema::integer(),
    ]);

    expect(Response::fromArray($response->toArray())->headers)
        ->toHaveKey('Retry-After');
});
