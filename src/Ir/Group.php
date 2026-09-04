<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

/**
 * A named set of endpoints — one resource, usually.
 *
 * A group is scoped to an API version when the API has more than one, because
 * `v1`'s Users and `v2`'s Users are two different sets of operations that
 * happen to share a noun. Below one version the field stays null, so an
 * unversioned API's anchors and tags read exactly as they always have.
 */
final readonly class Group
{
    /**
     * @param  list<Endpoint>  $endpoints
     */
    public function __construct(
        public string $name,
        public array $endpoints = [],
        public ?string $description = null,
        public ?string $slug = null,
        public ?string $version = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Data::string($data, 'name'),
            endpoints: array_map(
                static fn (array $endpoint): Endpoint => Endpoint::fromArray($endpoint),
                Data::maps($data, 'endpoints'),
            ),
            description: Data::nullableString($data, 'description'),
            slug: Data::nullableString($data, 'slug'),
            version: Data::nullableString($data, 'version'),
        );
    }

    public function slug(): string
    {
        if ($this->slug !== null) {
            return $this->slug;
        }

        // The version has to be in the anchor: `#users` can only mean one
        // section of the index, and a versioned API has one per version.
        $name = $this->version === null ? $this->name : $this->version.' '.$this->name;

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
    }

    /**
     * The name for surfaces that are a flat list and have nowhere to put a
     * version heading — OpenAPI tags, Postman folders. The HTML nests groups
     * under their version instead, and uses the plain name.
     */
    public function displayName(): string
    {
        return $this->version === null ? $this->name : "{$this->name} ({$this->version})";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug(),
            'version' => $this->version,
            'description' => $this->description,
            'endpoints' => array_map(static fn (Endpoint $e): array => $e->toArray(), $this->endpoints),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
