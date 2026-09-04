<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;

/**
 * Synthesises realistic example values.
 *
 * A parameter table that says `string` teaches a reader nothing and gives an
 * agent nothing to send. The values here are chosen so a generated request is
 * plausible enough to paste and run: an author-supplied example always wins,
 * then an enum's first case, then a guess from the parameter's own name, and
 * only then a bare type default.
 */
final class Examples
{
    /**
     * Name fragment => example value. Ordered: the first match wins, so more
     * specific fragments must come first.
     *
     * @var array<string, string|int>
     */
    private const HINTS = [
        'email' => 'jane@example.com',
        'password' => 'correct-horse-battery',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'username' => 'janedoe',
        'name' => 'Jane Doe',
        'title' => 'Quarterly report',
        'slug' => 'quarterly-report',
        'phone' => '+15551234567',
        'url' => 'https://example.com',
        'uuid' => '9f8c7b6a-5d4e-3f2a-1b0c-9d8e7f6a5b4c',
        'token' => 'a1b2c3d4e5f6',
        'currency' => 'USD',
        'country' => 'US',
        'locale' => 'en_US',
        'timezone' => 'UTC',
        'description' => 'A short description.',
        'plan' => 'pro',
        'sku' => 'FN-001',
        'city' => 'Berlin',
        'address' => '1 Example Street',
        'message' => 'Hello there',
        'reference' => 'REF-4821',
        'per_page' => 25,
        'page' => 1,
        'limit' => 25,
        'offset' => 0,
        'quantity' => 2,
        'total' => 4200,
        'amount' => 4200,
        'price' => 1999,
        'count' => 3,
        '_id' => 1,
        'id' => 1,
    ];

    public static function forParameter(Parameter $parameter): mixed
    {
        return self::forSchema($parameter->schema, $parameter->name);
    }

    public static function forSchema(Schema $schema, string $name = ''): mixed
    {
        if ($schema->example !== null) {
            return $schema->example;
        }

        if ($schema->enum !== []) {
            return $schema->enum[0];
        }

        return match ($schema->type) {
            SchemaType::Object => self::object($schema),
            SchemaType::Array => [self::forSchema($schema->items ?? Schema::string(), $name)],
            SchemaType::Boolean => true,
            SchemaType::Null => null,
            default => self::scalar($schema, $name),
        };
    }

    /**
     * A JSON body built from a set of body parameters.
     *
     * @param  list<Parameter>  $parameters
     * @return array<string, mixed>
     */
    public static function body(array $parameters): array
    {
        $body = [];

        foreach ($parameters as $parameter) {
            $body[$parameter->name] = self::forParameter($parameter);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(Schema $schema): array
    {
        $object = [];

        foreach ($schema->properties as $property => $propertySchema) {
            $object[$property] = self::forSchema($propertySchema, $property);
        }

        return $object;
    }

    private static function scalar(Schema $schema, string $name): string|int|float
    {
        $value = self::rawScalar($schema, $name);

        if (! is_string($value)) {
            return $value;
        }

        // An example that fails the field's own validation is worse than no
        // example - a reader pastes it and gets a 422. Length and pattern are
        // both enforced here rather than in the fallback branch, because a
        // name hint can violate either.
        return self::satisfy(self::clamp($value, $schema), $schema);
    }

    private static function rawScalar(Schema $schema, string $name): string|int|float
    {
        $hinted = self::fromName($name);

        if ($hinted !== null) {
            // A name hint only helps if it agrees with the declared type.
            $matchesType = match ($schema->type) {
                SchemaType::Integer, SchemaType::Number => is_int($hinted),
                // An untyped field takes whatever the name suggests; that hint
                // is the only evidence available about it.
                SchemaType::Any => true,
                default => is_string($hinted),
            };

            if ($matchesType) {
                return $hinted;
            }
        }

        return match ($schema->format) {
            'email' => 'jane@example.com',
            'uri', 'url' => 'https://example.com',
            'uuid' => '9f8c7b6a-5d4e-3f2a-1b0c-9d8e7f6a5b4c',
            'date' => '2026-01-15',
            'date-time' => '2026-01-15T09:30:00Z',
            default => match ($schema->type) {
                SchemaType::Integer => 1,
                SchemaType::Number => 19.99,
                default => $name === '' ? 'example' : str_replace(['_', '-'], ' ', $name),
            },
        };
    }

    /**
     * Respects an explicit maxLength so the example is actually submittable.
     */
    private static function clamp(string $value, Schema $schema): string
    {
        $max = $schema->constraints['maxLength'] ?? null;

        if (is_int($max) && $max > 0 && mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max));
        }

        $min = $schema->constraints['minLength'] ?? null;

        if (is_int($min) && mb_strlen($value) < $min) {
            $value = str_pad($value, $min, 'x');
        }

        return $value;
    }

    /**
     * Coerces a candidate until it satisfies the field's pattern.
     *
     * Generating a string from an arbitrary regex is not worth attempting, so
     * this tries the handful of shapes that actually occur in Laravel
     * codebases - uppercase reference codes, alphanumeric slugs - and gives up
     * gracefully rather than emitting something misleading.
     */
    private static function satisfy(string $value, Schema $schema): string
    {
        $pattern = $schema->constraints['pattern'] ?? null;

        if (! is_string($pattern) || self::matches($value, $pattern)) {
            return $value;
        }

        $alphanumeric = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? $value;

        foreach ([strtoupper($value), $alphanumeric, strtoupper($alphanumeric), 'ABC123', '12345'] as $candidate) {
            if ($candidate !== '' && self::matches(self::clamp($candidate, $schema), $pattern)) {
                return self::clamp($candidate, $schema);
            }
        }

        return $value;
    }

    /**
     * An unparseable pattern is treated as satisfied: a rule we cannot
     * evaluate is not grounds for rejecting an otherwise good example.
     */
    private static function matches(string $value, string $pattern): bool
    {
        if (! self::isUsablePattern($pattern)) {
            return true;
        }

        // Silence rather than suppress: `@` no longer stops a test runner's
        // error handler from turning the warning into a failure.
        set_error_handler(static fn (): bool => true);

        try {
            $result = preg_match($pattern, $value);
        } finally {
            restore_error_handler();
        }

        return $result === false || $result === 1;
    }

    /**
     * A PHP pattern must open with a non-alphanumeric delimiter. Laravel's
     * `regex:` rule hands us the raw string, so it may be anything at all.
     */
    private static function isUsablePattern(string $pattern): bool
    {
        if (strlen($pattern) < 2) {
            return false;
        }

        $delimiter = $pattern[0];

        return ! ctype_alnum($delimiter) && $delimiter !== '\\' && $delimiter !== "\0";
    }

    private static function fromName(string $name): string|int|null
    {
        if ($name === '') {
            return null;
        }

        $needle = strtolower($name);

        foreach (self::HINTS as $fragment => $value) {
            if ($needle === $fragment || str_contains($needle, $fragment)) {
                return $value;
            }
        }

        return null;
    }
}
