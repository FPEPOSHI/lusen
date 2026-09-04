<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;
use Lusen\Support\Str;

/**
 * A named group of prose pages in the sidebar - "Getting started", "Guides".
 *
 * The prose counterpart to Group, which does the same job for endpoints.
 */
final readonly class Section
{
    /**
     * @param  list<Page>  $pages
     */
    public function __construct(
        public string $name,
        public array $pages = [],
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Data::string($data, 'name'),
            pages: array_map(
                static fn (array $page): Page => Page::fromArray($page),
                Data::maps($data, 'pages'),
            ),
            description: Data::nullableString($data, 'description'),
        );
    }

    public function slug(): string
    {
        return Str::slug($this->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug(),
            'description' => $this->description,
            'pages' => array_map(static fn (Page $p): array => $p->toArray(), $this->pages),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
