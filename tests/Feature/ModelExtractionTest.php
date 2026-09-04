<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Extract\Models\MigrationReader;
use Lusen\Extract\Models\ModelSchema;
use Lusen\Extract\Resources\ResourceReader;
use Lusen\Ir\Schema;
use Lusen\SpecBuilder;
use Lusen\Support\Ast;
use Lusen\Tests\Fixtures\WidgetController;

function widgetFields(): array
{
    /** @var Schema|null $schema */
    $schema = null;

    foreach (app(SpecBuilder::class)->build()->endpoint('widgets.show')?->responses ?? [] as $response) {
        if ($response->isSuccess()) {
            $schema = $response->schema;
        }
    }

    return $schema?->properties['data']->properties ?? [];
}

beforeEach(function (): void {
    Ast::flushCache();
    ResourceReader::flushCache();
    MigrationReader::flushCache();
    ModelSchema::flushCache();

    config()->set('lusen.models.namespaces', ['Lusen\Tests\Fixtures\Models']);
    config()->set('lusen.models.migrations', [__DIR__.'/../Fixtures/migrations']);

    Route::get('api/widgets/{widget}', [WidgetController::class, 'show'])->name('widgets.show');
});

it('types every field from the model behind the resource', function (): void {
    // The resource itself declares nothing; all of this comes from the model.
    $fields = widgetFields();

    expect($fields['stock']->type->value)->toBe('integer')
        ->and($fields['price']->type->value)->toBe('number')
        ->and($fields['featured']->type->value)->toBe('boolean')
        ->and($fields['settings']->type->value)->toBe('array')
        ->and($fields['released_at']->format)->toBe('date-time');
});

it('carries a column length onto the response field', function (): void {
    expect(widgetFields()['name']->constraints)->toBe(['maxLength' => 120]);
});

it('carries nullability, which only migrations know', function (): void {
    expect(widgetFields()['sku']->nullable)->toBeTrue()
        ->and(widgetFields()['stock']->nullable)->toBeFalse();
});

it('carries an enum column through as an enum', function (): void {
    expect(widgetFields()['condition']->enum)->toBe(['new', 'refurbished']);
});

it('carries an enum cast through as an enum', function (): void {
    expect(widgetFields()['status']->enum)->toBe(['pending', 'paid', 'refunded']);
});

it('resolves fields the naming conventions could never have typed', function (): void {
    // stock, price and weight are exactly the names FieldTypes refuses to
    // guess; the model is what makes them knowable.
    $fields = widgetFields();

    expect($fields['weight']->type->value)->toBe('number')
        ->and($fields['price']->type->value)->toBe('number');
});

it('still admits a field the model says nothing about', function (): void {
    expect(widgetFields()['undocumented']->type->value)->toBe('any');
});

it('falls back to naming conventions when models are turned off', function (): void {
    config()->set('lusen.models.enabled', false);
    ResourceReader::flushCache();

    $fields = widgetFields();

    expect($fields['id']->type->value)->toBe('integer')      // still a name convention
        ->and($fields['price']->type->value)->toBe('any')     // no longer knowable
        ->and($fields['stock']->type->value)->toBe('any');
});

it('generates examples that match the derived types', function (): void {
    $example = app(SpecBuilder::class)->build()->endpoint('widgets.show')?->responses[0]->examples[0];
    $data = $example?->value['data'] ?? [];

    expect($data['featured'])->toBeBool()
        ->and($data['stock'])->toBeInt()
        ->and($data['condition'])->toBe('new')
        ->and($data['status'])->toBe('pending');
});
