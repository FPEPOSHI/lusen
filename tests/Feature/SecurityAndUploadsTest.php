<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Group;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;
use Lusen\SpecBuilder;
use Lusen\Support\Snippets;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\UserController;

function secSpec()
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::middleware('auth:sanctum')->get('api/plain', [UserController::class, 'index'])->name('s.plain');
    Route::middleware(['auth:api', 'scopes:orders:read,orders:write'])
        ->get('api/scoped', [UserController::class, 'index'])->name('s.scoped');
    Route::middleware(['auth:sanctum', 'abilities:posts:write'])
        ->get('api/abilities', [UserController::class, 'index'])->name('s.abilities');
    Route::middleware('auth.basic')->get('api/basic', [UserController::class, 'index'])->name('s.basic');
    Route::middleware('throttle:5,1')->post('api/throttled', [UserController::class, 'index'])->name('s.throttled');
    Route::post('api/avatar', [OrderController::class, 'upload'])->name('s.upload');
});

it('keeps a plain authenticated endpoint on bearer', function (): void {
    expect(secSpec()->endpoint('s.plain')?->securityScheme()?->type)->toBe(SecurityScheme::BEARER);
});

it('reads passport scopes rather than reducing them to a boolean', function (): void {
    // "requires the orders:write scope" answers a question that "requires
    // authentication" only raises.
    $scheme = secSpec()->endpoint('s.scoped')?->securityScheme();

    expect($scheme?->type)->toBe(SecurityScheme::OAUTH2)
        ->and($scheme?->scopes)->toBe(['orders:read', 'orders:write'])
        ->and($scheme?->label())->toContain('`orders:read`');
});

it('reads sanctum abilities as bearer scopes', function (): void {
    $scheme = secSpec()->endpoint('s.abilities')?->securityScheme();

    expect($scheme?->type)->toBe(SecurityScheme::BEARER)
        ->and($scheme?->scopes)->toBe(['posts:write']);
});

it('detects basic auth instead of assuming bearer', function (): void {
    expect(secSpec()->endpoint('s.basic')?->securityScheme()?->type)->toBe(SecurityScheme::BASIC);
});

it('emits every scheme the api actually uses', function (): void {
    $schemes = (new OpenApiEmitter)->document(secSpec())['components']['securitySchemes'];

    expect($schemes)->toHaveKeys(['bearerAuth', 'oauth2', 'basicAuth'])
        ->and($schemes['basicAuth'])->toBe(['type' => 'http', 'scheme' => 'basic'])
        ->and($schemes['oauth2']['flows']['clientCredentials']['scopes'])
        ->toHaveKeys(['orders:read', 'orders:write']);
});

it('attaches scopes to the operation that needs them', function (): void {
    $document = (new OpenApiEmitter)->document(secSpec());

    expect($document['paths']['/api/scoped']['get']['security'])
        ->toBe([['oauth2' => ['orders:read', 'orders:write']]]);
});

it('types an uploaded file as binary', function (): void {
    $avatar = null;

    foreach (secSpec()->endpoint('s.upload')?->parameters ?? [] as $parameter) {
        if ($parameter->name === 'avatar') {
            $avatar = $parameter;
        }
    }

    expect($avatar?->schema->format)->toBe('binary')
        ->and($avatar?->required)->toBeTrue()
        ->and($avatar?->description)->toContain('Accepted types: jpg, png.');
});

it('sends a body with a file as multipart, not json', function (): void {
    // Describing an upload as JSON gives a client something that cannot work.
    $endpoint = secSpec()->endpoint('s.upload');

    expect($endpoint?->hasUpload())->toBeTrue()
        ->and($endpoint?->requestContentType())->toBe('multipart/form-data');

    $body = (new OpenApiEmitter)->document(secSpec())['paths']['/api/avatar']['post']['requestBody'];

    expect($body['content'])->toHaveKey('multipart/form-data');
});

it('leaves an ordinary body as json', function (): void {
    expect(secSpec()->endpoint('s.plain')?->requestContentType())->toBe('application/json');
});

it('reports the size limit on an upload', function (): void {
    foreach (secSpec()->endpoint('s.upload')?->parameters ?? [] as $parameter) {
        if ($parameter->name === 'avatar') {
            expect($parameter->description)->toContain('Maximum size 2048 KB.');
        }
    }
});

it('renders response headers into openapi', function (): void {
    // Where somebody handling a 429 will look, rather than only in prose.
    $spec = new ApiSpec('T', groups: [new Group('G', [
        Endpoint::make(HttpMethod::Get, 'api/limited', 'limited')
            ->withResponses([new Response(429, 'Too many requests.', headers: [
                'Retry-After' => new Schema(
                    type: SchemaType::Integer,
                    description: 'Seconds to wait before retrying.',
                ),
            ])]),
    ])]);

    $headers = (new OpenApiEmitter)->document($spec)['paths']['/api/limited']['get']['responses']['429']['headers'];

    expect($headers['Retry-After']['description'])->toBe('Seconds to wait before retrying.')
        ->and($headers['Retry-After']['schema']['type'])->toBe('integer');
});

it('adds Retry-After to a throttled endpoint that returns 429', function (): void {
    Route::middleware('throttle:5,1')->get('api/rl', [UserController::class, 'throttled'])->name('s.rl');

    $responses = secSpec()->endpoint('s.rl')?->responses ?? [];

    expect($responses[0]->status)->toBe(429)
        ->and($responses[0]->headers)->toHaveKey('Retry-After');
});

it('documents a client id and secret header pair', function (): void {
    // Two headers required together is two schemes ANDed, not one scheme with
    // two names. Middleware cannot reveal this, so it is declared.
    config()->set('lusen.auth', ['scheme' => 'apiKey', 'headers' => ['X-Client-Id', 'X-Client-Secret']]);
    Route::middleware('auth:sanctum')->get('api/keyed', [UserController::class, 'index'])->name('s.keyed');

    $scheme = secSpec()->endpoint('s.keyed')?->securityScheme();

    expect($scheme?->type)->toBe(SecurityScheme::API_KEY)
        ->and($scheme?->headerNames())->toBe(['X-Client-Id', 'X-Client-Secret'])
        ->and($scheme?->label())->toBe('The `X-Client-Id` and `X-Client-Secret` headers');

    $document = (new OpenApiEmitter)->document(secSpec());

    expect($document['components']['securitySchemes']['xClientId'])
        ->toBe(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Client-Id'])
        ->and($document['paths']['/api/keyed']['get']['security'])
        ->toBe([['xClientId' => [], 'xClientSecret' => []]]);
});

it('sends the declared headers in the example request', function (): void {
    config()->set('lusen.auth', ['scheme' => 'apiKey', 'headers' => ['X-Client-Id', 'X-Client-Secret']]);
    Route::middleware('auth:sanctum')->get('api/keyed', [UserController::class, 'index'])->name('s.keyed');

    $curl = Snippets::curl(secSpec()->endpoint('s.keyed'), 'https://api.test');

    expect($curl)->toContain("-H 'X-Client-Id: YOUR_CLIENT_ID'")
        ->toContain("-H 'X-Client-Secret: YOUR_CLIENT_SECRET'")
        ->and($curl)->not->toContain('Authorization');
});

it('describes the declared scheme on the generated authentication page', function (): void {
    // Only the keyed route is in scope, so the page has one scheme to describe.
    config()->set('lusen.auth', ['scheme' => 'apiKey', 'headers' => ['X-Client-Id', 'X-Client-Secret']]);
    config()->set('lusen.routes.include', ['api/keyed']);
    Route::middleware('auth:sanctum')->get('api/keyed', [UserController::class, 'index'])->name('s.keyed');

    expect(secSpec()->page('authentication')?->markdown)
        ->toContain('X-Client-Id: YOUR_CLIENT_ID')
        ->and(secSpec()->page('authentication')?->markdown)->not->toContain('Bearer YOUR_TOKEN');
});

it('documents basic auth with a real Authorization example', function (): void {
    config()->set('lusen.auth', ['scheme' => 'basic']);
    Route::middleware('auth')->get('api/b', [UserController::class, 'index'])->name('s.b');

    expect(Snippets::curl(secSpec()->endpoint('s.b')))
        ->toContain("-H 'Authorization: Basic ");
});
