<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\HiddenController;
use Lusen\Tests\Fixtures\UserController;

function buildSpec()
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::middleware('auth:sanctum')->get('api/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('api/users/metrics', [UserController::class, 'internalMetrics']);
    Route::get('api/hidden', [HiddenController::class, 'index']);
    Route::get('api/orders/{order}/lines', fn () => null)->name('orders.lines');
});

it('takes the group name from the controller attribute', function (): void {
    expect(buildSpec()->endpoint('users.index')?->group)->toBe('Users');
});

it('takes summary and description from the ApiDoc attribute', function (): void {
    $endpoint = buildSpec()->endpoint('users.index');

    expect($endpoint?->summary)->toBe('List users')
        ->and($endpoint?->description)->toBe('A paginated list of every user.');
});

it('drops an endpoint marked Hidden at method level', function (): void {
    expect(buildSpec()->endpoint('get-api-users-metrics'))->toBeNull();
});

it('drops every endpoint on a controller marked Hidden', function (): void {
    expect(buildSpec()->endpoint('get-api-hidden'))->toBeNull();
});

it('reads declared query parameters off the attributes', function (): void {
    $query = buildSpec()->endpoint('users.index')?->parametersIn(ParameterLocation::Query);

    expect($query)->toHaveCount(2)
        ->and($query[0]->name)->toBe('per_page')
        ->and($query[0]->schema->type->value)->toBe('integer')
        ->and($query[0]->schema->example)->toBe(25)
        ->and($query[1]->schema->enum)->toBe(['active', 'invited']);
});

it('reads declared responses off the attributes and orders them by status', function (): void {
    $responses = buildSpec()->endpoint('users.show')?->responses;

    expect(array_map(fn ($r): int => $r->status, $responses))->toBe([200, 404])
        ->and($responses[1]->description)->toBe('No user with that id.');
});

it('infers path parameters from the uri', function (): void {
    $path = buildSpec()->endpoint('orders.lines')?->parametersIn(ParameterLocation::Path);

    expect($path)->toHaveCount(1)
        ->and($path[0]->name)->toBe('order')
        ->and($path[0]->required)->toBeTrue();
});

it('infers authentication from auth middleware', function (): void {
    expect(buildSpec()->endpoint('users.show')?->authenticated)->toBeTrue()
        ->and(buildSpec()->endpoint('users.index')?->authenticated)->toBeFalse();
});

it('falls back to the first meaningful uri segment for the group', function (): void {
    expect(buildSpec()->endpoint('orders.lines')?->group)->toBe('Orders');
});

it('never leaves an endpoint without a summary', function (): void {
    foreach (buildSpec()->endpoints() as $endpoint) {
        expect($endpoint->summary)->not->toBeNull();
    }
});

it('carries the spec identity through from config', function (): void {
    $spec = buildSpec();

    expect($spec->title)->toBe('Test API')
        ->and($spec->version)->toBe('2.1.0')
        ->and($spec->baseUrl)->toBe('https://api.test');
});

it('groups endpoints alphabetically by group name', function (): void {
    expect(array_map(fn ($g): string => $g->name, buildSpec()->groups))->toBe(['Orders', 'Users']);
});

it('types a route-model-bound path parameter as an integer', function (): void {
    // /users/{user} binds on the primary key, so documenting it as a string
    // yields the useless example /api/users/user.
    Route::get('api/customers/{customer}', fn () => null)->name('customers.show');

    $path = buildSpec()->endpoint('customers.show')?->parametersIn(ParameterLocation::Path);

    expect($path[0]->schema->type->value)->toBe('integer');
});

it('leaves an unrelated path parameter as a string', function (): void {
    Route::get('api/reports/{slug}', fn () => null)->name('reports.show');

    $path = buildSpec()->endpoint('reports.show')?->parametersIn(ParameterLocation::Path);

    expect($path[0]->schema->type->value)->toBe('string');
});
