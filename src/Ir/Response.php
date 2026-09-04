<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

final readonly class Response
{
    /**
     * @param  list<Example>  $examples
     * @param  array<string, Schema>  $headers  response headers, by name
     */
    public function __construct(
        public int $status,
        public ?string $description = null,
        public ?Schema $schema = null,
        public array $examples = [],
        public string $contentType = 'application/json',
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $schema = Data::nullableMap($data, 'schema');

        return new self(
            status: Data::int($data, 'status', 200),
            description: Data::nullableString($data, 'description'),
            schema: $schema === null ? null : Schema::fromArray($schema),
            examples: array_map(
                static fn (array $example): Example => Example::fromArray($example),
                Data::maps($data, 'examples'),
            ),
            contentType: Data::string($data, 'contentType', 'application/json'),
            headers: array_map(
                static fn (array $header): Schema => Schema::fromArray($header),
                Data::mapOfMaps($data, 'headers'),
            ),
        );
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Falls back to the standard reason phrase so no response ever renders
     * without a label - agents and crawlers both need the status explained.
     */
    public function label(): string
    {
        if ($this->description !== null && $this->description !== '') {
            return $this->description;
        }

        return $this->reasonPhrase();
    }

    /**
     * The status code's standard meaning, ignoring this endpoint's own wording.
     *
     * A cross-API error reference needs the general meaning: one endpoint
     * describing 404 as "no customer with that id" is true there and wrong as
     * a statement about the status.
     */
    public function reasonPhrase(): string
    {
        return match ($this->status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'Response',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'description' => $this->description,
            'schema' => $this->schema?->toArray(),
            'examples' => $this->examples === []
                ? null
                : array_map(static fn (Example $e): array => $e->toArray(), $this->examples),
            'contentType' => $this->contentType,
            'headers' => $this->headers === []
                ? null
                : array_map(static fn (Schema $h): array => $h->toArray(), $this->headers),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
