<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

final readonly class Example
{
    public function __construct(
        public string $label,
        public mixed $value,
        public string $contentType = 'application/json',
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: Data::string($data, 'label'),
            value: $data['value'] ?? null,
            contentType: Data::string($data, 'contentType', 'application/json'),
            description: Data::nullableString($data, 'description'),
        );
    }

    /**
     * Pretty-printed body for the docs UI and Markdown mirror.
     */
    public function render(): string
    {
        if (is_string($this->value)) {
            return $this->value;
        }

        return json_encode(
            $this->value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'value' => $this->value,
            'contentType' => $this->contentType,
            'description' => $this->description,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
