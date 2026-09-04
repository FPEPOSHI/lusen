<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Collect\RouteCollector;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\UserController;

function collector(array $config = []): RouteCollector
{
    return new RouteCollector(app('router'), $config);
}

function uris(array $candidates): array
{
    return array_map(fn ($c): string => $c->method->value.' '.$c->uri, $candidates);
}

beforeEach(function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::post('api/users', [UserController::class, 'index']);
    Route::get('api/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('web/dashboard', fn () => null);
    Route::get('sanctum/csrf-cookie', fn () => null);
    Route::get('api/documentation', fn () => null);
});

it('collects only routes matching the include patterns', function (): void {
    expect(uris(collector(['include' => ['api/*']])->collect()))
        ->not->toContain('GET web/dashboard');
});

it('applies the exclude patterns after the include patterns', function (): void {
    $collected = uris(collector([
        'include' => ['api/*', 'sanctum/*'],
        'exclude' => ['sanctum/*', 'api/documentation*'],
    ])->collect());

    expect($collected)->not->toContain('GET sanctum/csrf-cookie')
        ->and($collected)->not->toContain('GET api/documentation');
});

it('never documents the HEAD route laravel pairs with every GET', function (): void {
    $methods = array_map(fn ($c): HttpMethod => $c->method, collector(['include' => ['api/*']])->collect());

    expect($methods)->not->toContain(HttpMethod::Head);
});

it('splits a multi-verb route into one candidate per verb', function (): void {
    $collected = uris(collector(['include' => ['api/*']])->collect());

    expect($collected)->toContain('GET api/users')
        ->toContain('POST api/users');
});

it('orders candidates by uri then method so output never depends on registration order', function (): void {
    expect(uris(collector(['include' => ['api/*']])->collect()))->toBe([
        'GET api/documentation',
        'GET api/users',
        'POST api/users',
        'GET api/users/{user}',
    ]);
});

it('excludes routes by name pattern', function (): void {
    $collected = uris(collector([
        'include' => ['api/*'],
        'exclude_names' => ['users.show'],
    ])->collect());

    expect($collected)->not->toContain('GET api/users/{user}');
});

it('requires every configured middleware when the filter is set', function (): void {
    Route::middleware('auth:sanctum')->get('api/secret', fn () => null);

    $collected = uris(collector([
        'include' => ['api/*'],
        'middleware' => ['auth:sanctum'],
    ])->collect());

    expect($collected)->toBe(['GET api/secret']);
});

it('resolves the controller and action off a candidate', function (): void {
    $candidate = collect(collector(['include' => ['api/*']])->collect())
        ->firstWhere(fn ($c): bool => $c->uri === 'api/users' && $c->method === HttpMethod::Get);

    expect($candidate->controller)->toBe(UserController::class)
        ->and($candidate->action)->toBe('index')
        ->and($candidate->name)->toBe('users.index')
        ->and($candidate->isClosure())->toBeFalse();
});

it('reports a closure route as having no controller', function (): void {
    $candidate = collect(collector(['include' => ['api/*']])->collect())
        ->firstWhere(fn ($c): bool => $c->uri === 'api/documentation');

    expect($candidate->isClosure())->toBeTrue()
        ->and($candidate->sourceFile())->toBeNull();
});

it('sees through a middleware group to the throttle inside it', function (): void {
    // Laravel's own `api` group carries the throttle every request is subject
    // to. gatherMiddleware() reports the group's name and nothing else, so a
    // rate limit that applies to the whole API was invisible.
    app('router')->middlewareGroup('metered', ['throttle:120,1', 'auth:sanctum']);

    Route::middleware('metered')->get('api/metered', fn () => null)->name('metered');

    $endpoint = app(SpecBuilder::class)->build()->endpoint('metered');

    expect($endpoint?->rateLimit?->label())->toBe('120 requests per minute')
        // The same blindness hid authentication declared in a group.
        ->and($endpoint?->authenticated)->toBeTrue();
});

it('keeps the group name too, so a middleware filter still matches it', function (): void {
    app('router')->middlewareGroup('metered', ['throttle:120,1']);

    Route::middleware('metered')->get('api/kept', fn () => null)->name('kept');

    config()->set('lusen.routes.middleware', ['metered']);

    expect(app(SpecBuilder::class)->build()->endpoint('kept'))->not->toBeNull();
});
