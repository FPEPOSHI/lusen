<?php

declare(strict_types=1);

use Lusen\Support\FieldTypes;

it('types strong laravel conventions', function (string $name, string $type, ?string $format): void {
    $schema = FieldTypes::forName($name);

    expect($schema->type->value)->toBe($type)
        ->and($schema->format)->toBe($format);
})->with([
    ['id', 'integer', null],
    ['customer_id', 'integer', null],
    ['created_at', 'string', 'date-time'],
    ['published_at', 'string', 'date-time'],
    ['birth_date', 'string', 'date'],
    ['orders_count', 'integer', null],
    ['is_active', 'boolean', null],
    ['has_shipped', 'boolean', null],
    ['email', 'string', 'email'],
    ['avatar_url', 'string', 'uri'],
    ['uuid', 'string', 'uuid'],
]);

it('leaves an ambiguous name untyped rather than guessing', function (string $name): void {
    // price and amount are minor-unit integers in some codebases and floats in
    // others; status is a string in most and an integer in plenty.
    expect(FieldTypes::forName($name)->type->value)->toBe('any');
})->with(['price', 'amount', 'total', 'status', 'metadata', 'whatever']);

it('prefers a uuid reading over the integer id rule', function (): void {
    expect(FieldTypes::forName('order_uuid')->format)->toBe('uuid');
});

it('treats a php cast as authoritative', function (): void {
    expect(FieldTypes::forCast('int')?->type->value)->toBe('integer')
        ->and(FieldTypes::forCast('bool')?->type->value)->toBe('boolean')
        ->and(FieldTypes::forCast('float')?->type->value)->toBe('number')
        ->and(FieldTypes::forCast('nonsense'))->toBeNull();
});
