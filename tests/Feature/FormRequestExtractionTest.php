<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Extract\AttributeExtractor;
use Lusen\Extract\FormRequestExtractor;
use Lusen\Extract\RouteExtractor;
use Lusen\Extract\Rules\FormRequestReader;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\SpecBuilder;
use Lusen\Support\Snippets;
use Lusen\Tests\Fixtures\OrderController;

function orderSpec()
{
    return app(SpecBuilder::class)->build();
}

function bodyParam(string $id, string $name): ?Parameter
{
    foreach (orderSpec()->endpoint($id)?->parameters ?? [] as $parameter) {
        if ($parameter->name === $name) {
            return $parameter;
        }
    }

    return null;
}

beforeEach(function (): void {
    FormRequestReader::flushCache();

    Route::post('api/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('api/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::put('api/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('api/orders/dynamic', [OrderController::class, 'dynamic'])->name('orders.dynamic');
    Route::get('api/orders/bare', [OrderController::class, 'bare'])->name('orders.bare');
});

it('documents body parameters from the form request rules', function (): void {
    $email = bodyParam('orders.store', 'email');

    expect($email?->in)->toBe(ParameterLocation::Body)
        ->and($email?->required)->toBeTrue()
        ->and($email?->schema->format)->toBe('email')
        ->and($email?->schema->constraints)->toBe(['maxLength' => 255]);
});

it('puts rules on a query endpoint into the query string, not a body', function (): void {
    $perPage = bodyParam('orders.index', 'per_page');

    expect($perPage?->in)->toBe(ParameterLocation::Query)
        ->and($perPage?->schema->type->value)->toBe('integer')
        ->and($perPage?->schema->constraints)->toBe(['min' => 1, 'max' => 100]);
});

it('reads Rule::enum by resolving the enum cases', function (): void {
    expect(bodyParam('orders.store', 'status')?->schema->enum)
        ->toBe(['pending', 'paid', 'refunded']);
});

it('reads Rule::in', function (): void {
    expect(bodyParam('orders.store', 'channel')?->schema->enum)->toBe(['web', 'pos']);
});

it('reads a plain in rule', function (): void {
    expect(bodyParam('orders.store', 'currency')?->schema->enum)->toBe(['USD', 'EUR', 'GBP']);
});

it('nests dot-notated rules into an object', function (): void {
    $customer = bodyParam('orders.store', 'customer');

    expect($customer?->schema->type->value)->toBe('object')
        ->and($customer?->schema->properties)->toHaveKeys(['name', 'vip'])
        ->and($customer?->schema->properties['name']->constraints)->toBe(['maxLength' => 120])
        ->and($customer?->schema->required)->toBe(['name']);
});

it('nests wildcard rules into an array of objects', function (): void {
    $items = bodyParam('orders.store', 'items');

    expect($items?->schema->type->value)->toBe('array')
        ->and($items?->schema->constraints)->toBe(['minItems' => 1, 'maxItems' => 20])
        ->and($items?->schema->items?->type->value)->toBe('object')
        ->and($items?->schema->items?->properties)->toHaveKeys(['product_id', 'quantity'])
        ->and($items?->schema->items?->required)->toBe(['product_id', 'quantity']);
});

it('handles a scalar wildcard as an array of scalars', function (): void {
    $tags = bodyParam('orders.store', 'tags');

    expect($tags?->schema->type->value)->toBe('array')
        ->and($tags?->schema->items?->type->value)->toBe('string')
        ->and($tags?->schema->items?->constraints)->toBe(['maxLength' => 30]);
});

it('omits a prohibited field entirely', function (): void {
    expect(bodyParam('orders.store', 'legacy_field'))->toBeNull();
});

it('skips a rule it cannot read statically without dropping the field list', function (): void {
    // 'callback' holds a closure; the rest of the request must still document.
    expect(bodyParam('orders.store', 'callback'))->toBeNull()
        ->and(bodyParam('orders.store', 'email'))->not->toBeNull();
});

it('degrades to no parameters when rules are built at runtime', function (): void {
    $endpoint = orderSpec()->endpoint('orders.dynamic');

    expect($endpoint)->not->toBeNull()
        ->and($endpoint?->parametersIn(ParameterLocation::Body))->toBe([]);
});

it('leaves an action with no form request alone', function (): void {
    expect(orderSpec()->endpoint('orders.bare')?->parameters)->toBe([]);
});

it('treats sometimes as optional', function (): void {
    expect(bodyParam('orders.store', 'notes')?->required)->toBeFalse();
});

it('records the form request file so incremental builds can watch it', function (): void {
    $files = orderSpec()->endpoint('orders.store')?->sourceFiles ?? [];

    expect(implode(' ', $files))->toContain('StoreOrderRequest.php');
});

it('does not duplicate a path parameter that the rules also mention', function (): void {
    $endpoint = orderSpec()->endpoint('orders.update');
    $named = array_filter($endpoint?->parameters ?? [], fn ($p): bool => $p->name === 'order');

    expect($named)->toHaveCount(1)
        ->and(reset($named)->in)->toBe(ParameterLocation::Path)
        ->and(reset($named)->required)->toBeTrue();
});

it('runs AttributeExtractor last, so an explicit annotation always wins', function (): void {
    // The invariant, rather than the exact list: adding an extractor should
    // not break this test, but reordering it past AttributeExtractor should.
    $extractors = config('lusen.extractors');

    expect(end($extractors))->toBe(AttributeExtractor::class)
        ->and($extractors[0])->toBe(RouteExtractor::class)
        ->and($extractors)->toContain(FormRequestExtractor::class);
});

it('produces a request body example from the derived schema', function (): void {
    $curl = Snippets::curl(orderSpec()->endpoint('orders.store'), 'https://api.test');

    expect($curl)->toContain("-X POST 'https://api.test/api/orders'")
        ->toContain('"email": "jane@example.com"')
        ->toContain('"status": "pending"');
});

it('round-trips a derived nested schema through the ir', function (): void {
    $spec = orderSpec();

    expect(ApiSpec::fromJson($spec->toJson())->toJson())->toBe($spec->toJson());
});

it('emits nested required arrays into openapi', function (): void {
    $document = (new OpenApiEmitter)->document(orderSpec());
    $body = $document['paths']['/api/orders']['post']['requestBody']['content']['application/json']['schema'];

    expect($body['properties']['items']['items']['required'])->toBe(['product_id', 'quantity'])
        ->and($body['required'])->toContain('email');
});

it('reads the same request only once across actions that share it', function (): void {
    FormRequestReader::flushCache();

    orderSpec();

    // Both store and update type-hint StoreOrderRequest; the cache means the
    // file is parsed once regardless.
    expect(bodyParam('orders.update', 'email'))->not->toBeNull();
});

it('describes a parameter from the docblock above its rule', function (): void {
    expect(bodyParam('orders.store', 'depot')?->description)->toBe('Where to deliver the order.');
});

it('takes the author @example over a generated one, typed as written', function (): void {
    expect(bodyParam('orders.store', 'depot')?->schema->example)->toBe('DEPOT-7')
        // Written as a number, so the example request must not quote it.
        ->and(bodyParam('orders.store', 'crates')?->schema->example)->toBe(3);
});

it('keeps a rule-derived note alongside the authored sentence', function (): void {
    // "how many crates" and whatever the rules add answer different
    // questions, so neither replaces the other.
    expect(bodyParam('orders.store', 'crates')?->description)
        ->toStartWith('How many crates, at most twelve.');
});

it('leaves an undocumented rule undescribed rather than inventing a sentence', function (): void {
    expect(bodyParam('orders.store', 'email')?->description)->toBeNull();
});
