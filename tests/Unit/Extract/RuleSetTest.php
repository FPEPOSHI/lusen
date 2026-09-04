<?php

declare(strict_types=1);

use Lusen\Extract\Rules\RuleSet;

it('accepts both the pipe string and the array spelling', function (): void {
    expect(RuleSet::parse('required|email|max:255')->all())
        ->toBe(RuleSet::parse(['required', 'email', 'max:255'])->all());
});

it('maps validation types to schema types', function (string $rule, string $expected): void {
    expect(RuleSet::parse($rule)->toSchema()->type->value)->toBe($expected);
})->with([
    ['integer', 'integer'],
    ['numeric', 'number'],
    ['boolean', 'boolean'],
    ['array', 'array'],
    ['string', 'string'],
    ['json', 'object'],
    ['decimal:2', 'number'],
]);

it('defaults to string when no type rule is present', function (): void {
    expect(RuleSet::parse('required')->toSchema()->type->value)->toBe('string');
});

it('maps format rules', function (string $rule, string $expected): void {
    expect(RuleSet::parse($rule)->toSchema()->format)->toBe($expected);
})->with([
    ['email', 'email'],
    ['url', 'uri'],
    ['uuid', 'uuid'],
    ['date', 'date-time'],
    ['date_format:Y-m-d', 'date'],
]);

it('treats required as required', function (): void {
    expect(RuleSet::parse('required|string')->isRequired())->toBeTrue()
        ->and(RuleSet::parse('nullable|string')->isRequired())->toBeFalse();
});

it('treats sometimes as optional even alongside required', function (): void {
    // `sometimes` means the field may be absent, which is what optional means
    // in a documented request body.
    expect(RuleSet::parse('sometimes|required|string')->isRequired())->toBeFalse();
});

it('treats conditional requirement as required', function (): void {
    expect(RuleSet::parse('required_with:other')->isRequired())->toBeTrue()
        ->and(RuleSet::parse('required_if:type,card')->isRequired())->toBeTrue();
});

it('carries nullable onto the schema', function (): void {
    expect(RuleSet::parse('nullable|string')->toSchema()->nullable)->toBeTrue();
});

it('reads an in rule as an enum', function (): void {
    expect(RuleSet::parse('required|in:USD,EUR,GBP')->toSchema()->enum)->toBe(['USD', 'EUR', 'GBP']);
});

it('interprets min and max by type', function (): void {
    expect(RuleSet::parse('string|min:2|max:10')->toSchema()->constraints)
        ->toBe(['minLength' => 2, 'maxLength' => 10])
        ->and(RuleSet::parse('integer|min:1|max:100')->toSchema()->constraints)
        ->toBe(['min' => 1, 'max' => 100])
        ->and(RuleSet::parse('array|min:1|max:20')->toSchema()->constraints)
        ->toBe(['minItems' => 1, 'maxItems' => 20]);
});

it('expands between and size into a pair of bounds', function (): void {
    expect(RuleSet::parse('integer|between:1,99')->toSchema()->constraints)
        ->toBe(['min' => 1, 'max' => 99])
        ->and(RuleSet::parse('string|size:8')->toSchema()->constraints)
        ->toBe(['minLength' => 8, 'maxLength' => 8]);
});

it('keeps a decimal bound as a float', function (): void {
    expect(RuleSet::parse('numeric|min:0.01')->toSchema()->constraints['min'])->toBe(0.01);
});

it('carries a regex through as a pattern', function (): void {
    expect(RuleSet::parse('string|regex:/^[A-Z]+$/')->toSchema()->constraints['pattern'])
        ->toBe('/^[A-Z]+$/');
});

it('turns digits into an equivalent pattern', function (): void {
    expect(RuleSet::parse('digits:4')->toSchema()->constraints['pattern'])->toBe('^\d{4}$');
});

it('notes constraints the schema cannot express', function (): void {
    expect(RuleSet::parse('required|unique:users,email')->note())->toContain('unique')
        ->and(RuleSet::parse('required|confirmed')->note())->toContain('confirmation')
        ->and(RuleSet::parse('required_with:card_number')->note())->toContain('card_number');
});

it('has no note when every rule is expressible', function (): void {
    expect(RuleSet::parse('required|string|max:10')->note())->toBeNull();
});

it('recognises a prohibited field', function (): void {
    expect(RuleSet::parse('prohibited')->isProhibited())->toBeTrue();
});

it('ignores database rules when deciding the schema', function (): void {
    $schema = RuleSet::parse('required|string|exists:users,id|unique:users')->toSchema();

    expect($schema->type->value)->toBe('string')
        ->and($schema->constraints)->toBe([]);
});
