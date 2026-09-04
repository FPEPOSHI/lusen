<?php

declare(strict_types=1);

use Lusen\Extract\Types\TypeNames;
use Lusen\Extract\Types\TypeReader;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use Lusen\Tests\Fixtures\Schema\CyclicShape;
use Lusen\Tests\Fixtures\Schema\OrderShape;

beforeEach(function (): void {
    Ast::flushCache();
});

function readType(string $expression): ?Schema
{
    return (new TypeReader)->read($expression, TypeNames::empty());
}

it('reads the scalar keywords', function (string $written, SchemaType $type): void {
    expect(readType($written)?->type)->toBe($type);
})->with([
    ['int', SchemaType::Integer],
    ['integer', SchemaType::Integer],
    ['float', SchemaType::Number],
    ['string', SchemaType::String],
    ['bool', SchemaType::Boolean],
    ['array', SchemaType::Array],
    ['object', SchemaType::Object],
    ['mixed', SchemaType::Any],
]);

it('reads a literal as its type, and keeps the literal as the example', function (): void {
    expect(readType('true')?->type)->toBe(SchemaType::Boolean)
        ->and(readType('true')?->example)->toBeTrue()
        ->and(readType('42')?->example)->toBe(42)
        ->and(readType("'paid'")?->example)->toBe('paid');
});

it('reads a union of string literals as an enum', function (): void {
    $schema = readType("'pending'|'paid'|'refunded'");

    expect($schema?->enum)->toBe(['pending', 'paid', 'refunded']);
});

it('treats null in a union as nullability rather than a member', function (): void {
    expect(readType('string|null')?->type)->toBe(SchemaType::String)
        ->and(readType('string|null')?->nullable)->toBeTrue()
        ->and(readType('?int')?->type)->toBe(SchemaType::Integer)
        ->and(readType('?int')?->nullable)->toBeTrue();
});

it('leads with the first member of a union of real types', function (): void {
    // `array{...}|ApiError` is how the success shape and the error shape get
    // written together; a reader of the 200 wants the first one.
    $schema = readType('array{id: int}|SomeUnknownError');

    expect($schema?->type)->toBe(SchemaType::Object)
        ->and(array_keys($schema?->properties ?? []))->toBe(['id']);
});

it('reads an array shape into properties, with optional keys left out of required', function (): void {
    $schema = readType('array{id: int, name: string, note?: string}');

    expect($schema?->type)->toBe(SchemaType::Object)
        ->and(array_keys($schema->properties))->toBe(['id', 'name', 'note'])
        ->and($schema->properties['id']->type)->toBe(SchemaType::Integer)
        ->and($schema->required)->toBe(['id', 'name']);
});

it('reads nested shapes to full depth', function (): void {
    $schema = readType('array{data: array{lines: list<array{qty: int}>}}');

    $lines = $schema?->properties['data']->properties['lines'] ?? null;

    expect($lines?->type)->toBe(SchemaType::Array)
        ->and($lines->items?->properties['qty']->type)->toBe(SchemaType::Integer);
});

it('reads the list and array generics', function (): void {
    expect(readType('list<string>')?->items?->type)->toBe(SchemaType::String)
        ->and(readType('array<int>')?->items?->type)->toBe(SchemaType::Integer)
        ->and(readType('string[]')?->items?->type)->toBe(SchemaType::String);
});

it('degrades a keyed map to a plain object rather than inventing properties', function (): void {
    $schema = readType('array<string, int>');

    expect($schema?->type)->toBe(SchemaType::Object)
        ->and($schema->properties)->toBe([]);
});

it('is not fooled by a comma inside a nested shape', function (): void {
    $schema = readType('array{a: array{x: int, y: int}, b: string}');

    expect(array_keys($schema?->properties ?? []))->toBe(['a', 'b']);
});

it('leaves an unreadable type any rather than guessing', function (): void {
    expect(readType('SomethingNobodyCanResolve')?->type)->toBe(SchemaType::Any);
});

it('reads a class through its typed public properties', function (): void {
    $schema = (new TypeReader)->readClass(OrderShape::class);

    expect($schema?->type)->toBe(SchemaType::Object)
        ->and($schema->properties['id']->type)->toBe(SchemaType::Integer)
        // DocBlock drops the trailing full stop from a summary.
        ->and($schema->properties['id']->description)->toBe("The order's own identifier")
        ->and($schema->properties['paid_total']->nullable)->toBeTrue()
        // A nested DTO is read the same way.
        ->and($schema->properties['amount']->properties['currency']->description)->toBe('ISO 4217 currency code');
});

it('lets a @var docblock override a bare array type', function (): void {
    $schema = (new TypeReader)->readClass(OrderShape::class);

    expect($schema?->properties['lines']->type)->toBe(SchemaType::Array)
        ->and($schema->properties['lines']->items?->properties['gross']->type)->toBe(SchemaType::Number)
        ->and($schema->properties['tags']->items?->type)->toBe(SchemaType::String);
});

it('leaves an untyped property any, and skips static and non-public ones', function (): void {
    $schema = (new TypeReader)->readClass(OrderShape::class);

    expect($schema?->properties['anything']->type)->toBe(SchemaType::Any)
        ->and($schema->properties)->not->toHaveKey('ignored')
        ->and($schema->properties)->not->toHaveKey('hidden');
});

it('claims nothing is required in a class, because a class cannot say', function (): void {
    expect((new TypeReader)->readClass(OrderShape::class)?->required)->toBe([]);
});

it('survives a class that refers to itself', function (): void {
    $schema = (new TypeReader)->readClass(CyclicShape::class);

    expect($schema?->properties['name']->type)->toBe(SchemaType::String)
        ->and($schema->properties['parent']->type)->toBe(SchemaType::Object);
});

it('returns null for a class it cannot find', function (): void {
    expect((new TypeReader)->readClass('No\\Such\\Klass'))->toBeNull();
});

it('resolves a class named in a docblock through the file that wrote it', function (): void {
    // OrderShape's own file imports nothing, but MoneyShape sits beside it, so
    // the namespace fallback has to find it.
    $schema = (new TypeReader)->read('MoneyShape', TypeNames::forClass(OrderShape::class));

    expect($schema?->properties['currency']->type)->toBe(SchemaType::String);
});
