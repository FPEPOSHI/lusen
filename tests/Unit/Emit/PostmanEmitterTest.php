<?php

declare(strict_types=1);

use Lusen\Emit\PostmanEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;

function postman(): array
{
    return (new PostmanEmitter)->collection(fixtureSpec());
}

function requestNamed(string $name): array
{
    foreach (postman()['item'] as $folder) {
        foreach ($folder['item'] as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }
    }

    return [];
}

it('emits one collection file', function (): void {
    $file = (new PostmanEmitter)->emit(fixtureSpec())[0];

    expect($file->path)->toBe('postman.json')
        ->and($file->contentType)->toBe('application/json')
        ->and(json_decode($file->contents, true))->toHaveKeys(['info', 'item']);
});

it('declares the v2.1 schema', function (): void {
    expect(postman()['info']['schema'])
        ->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
});

it('keeps its id stable across builds', function (): void {
    // A fresh id on every build would make Postman treat each import as a new
    // collection rather than an update.
    expect(postman()['info']['_postman_id'])->toBe(postman()['info']['_postman_id'])
        ->and(postman()['info']['_postman_id'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('groups requests into folders', function (): void {
    expect(postman()['item'][0]['name'])->toBe('Users')
        ->and(array_column(postman()['item'][0]['item'], 'name'))
        ->toBe(['List users', 'Create a user', 'Show a user']);
});

it('puts the host in a baseUrl variable so environments can be switched', function (): void {
    $variables = array_column(postman()['variable'], 'value', 'key');

    expect($variables['baseUrl'])->toBe('https://api.test')
        ->and(requestNamed('List users')['request']['url']['host'])->toBe(['{{baseUrl}}']);
});

it('ships an empty token variable rather than a filled-in one', function (): void {
    // A collection with a real token in it is a collection someone commits.
    $variables = array_column(postman()['variable'], 'value', 'key');

    expect($variables['token'])->toBe('');
});

it('declares bearer auth once on the collection', function (): void {
    expect(postman()['auth']['type'])->toBe('bearer')
        ->and(postman()['auth']['bearer'][0]['value'])->toBe('{{token}}');
});

it('marks only the public endpoints as noauth', function (): void {
    expect(requestNamed('List users')['request']['auth']['type'])->toBe('noauth')
        ->and(requestNamed('Create a user')['request'])->not->toHaveKey('auth');
});

it('translates laravel path placeholders to postman variables', function (): void {
    $url = requestNamed('Show a user')['request']['url'];

    expect($url['path'])->toBe(['api', 'users', ':user'])
        ->and($url['variable'][0]['key'])->toBe('user')
        ->and($url['variable'][0]['value'])->toBe('1');
});

it('includes optional query parameters but leaves them disabled', function (): void {
    // Toggling a parameter beats looking it up and retyping it.
    $query = requestNamed('List users')['request']['url']['query'];

    expect($query[0]['key'])->toBe('per_page')
        ->and($query[0]['disabled'])->toBeTrue()
        ->and($query[0]['description'])->toContain('Results per page.');
});

it('keeps a disabled parameter out of the raw url', function (): void {
    expect(requestNamed('List users')['request']['url']['raw'])->toBe('{{baseUrl}}/api/users');
});

it('sends a json body built from the body parameters', function (): void {
    $body = requestNamed('Create a user')['request']['body'];

    expect($body['mode'])->toBe('raw')
        ->and($body['options']['raw']['language'])->toBe('json')
        ->and(json_decode($body['raw'], true))->toBe([
            'email' => 'jane@example.com',
            'role' => 'admin',
        ]);
});

it('sets a content type header only when there is a body', function (): void {
    $withBody = array_column(requestNamed('Create a user')['request']['header'], 'value', 'key');
    $without = array_column(requestNamed('List users')['request']['header'], 'value', 'key');

    expect($withBody)->toHaveKey('Content-Type')
        ->and($without)->not->toHaveKey('Content-Type')
        ->and($without['Accept'])->toBe('application/json');
});

it('documents body parameters as a markdown table', function (): void {
    // Postman renders markdown; without this the fields exist only as keys in
    // the example body, with no types.
    expect(requestNamed('Create a user')['request']['description'])
        ->toContain('| Field | Type | Required |')
        ->toContain('| `email` | string<email> | yes |');
});

it('imports documented responses as saved examples', function (): void {
    $examples = requestNamed('List users')['response'];

    expect($examples[0]['name'])->toBe('200 OK')
        ->and($examples[0]['code'])->toBe(200)
        ->and($examples[0]['body'])->toContain('"data"');
});

it('omits auth entirely when no endpoint needs it', function (): void {
    $spec = new ApiSpec('Open', groups: [
        new Group('G', [Endpoint::make(HttpMethod::Get, 'api/ping')]),
    ]);

    $collection = (new PostmanEmitter)->collection($spec);

    expect($collection)->not->toHaveKey('auth')
        ->and(array_column($collection['variable'], 'key'))->toBe(['baseUrl']);
});

it('is deterministic', function (): void {
    expect((new PostmanEmitter)->emit(fixtureSpec())[0]->contents)
        ->toBe((new PostmanEmitter)->emit(fixtureSpec())[0]->contents);
});
