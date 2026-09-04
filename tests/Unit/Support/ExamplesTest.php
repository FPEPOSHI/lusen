<?php

declare(strict_types=1);

use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;
use Lusen\Support\Examples;

function param(string $name, Schema $schema, bool $required = false): Parameter
{
    return new Parameter($name, ParameterLocation::Query, $schema, $required);
}

it('prefers an author-supplied example over everything else', function (): void {
    expect(Examples::forSchema(Schema::integer()->withExample(99), 'per_page'))->toBe(99);
});

it('falls back to the first enum case', function (): void {
    expect(Examples::forSchema(Schema::enum(['invited', 'active']), 'status'))->toBe('invited');
});

it('guesses from the parameter name', function (string $name, mixed $expected): void {
    expect(Examples::forSchema(Schema::string(), $name))->toBe($expected);
})->with([
    ['email', 'jane@example.com'],
    ['first_name', 'Jane'],
    ['currency', 'USD'],
    ['url', 'https://example.com'],
]);

it('only uses a name hint when it agrees with the declared type', function (): void {
    // 'total' hints at 4200, an int, which must not leak into a string field.
    expect(Examples::forSchema(Schema::string(), 'total'))->toBeString()
        ->and(Examples::forSchema(Schema::integer(), 'total'))->toBe(4200);
});

it('uses the format when there is no name hint', function (): void {
    expect(Examples::forSchema(Schema::string('date-time'), 'occurred'))->toBe('2026-01-15T09:30:00Z')
        ->and(Examples::forSchema(Schema::string('uuid'), 'reference'))->toContain('-');
});

it('never exceeds a declared max length', function (): void {
    expect(Examples::forSchema(new Schema(constraints: ['maxLength' => 4]), 'nickname'))->toHaveLength(4);
});

it('builds a nested object from its properties', function (): void {
    $schema = Schema::object([
        'id' => Schema::integer(),
        'email' => Schema::string('email'),
    ]);

    expect(Examples::forSchema($schema))->toBe(['id' => 1, 'email' => 'jane@example.com']);
});

it('wraps an array example in a single-element list', function (): void {
    expect(Examples::forSchema(Schema::arrayOf(Schema::integer()), 'ids'))->toBe([1]);
});

it('returns a boolean for a boolean schema', function (): void {
    expect(Examples::forSchema(new Schema(type: SchemaType::Boolean), 'active'))->toBeTrue();
});

it('builds a json body keyed by parameter name', function (): void {
    $body = Examples::body([
        param('email', Schema::string('email'), true),
        param('role', Schema::enum(['admin', 'member'])),
    ]);

    expect($body)->toBe(['email' => 'jane@example.com', 'role' => 'admin']);
});

it('coerces an example until it satisfies the field pattern', function (): void {
    $schema = new Schema(constraints: ['pattern' => '/^[A-Z0-9]+$/']);

    expect(Examples::forSchema($schema, 'coupon'))->toBe('COUPON');
});

it('falls back to a generic code when the name cannot be coerced', function (): void {
    $schema = new Schema(constraints: ['pattern' => '/^[0-9]{5}$/']);

    expect(Examples::forSchema($schema, 'zip'))->toBe('12345');
});

it('treats an unparseable pattern as satisfied rather than mangling the value', function (): void {
    $schema = new Schema(constraints: ['pattern' => 'not-a-regex']);

    expect(Examples::forSchema($schema, 'email'))->toBe('jane@example.com');
});

it('pads a value up to minLength', function (): void {
    expect(Examples::forSchema(new Schema(constraints: ['minLength' => 6]), 'pin'))->toHaveLength(6);
});

it('still satisfies size rules that pin an exact length', function (): void {
    $schema = new Schema(constraints: ['minLength' => 8, 'maxLength' => 8]);

    expect(Examples::forSchema($schema, 'reference'))->toHaveLength(8);
});
