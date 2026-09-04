<?php

declare(strict_types=1);

namespace Lusen\Extract\Rules;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;

/**
 * One field's validation rules, translated into a schema.
 *
 * Laravel's rule vocabulary is the richest description of an API's input that
 * most applications already have, so this is where the bulk of a generated
 * spec's detail comes from. The translation is deliberately conservative: a
 * rule that carries no schema meaning (`exists`, `unique`, `confirmed`)
 * changes nothing rather than being guessed at.
 */
final readonly class RuleSet
{
    /**
     * Rules whose only job is a database lookup or a cross-field check. They
     * say nothing about the shape of the value.
     *
     * @var list<string>
     */
    private const OPAQUE = [
        'exists', 'unique', 'confirmed', 'current_password', 'password',
        'different', 'same', 'filled', 'present', 'bail', 'sometimes',
    ];

    /**
     * @param  list<string>  $rules  normalised rule strings, e.g. ['required', 'max:255']
     */
    public function __construct(private array $rules) {}

    /**
     * Splits Laravel's two accepted spellings - `'required|email'` and
     * `['required', 'email']` - into a flat list.
     *
     * Non-string entries - Rule objects, closures - carry no statically
     * readable constraint and are dropped.
     *
     * @param  string|array<mixed>  $rules
     */
    public static function parse(string|array $rules): self
    {
        $flat = [];

        foreach ((array) $rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            foreach (explode('|', $rule) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $flat[] = $part;
                }
            }
        }

        return new self($flat);
    }

    public function isRequired(): bool
    {
        // `sometimes` means the field may be absent entirely, which is exactly
        // what optional means here even when `required` is also present.
        if ($this->has('sometimes')) {
            return false;
        }

        return $this->has('required')
            || $this->hasPrefix('required_if')
            || $this->hasPrefix('required_with')
            || $this->hasPrefix('required_unless');
    }

    public function isNullable(): bool
    {
        return $this->has('nullable');
    }

    public function isUpload(): bool
    {
        foreach ($this->rules as $rule) {
            if (in_array($rule, ['file', 'image'], true) || str_starts_with($rule, 'mimes:')) {
                return true;
            }
        }

        return false;
    }

    public function isProhibited(): bool
    {
        return $this->has('prohibited');
    }

    public function isArray(): bool
    {
        return $this->has('array') || $this->has('list');
    }

    public function toSchema(): Schema
    {
        $type = $this->type();

        return new Schema(
            type: $type,
            format: $this->format(),
            nullable: $this->isNullable(),
            enum: $this->enum(),
            constraints: $this->constraints($type),
        );
    }

    /**
     * Human-readable notes for rules that constrain a value in a way the
     * schema cannot express, so the information is not simply lost.
     */
    public function note(): ?string
    {
        $notes = [];

        foreach ($this->rules as $rule) {
            if (str_starts_with($rule, 'mimes:')) {
                $notes[] = 'Accepted types: '.str_replace(',', ', ', substr($rule, 6)).'.';
            }

            if (str_starts_with($rule, 'max:') && $this->isUpload()) {
                $notes[] = 'Maximum size '.substr($rule, 4).' KB.';
            }
        }

        if ($this->has('unique') || $this->hasPrefix('unique')) {
            $notes[] = 'Must be unique.';
        }

        if ($this->has('confirmed')) {
            $notes[] = 'Requires a matching confirmation field.';
        }

        foreach ($this->rules as $rule) {
            if (str_starts_with($rule, 'required_with:')) {
                $notes[] = 'Required when '.str_replace(',', ', ', substr($rule, 14)).' is present.';
            }

            if (str_starts_with($rule, 'required_if:')) {
                $notes[] = 'Required depending on '.explode(',', substr($rule, 12))[0].'.';
            }
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->rules;
    }

    public function isOpaque(): bool
    {
        foreach ($this->rules as $rule) {
            if (! in_array(explode(':', $rule)[0], self::OPAQUE, true)) {
                return false;
            }
        }

        return true;
    }

    private function type(): SchemaType
    {
        foreach ($this->rules as $rule) {
            $type = match ($rule) {
                'integer', 'int' => SchemaType::Integer,
                'numeric', 'decimal' => SchemaType::Number,
                'boolean', 'bool', 'accepted', 'declined' => SchemaType::Boolean,
                'array', 'list' => SchemaType::Array,
                'json' => SchemaType::Object,
                'string', 'email', 'url', 'uuid', 'ulid', 'ip', 'date' => SchemaType::String,
                default => null,
            };

            if ($type !== null) {
                return $type;
            }

            if (str_starts_with($rule, 'decimal:')) {
                return SchemaType::Number;
            }
        }

        // An `in:` rule with no type rule still implies a string enum.
        return SchemaType::String;
    }

    private function format(): ?string
    {
        foreach ($this->rules as $rule) {
            // An uploaded file is binary, and a body containing one cannot be
            // sent as JSON at all.
            if (in_array($rule, ['file', 'image'], true)
                || str_starts_with($rule, 'mimes:')
                || str_starts_with($rule, 'mimetypes:')) {
                return 'binary';
            }

            $format = match ($rule) {
                'email' => 'email',
                'url', 'active_url' => 'uri',
                'uuid' => 'uuid',
                'ulid' => 'ulid',
                'ip', 'ipv4' => 'ipv4',
                'ipv6' => 'ipv6',
                'date' => 'date-time',
                default => null,
            };

            if ($format !== null) {
                return $format;
            }

            if ($rule === 'date_format:Y-m-d') {
                return 'date';
            }

            if (str_starts_with($rule, 'date_format:')) {
                return 'date-time';
            }
        }

        return null;
    }

    /**
     * @return list<string|int|float|bool>
     */
    private function enum(): array
    {
        foreach ($this->rules as $rule) {
            if (! str_starts_with($rule, 'in:')) {
                continue;
            }

            $values = array_map(
                static fn (string $v): string => trim($v, " \t\"'"),
                explode(',', substr($rule, 3)),
            );

            return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
        }

        return [];
    }

    /**
     * `min` and `max` mean different things depending on the type - length for
     * strings, value for numbers, item count for arrays - so the type has to
     * be resolved first.
     *
     * @return array<string, string|int|float|bool>
     */
    private function constraints(SchemaType $type): array
    {
        $constraints = [];

        foreach ($this->rules as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

            if ($argument === null) {
                continue;
            }

            match ($name) {
                'min' => $constraints[$this->boundKey('min', $type)] = $this->number($argument),
                'max' => $constraints[$this->boundKey('max', $type)] = $this->number($argument),
                'size' => $constraints = array_merge($constraints, $this->size($argument, $type)),
                'between' => $constraints = array_merge($constraints, $this->between($argument, $type)),
                'regex' => $constraints['pattern'] = $argument,
                'digits' => $constraints['pattern'] = '^\d{'.$argument.'}$',
                default => null,
            };
        }

        return $constraints;
    }

    private function boundKey(string $bound, SchemaType $type): string
    {
        return match ($type) {
            SchemaType::String => $bound === 'min' ? 'minLength' : 'maxLength',
            SchemaType::Array => $bound === 'min' ? 'minItems' : 'maxItems',
            default => $bound === 'min' ? 'min' : 'max',
        };
    }

    /**
     * @return array<string, int|float>
     */
    private function size(string $argument, SchemaType $type): array
    {
        $value = $this->number($argument);

        return [
            $this->boundKey('min', $type) => $value,
            $this->boundKey('max', $type) => $value,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function between(string $argument, SchemaType $type): array
    {
        [$min, $max] = array_pad(explode(',', $argument, 2), 2, null);

        if ($min === null || $max === null) {
            return [];
        }

        return [
            $this->boundKey('min', $type) => $this->number($min),
            $this->boundKey('max', $type) => $this->number($max),
        ];
    }

    private function number(string $value): int|float
    {
        $value = trim($value);

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function has(string $rule): bool
    {
        return in_array($rule, $this->rules, true);
    }

    private function hasPrefix(string $prefix): bool
    {
        foreach ($this->rules as $rule) {
            if (str_starts_with($rule, $prefix.':')) {
                return true;
            }
        }

        return false;
    }
}
