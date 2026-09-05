<?php

declare(strict_types=1);

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;
use Lusen\Support\TryIt;

function tryItEndpoint(HttpMethod $method = HttpMethod::Get, ?bool $tryIt = null): Endpoint
{
    return new Endpoint(id: 'users.index', method: $method, uri: 'api/users', tryIt: $tryIt);
}

it('stays off until a site turns it on', function (mixed $config): void {
    expect(TryIt::enabled(tryItEndpoint(), $config))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [[]],
    'explicitly off' => [['enabled' => false]],
    'truthy but not true' => [['enabled' => 1]],
]);

it('offers only the methods the site allows', function (): void {
    $config = ['enabled' => true, 'methods' => ['GET']];

    expect(TryIt::enabled(tryItEndpoint(HttpMethod::Get), $config))->toBeTrue()
        ->and(TryIt::enabled(tryItEndpoint(HttpMethod::Delete), $config))->toBeFalse();
});

it('reads the method list case-insensitively', function (): void {
    expect(TryIt::enabled(tryItEndpoint(HttpMethod::Post), ['enabled' => true, 'methods' => ['get', 'post']]))->toBeTrue();
});

it('lets an endpoint withdraw, but never opt in', function (): void {
    // A package that shipped a Send button nobody asked for would deserve
    // everything that followed, so the endpoint can only ever subtract.
    expect(TryIt::enabled(tryItEndpoint(tryIt: false), ['enabled' => true, 'methods' => ['GET']]))->toBeFalse()
        ->and(TryIt::enabled(tryItEndpoint(tryIt: true), ['enabled' => false]))->toBeFalse();
});

it('hands the script only the settings it needs', function (): void {
    expect(TryIt::options(['enabled' => true, 'credentials' => true, 'persist_token' => 'none'], 'https://api.test/'))
        ->toBe(['credentials' => true, 'persist' => 'none', 'baseUrl' => 'https://api.test'])
        ->and(TryIt::options([]))->toBe(['credentials' => false, 'persist' => 'session', 'baseUrl' => ''])
        // A value nobody recognises lands on the documented default rather
        // than on whichever branch happened to be last.
        ->and(TryIt::options(['persist_token' => 'forever'])['persist'])->toBe('session')
        ->and(TryIt::options(['persist_token' => 'local'])['persist'])->toBe('local');
});

it('finds the credentials the api asks for', function (): void {
    // One field in the sidebar rather than one per page: an API that wants a
    // bearer token wants the same one everywhere.
    $spec = new ApiSpec('Test API', groups: [new Group(name: 'Users', endpoints: [
        new Endpoint(id: 'users.index', method: HttpMethod::Get, uri: 'api/users'),
        new Endpoint(id: 'users.show', method: HttpMethod::Get, uri: 'api/users/{user}', authenticated: true),
    ])]);

    expect(TryIt::auth($spec))->toBe(['scheme' => 'bearer', 'headers' => ['Authorization']]);
});

it('asks for nothing when nothing is authenticated', function (): void {
    $spec = new ApiSpec('Test API', groups: [new Group(name: 'Users', endpoints: [
        new Endpoint(id: 'users.index', method: HttpMethod::Get, uri: 'api/users'),
    ])]);

    expect(TryIt::auth($spec))->toBeNull();
});
