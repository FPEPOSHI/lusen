<?php

declare(strict_types=1);

use Lusen\Extract\Models\MigrationReader;
use Lusen\Extract\Models\ModelLocator;
use Lusen\Extract\Models\ModelSchema;
use Lusen\Support\Ast;
use Lusen\Tests\Fixtures\Models\Gadget;
use Lusen\Tests\Fixtures\Models\Widget;
use Lusen\Tests\Fixtures\Resources\UserResource;

function modelSchema(): ModelSchema
{
    MigrationReader::flushCache();
    ModelSchema::flushCache();
    Ast::flushCache();

    return new ModelSchema(new MigrationReader([__DIR__.'/../../../Fixtures/migrations']));
}

it('derives a table name by convention', function (): void {
    expect(modelSchema()->table(Widget::class))->toBe('widgets');
});

it('prefers an explicit table property', function (): void {
    expect(modelSchema()->table(Gadget::class))->toBe('custom_gadgets');
});

it('reads casts declared as a method', function (): void {
    $fields = modelSchema()->fields(Widget::class);

    expect($fields['released_at']->format)->toBe('date-time')
        ->and($fields['settings']->type->value)->toBe('array')
        ->and($fields['weight']->type->value)->toBe('number')
        ->and($fields['featured']->type->value)->toBe('boolean');
});

it('reads casts declared as a property', function (): void {
    $fields = modelSchema()->fields(Gadget::class);

    expect($fields['shipped_at']->format)->toBe('date-time')
        ->and($fields['quantity']->type->value)->toBe('integer');
});

it('reads an enum cast as an enum schema', function (): void {
    expect(modelSchema()->fields(Widget::class)['status']->enum)
        ->toBe(['pending', 'paid', 'refunded']);
});

it('falls back to columns for fields with no cast', function (): void {
    $fields = modelSchema()->fields(Widget::class);

    expect($fields['name']->constraints)->toBe(['maxLength' => 120])
        ->and($fields['stock']->type->value)->toBe('integer');
});

it('lets a cast win over the column it transforms', function (): void {
    // settings is a json column cast to array: array is what the response has.
    expect(modelSchema()->fields(Widget::class)['settings']->type->value)->toBe('array');
});

it('returns null for an attribute the model says nothing about', function (): void {
    expect(modelSchema()->field(Widget::class, 'nonexistent'))->toBeNull();
});

it('finds a model by naming convention', function (): void {
    ModelLocator::flushCache();

    $locator = new ModelLocator(['Lusen\Tests\Fixtures\Models']);

    expect($locator->forResource(UserResource::class))->toBeNull();
});

it('refuses to treat a non-model as a model', function (): void {
    ModelLocator::flushCache();

    // Lusen\Tests\Fixtures\Requests\OrderStatus exists but is an enum, not a model.
    $locator = new ModelLocator(['Lusen\Tests\Fixtures\Requests']);

    expect($locator->forResource('Lusen\Tests\Fixtures\Resources\OrderStatusResource'))->toBeNull();
});
