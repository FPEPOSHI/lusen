<?php

declare(strict_types=1);

use Lusen\Ir\Schema;
use Lusen\Support\SchemaFields;

function nestedSchema(): Schema
{
    return Schema::object([
        'data' => Schema::object([
            'id' => Schema::integer(),
            'items' => Schema::arrayOf(Schema::object([
                'sku' => Schema::string(),
            ])),
        ], required: ['id']),
    ], required: ['data']);
}

it('flattens nested objects into dotted paths', function (): void {
    expect(array_column(SchemaFields::flatten(nestedSchema()), 'name'))
        ->toBe(['data', 'data.id', 'data.items', 'data.items[].sku']);
});

it('marks array elements with a bracket suffix', function (): void {
    $rows = SchemaFields::flatten(nestedSchema());

    expect($rows[3]['name'])->toBe('data.items[].sku')
        ->and($rows[3]['type'])->toBe('string');
});

it('reports which fields are required', function (): void {
    $rows = array_column(SchemaFields::flatten(nestedSchema()), 'required', 'name');

    expect($rows['data'])->toBeTrue()
        ->and($rows['data.id'])->toBeTrue()
        ->and($rows['data.items'])->toBeFalse();
});

it('unwraps a top-level array to its element shape', function (): void {
    $schema = Schema::arrayOf(Schema::object(['id' => Schema::integer()]));

    expect(array_column(SchemaFields::flatten($schema), 'name'))->toBe(['[].id']);
});

it('labels an untyped field as any', function (): void {
    $schema = Schema::object(['mystery' => Schema::any()]);

    expect(SchemaFields::flatten($schema)[0]['type'])->toBe('any');
});

it('reports whether a schema has any fields at all', function (): void {
    expect(SchemaFields::hasFields(nestedSchema()))->toBeTrue()
        ->and(SchemaFields::hasFields(Schema::string()))->toBeFalse()
        ->and(SchemaFields::hasFields(null))->toBeFalse();
});

it('stops recursing before a cyclic schema can run away', function (): void {
    $leaf = Schema::object(['id' => Schema::integer()]);

    for ($i = 0; $i < 10; $i++) {
        $leaf = Schema::object(['child' => $leaf]);
    }

    expect(SchemaFields::flatten($leaf))->not->toBe([])
        ->and(count(SchemaFields::flatten($leaf)))->toBeLessThan(12);
});
