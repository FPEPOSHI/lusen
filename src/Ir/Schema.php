<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Support\Data;

/**
 * A JSON-Schema-shaped description of one value.
 *
 * Deliberately narrower than JSON Schema: only what the emitters can render
 * well. Anything exotic belongs in an extractor's own representation, not here.
 */
final readonly class Schema
{
    /**
     * @param  list<string|int|float|bool>  $enum
     * @param  array<string, Schema>  $properties
     * @param  list<string>  $required  names of required properties, for object schemas
     * @param  array<string, string|int|float|bool>  $constraints  min, max, minLength, maxLength, pattern
     */
    public function __construct(
        public SchemaType $type = SchemaType::String,
        public ?string $format = null,
        public bool $nullable = false,
        public array $enum = [],
        public ?Schema $items = null,
        public array $properties = [],
        public array $required = [],
        public array $constraints = [],
        public mixed $example = null,
        public ?string $description = null,
    ) {}

    public static function string(?string $format = null): self
    {
        return new self(type: SchemaType::String, format: $format);
    }

    public static function integer(): self
    {
        return new self(type: SchemaType::Integer);
    }

    public static function number(): self
    {
        return new self(type: SchemaType::Number);
    }

    public static function boolean(): self
    {
        return new self(type: SchemaType::Boolean);
    }

    /**
     * @param  array<string, Schema>  $properties
     * @param  list<string>  $required
     */
    public static function object(array $properties = [], array $required = []): self
    {
        return new self(type: SchemaType::Object, properties: $properties, required: $required);
    }

    public static function arrayOf(Schema $items): self
    {
        return new self(type: SchemaType::Array, items: $items);
    }

    /**
     * @param  list<string|int|float|bool>  $values
     */
    public static function enum(array $values, SchemaType $type = SchemaType::String): self
    {
        return new self(type: $type, enum: $values);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $items = Data::nullableMap($data, 'items');

        return new self(
            type: SchemaType::from(Data::string($data, 'type', 'string')),
            format: Data::nullableString($data, 'format'),
            nullable: Data::bool($data, 'nullable'),
            enum: Data::scalars($data, 'enum'),
            items: $items === null ? null : self::fromArray($items),
            properties: array_map(
                static fn (array $property): self => self::fromArray($property),
                Data::mapOfMaps($data, 'properties'),
            ),
            required: Data::strings($data, 'required'),
            constraints: Data::scalarMap($data, 'constraints'),
            example: $data['example'] ?? null,
            description: Data::nullableString($data, 'description'),
        );
    }

    public function withExample(mixed $example): self
    {
        return new self(
            type: $this->type,
            format: $this->format,
            nullable: $this->nullable,
            enum: $this->enum,
            items: $this->items,
            properties: $this->properties,
            required: $this->required,
            constraints: $this->constraints,
            example: $example,
            description: $this->description,
        );
    }

    public function asNullable(): self
    {
        return $this->nullable ? $this : new self(
            type: $this->type,
            format: $this->format,
            nullable: true,
            enum: $this->enum,
            items: $this->items,
            properties: $this->properties,
            required: $this->required,
            constraints: $this->constraints,
            example: $this->example,
            description: $this->description,
        );
    }

    /**
     * Keeps an existing description: the field's own docblock is more specific
     * than anything a caller could add around it.
     */
    public function describedAs(?string $description): self
    {
        if ($description === null || $description === '' || $this->description !== null) {
            return $this;
        }

        return new self(
            type: $this->type,
            format: $this->format,
            nullable: $this->nullable,
            enum: $this->enum,
            items: $this->items,
            properties: $this->properties,
            required: $this->required,
            constraints: $this->constraints,
            example: $this->example,
            description: $description,
        );
    }

    /**
     * Human-readable one-liner for the docs UI parameter table, e.g.
     * "string, max 255" or "integer, 1-100".
     */
    public static function any(): self
    {
        return new self(type: SchemaType::Any);
    }

    public function label(): string
    {
        $parts = [$this->type === SchemaType::Any ? 'any' : $this->type->value];

        if ($this->format !== null) {
            $parts[0] = "{$this->type->value}<{$this->format}>";
        }

        if ($this->enum !== []) {
            $parts[] = 'one of '.implode(', ', array_map(
                static fn (string|int|float|bool $v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
                $this->enum,
            ));
        }

        foreach (['min', 'max', 'minLength', 'maxLength'] as $key) {
            if (isset($this->constraints[$key])) {
                $parts[] = "{$key} {$this->constraints[$key]}";
            }
        }

        if ($this->nullable) {
            $parts[] = 'nullable';
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'format' => $this->format,
            'nullable' => $this->nullable ?: null,
            'enum' => $this->enum ?: null,
            'items' => $this->items?->toArray(),
            'properties' => $this->properties === []
                ? null
                : array_map(static fn (self $s): array => $s->toArray(), $this->properties),
            'required' => $this->required ?: null,
            'constraints' => $this->constraints ?: null,
            'example' => $this->example,
            'description' => $this->description,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
