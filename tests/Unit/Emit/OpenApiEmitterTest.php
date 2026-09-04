<?php

declare(strict_types=1);

use Lusen\Emit\OpenApiEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Group;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;

it('emits a single openapi.json', function (): void {
    $files = (new OpenApiEmitter)->emit(fixtureSpec());

    expect($files)->toHaveCount(1)
        ->and($files[0]->path)->toBe('openapi.json')
        ->and($files[0]->contentType)->toBe('application/json');
});

it('declares openapi 3.1 and carries the spec info across', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info'])->toBe([
            'title' => 'Test API',
            'version' => '2.1.0',
            'description' => 'Fixture API used by the test suite.',
        ])
        ->and($document['servers'])->toBe([['url' => 'https://api.test']]);
});

it('keys paths by templated uri and nests operations under the lowercase verb', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());

    expect($document['paths'])->toHaveKeys(['/api/users', '/api/users/{user}'])
        ->and($document['paths']['/api/users'])->toHaveKeys(['get', 'post']);
});

it('uses the stable endpoint id as the operationId', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());

    expect($document['paths']['/api/users']['get']['operationId'])->toBe('users.index');
});

it('folds body parameters into a requestBody with a required list', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());
    $body = $document['paths']['/api/users']['post']['requestBody'];

    expect($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema']['required'])->toBe(['email'])
        ->and($body['content']['application/json']['schema']['properties'])->toHaveKeys(['email', 'role'])
        ->and($body['content']['application/json']['schema']['properties']['role']['enum'])->toBe(['admin', 'member']);
});

it('does not also list body parameters as openapi parameters', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());

    expect($document['paths']['/api/users']['post'])->not->toHaveKey('parameters');
});

it('marks path parameters required regardless of the ir flag', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());
    $parameters = $document['paths']['/api/users/{user}']['get']['parameters'];

    expect($parameters[0]['name'])->toBe('user')
        ->and($parameters[0]['in'])->toBe('path')
        ->and($parameters[0]['required'])->toBeTrue();
});

it('adds a bearer security scheme only when some endpoint needs auth', function (): void {
    $withAuth = (new OpenApiEmitter)->document(fixtureSpec());
    $withoutAuth = (new OpenApiEmitter)->document(new ApiSpec('Public'));

    expect($withAuth['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer')
        ->and($withAuth['paths']['/api/users']['post']['security'])->toBe([['bearerAuth' => []]])
        ->and($withoutAuth)->not->toHaveKey('components');
});

it('emits a valid responses object even when the ir has no responses', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());

    expect($document['paths']['/api/users/{user}']['get']['responses'])->toBe(['200' => ['description' => 'OK']]);
});

it('carries response examples through under a slugged key', function (): void {
    $document = (new OpenApiEmitter)->document(fixtureSpec());
    $content = $document['paths']['/api/users']['get']['responses']['200']['content'];

    expect($content['application/json']['examples']['default']['value'])->toBe(['data' => [['id' => 1]]]);
});

it('renders nullable as a 3.1 type union rather than a nullable flag', function (): void {
    $spec = new ApiSpec('Nullable', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/thing')->withParameters([
                new Parameter(
                    name: 'q',
                    in: ParameterLocation::Query,
                    schema: new Schema(nullable: true),
                ),
            ]),
        ]),
    ]);

    $schema = (new OpenApiEmitter)->document($spec)['paths']['/api/thing']['get']['parameters'][0]['schema'];

    expect($schema['type'])->toBe(['string', 'null'])
        ->and($schema)->not->toHaveKey('nullable');
});

it('translates ir constraint names to json schema keywords', function (): void {
    $spec = new ApiSpec('Constraints', groups: [
        new Group('G', [
            Endpoint::make(HttpMethod::Get, 'api/thing')->withParameters([
                new Parameter(
                    name: 'page',
                    in: ParameterLocation::Query,
                    schema: new Schema(
                        type: SchemaType::Integer,
                        constraints: ['min' => 1, 'max' => 100],
                    ),
                ),
            ]),
        ]),
    ]);

    $schema = (new OpenApiEmitter)->document($spec)['paths']['/api/thing']['get']['parameters'][0]['schema'];

    expect($schema)->toHaveKeys(['minimum', 'maximum'])
        ->and($schema['minimum'])->toBe(1);
});

it('produces byte-identical output for an unchanged spec', function (): void {
    expect((new OpenApiEmitter)->emit(fixtureSpec())[0]->contents)
        ->toBe((new OpenApiEmitter)->emit(fixtureSpec())[0]->contents);
});
