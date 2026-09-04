<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Support\Data;

final readonly class Parameter
{
    public function __construct(
        public string $name,
        public ParameterLocation $in,
        public Schema $schema,
        public bool $required = false,
        public ?string $description = null,
        public bool $deprecated = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Data::string($data, 'name'),
            in: ParameterLocation::from(Data::string($data, 'in', 'query')),
            schema: Schema::fromArray(Data::map($data, 'schema')),
            required: Data::bool($data, 'required'),
            description: Data::nullableString($data, 'description'),
            deprecated: Data::bool($data, 'deprecated'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'in' => $this->in->value,
            'schema' => $this->schema->toArray(),
            'required' => $this->required ?: null,
            'description' => $this->description,
            'deprecated' => $this->deprecated ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
