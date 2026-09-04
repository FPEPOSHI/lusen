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
