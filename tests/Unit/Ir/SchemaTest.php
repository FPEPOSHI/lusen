<?php

declare(strict_types=1);

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;

it('reads a shape off a value', function (): void {
    $schema = Schema::fromValue(['id' => 7, 'total' => 12.5, 'paid' => true, 'note' => 'x']);

    expect($schema->type)->toBe(SchemaType::Object)
        ->and($schema->properties['id']->type)->toBe(SchemaType::Integer)
        ->and($schema->properties['total']->type)->toBe(SchemaType::Number)
        ->and($schema->properties['paid']->type)->toBe(SchemaType::Boolean)
        ->and($schema->properties['note']->type)->toBe(SchemaType::String)
        ->and($schema->required)->toBe([]);
});

it('types a list by its first item and an empty list as a list of anything', function (): void {
    expect(Schema::fromValue([['id' => 1]])->items?->properties['id']->type)->toBe(SchemaType::Integer)
        ->and(Schema::fromValue([])->items?->type)->toBe(SchemaType::Any);
});

it('leaves a null as anything, since one null says nothing about the field', function (): void {
    expect(Schema::fromValue(['note' => null])->properties['note']->type)->toBe(SchemaType::Any);
});
