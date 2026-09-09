<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Enums\SchemaType;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\ShapedController;

function shapedSpec(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::get('api/orders', [ShapedController::class, 'index'])->name('orders.index');
    Route::post('api/orders', [ShapedController::class, 'store'])->name('orders.store');
    Route::get('api/orders/{order}', [ShapedController::class, 'show'])->name('orders.show');
    Route::delete('api/orders/{order}', [ShapedController::class, 'destroy'])->name('orders.destroy');
    Route::post('api/orders/{order}/refunds', [ShapedController::class, 'refund'])->name('orders.refund');
});

it('reads a @response written as a json body, the way scribe spells it', function (): void {
    // Misread as a type expression, the body became an `any` schema whose
    // generated example was the word "example" - on every response a
    // codebase migrating from Scribe had written.
    $responses = shapedSpec()->endpoint('orders.refund')?->responses ?? [];

    expect(array_map(fn ($r): int => $r->status, $responses))->toBe([200, 404])
        ->and($responses[0]->examples[0]->value)->toBe(['status' => true, 'data' => ['id' => 7, 'total' => 12.5, 'tags' => ['refund'], 'note' => null]])
        ->and($responses[0]->schema?->properties['status']->type)->toBe(SchemaType::Boolean)
        ->and($responses[0]->schema?->properties['data']->properties['id']->type)->toBe(SchemaType::Integer)
        ->and($responses[0]->schema?->properties['data']->properties['total']->type)->toBe(SchemaType::Number)
        ->and($responses[0]->schema?->properties['data']->properties['tags']->items?->type)->toBe(SchemaType::String)
        ->and($responses[0]->schema?->properties['data']->properties['note']->type)->toBe(SchemaType::Any)
        ->and($responses[1]->schema?->properties['message']->type)->toBe(SchemaType::String);
});

it('documents a response from the @response docblock', function (): void {
    $responses = shapedSpec()->endpoint('orders.index')?->responses;

    expect($responses)->toHaveCount(1)
        ->and($responses[0]->status)->toBe(200)
        ->and($responses[0]->schema?->properties['status']->type)->toBe(SchemaType::Boolean);
});

it('reads the whole nested shape, including a referenced class', function (): void {
    $data = shapedSpec()->endpoint('orders.index')?->responses[0]->schema?->properties['data'];

    $order = $data?->properties['orders']->items;

    expect($data?->properties['total']->type)->toBe(SchemaType::Integer)
        ->and($order?->properties['id']->type)->toBe(SchemaType::Integer)
        ->and($order->properties['amount']->properties['currency']->type)->toBe(SchemaType::String);
});

it('gives a POST the conventional 201 without being told', function (): void {
    $statuses = array_map(
        fn ($response): int => $response->status,
        shapedSpec()->endpoint('orders.store')?->responses ?? [],
    );

    expect($statuses)->toBe([201, 422]);
});

it('never documents a body against 204, whatever the verb implies', function (): void {
    // A DELETE would conventionally be 204, but a written shape says there is
    // a body, and a 204 with a body is a contradiction.
    $responses = shapedSpec()->endpoint('orders.destroy')?->responses;

    expect($responses[0]->status)->toBe(200);
});

it('generates an example that matches the documented shape', function (): void {
    $example = shapedSpec()->endpoint('orders.index')?->responses[0]->examples[0] ?? null;

    expect($example?->value)->toHaveKey('status')
        ->and($example->value['data'])->toHaveKey('orders');
});

it('lets an attribute overrule the docblock, because an attribute is the last word', function (): void {
    $responses = shapedSpec()->endpoint('orders.show')?->responses;

    expect($responses)->toHaveCount(1)
        ->and($responses[0]->description)->toBe('The order.')
        ->and($responses[0]->schema)->toBeNull();
});
