<?php

declare(strict_types=1);

use Lusen\Extract\Models\MigrationReader;
use Lusen\Support\Ast;

function migrations(): MigrationReader
{
    MigrationReader::flushCache();
    Ast::flushCache();

    return new MigrationReader([__DIR__.'/../../../Fixtures/migrations']);
}

it('reads column types', function (string $column, string $type, ?string $format): void {
    $schema = migrations()->columns('widgets')[$column] ?? null;

    expect($schema?->type->value)->toBe($type)
        ->and($schema?->format)->toBe($format);
})->with([
    ['id', 'integer', null],
    ['name', 'string', null],
    ['description', 'string', null],
    ['stock', 'integer', null],
    ['price', 'number', null],
    ['featured', 'boolean', null],
    ['settings', 'array', null],
    ['reference', 'string', 'uuid'],
    ['owner_id', 'integer', null],
    ['released_at', 'string', 'date-time'],
]);

it('reads a length limit off a string column', function (): void {
    expect(migrations()->columns('widgets')['name']->constraints)->toBe(['maxLength' => 120]);
});

it('reads nullability, which casts cannot report', function (): void {
    $columns = migrations()->columns('widgets');

    expect($columns['sku']->nullable)->toBeTrue()
        ->and($columns['description']->nullable)->toBeTrue()
        ->and($columns['stock']->nullable)->toBeFalse()
        ->and($columns['name']->nullable)->toBeFalse();
});

it('reads an enum column as an enum schema', function (): void {
    expect(migrations()->columns('widgets')['condition']->enum)->toBe(['new', 'refurbished']);
});

it('expands timestamps and soft deletes', function (): void {
    $columns = migrations()->columns('widgets');

    expect($columns)->toHaveKeys(['created_at', 'updated_at', 'deleted_at'])
        ->and($columns['created_at']->format)->toBe('date-time')
        ->and($columns['deleted_at']->nullable)->toBeTrue();
});

it('applies a later migration that alters the table', function (): void {
    // Migrations are timestamp-prefixed, so a later change should win.
    $weight = migrations()->columns('widgets')['weight'] ?? null;

    expect($weight?->type->value)->toBe('number')
        ->and($weight?->nullable)->toBeTrue();
});

it('reads a second table independently', function (): void {
    expect(migrations()->columns('custom_gadgets'))->toHaveKeys(['id', 'label', 'quantity'])
        ->and(migrations()->columns('custom_gadgets'))->not->toHaveKey('name');
});

it('returns nothing for a table it has never seen', function (): void {
    expect(migrations()->columns('nope'))->toBe([]);
});

it('tolerates a directory that does not exist', function (): void {
    MigrationReader::flushCache();

    expect((new MigrationReader(['/no/such/place']))->columns('widgets'))->toBe([]);
});
