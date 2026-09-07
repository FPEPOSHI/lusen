<?php

declare(strict_types=1);

use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
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

it('turns a parameter list into the object it describes', function (): void {
    $rows = SchemaFields::forParameters([
        new Parameter('customer_id', ParameterLocation::Body, Schema::integer(), required: true, description: 'Who for.'),
        new Parameter('items', ParameterLocation::Body, Schema::arrayOf(Schema::object(
            ['product_id' => Schema::integer(), 'quantity' => Schema::integer()],
            ['product_id'],
        )), required: true),
    ]);

    // `items` being "array" is not documentation: somebody building the
    // request needs to know what goes in it.
    expect(array_column($rows, 'name'))->toBe(['customer_id', 'items', 'items[].product_id', 'items[].quantity'])
        ->and(array_column($rows, 'required'))->toBe([true, true, true, false]);

    // Split at the last separator, so a table can mute the half that only
    // says where the field lives and leave the half that names it.
    expect(array_column($rows, 'parent'))->toBe(['', '', 'items[].', 'items[].'])
        ->and(array_column($rows, 'leaf'))->toBe(['customer_id', 'items', 'product_id', 'quantity']);
});

it('carries a parameter description without erasing the schema one', function (): void {
    $described = Schema::integer()->describedAs('From the schema.');

    $rows = SchemaFields::forParameters([
        new Parameter('a', ParameterLocation::Body, $described, description: 'From the parameter.'),
        new Parameter('b', ParameterLocation::Body, $described),
    ]);

    expect(array_column($rows, 'description'))->toBe(['From the parameter.', 'From the schema.']);
});

it('has nothing to say about an empty parameter list', function (): void {
    expect(SchemaFields::forParameters([]))->toBe([]);
});

it('splits a path that has no parent, and one whose parent is a list', function (): void {
    $rows = SchemaFields::flatten(Schema::arrayOf(Schema::object([
        'id' => Schema::integer(),
        'meta' => Schema::object(['sku' => Schema::string()]),
    ])));

    expect(array_column($rows, 'name'))->toBe(['[].id', '[].meta', '[].meta.sku'])
        ->and(array_column($rows, 'parent'))->toBe(['[].', '[].', '[].meta.'])
        ->and(array_column($rows, 'leaf'))->toBe(['id', 'meta', 'sku']);
});
