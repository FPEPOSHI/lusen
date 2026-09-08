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
     * @param  int|null  $order  where the group sits in the navigation; null sorts it after the groups that state a place
     */
    public function __construct(
        public string $name,
        public array $endpoints = [],
        public ?string $description = null,
        public ?string $slug = null,
        public ?string $version = null,
        public ?int $order = null,
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
            order: Data::nullableInt($data, 'order'),
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
     * The opening paragraph of the description, as one line.
     *
     * A description is prose, and prose written for a group's landing page
     * runs to headings, lists and several paragraphs — the fature.al
     * onboarding group narrates a six-step workflow. That belongs on the page
     * and nowhere else: a meta description, a search result and a line in an
     * index each want the first sentence, and pouring the whole thing into
     * them truncates it mid-workflow.
     */
    public function lede(): ?string
    {
        if ($this->description === null) {
            return null;
        }

        $paragraph = preg_split('/\R\s*\R/', trim($this->description), 2)[0] ?? '';

        // Newlines inside the paragraph are the author's line wrapping, not
        // structure; a single line is what every caller of this wants.
        return trim((string) preg_replace('/\s+/', ' ', $paragraph)) ?: null;
    }

    /**
     * One line on what the group is for, where nobody wrote one: the
     * operations themselves, named. That is the sentence under the group's
     * page in a search result, and "List users, Create a user, Show a user."
     * says more than "Users" and invents nothing.
     */
    public function summary(): string
    {
        $lede = $this->lede();

        if ($lede !== null) {
            return $lede;
        }

        if ($this->endpoints === []) {
            return "Operations on {$this->name}.";
        }

        return implode(', ', array_map(static fn (Endpoint $e): string => $e->title(), $this->endpoints)).'.';
    }

    /**
     * Whether a reader needs a credential here, as a sentence.
     *
     * Stated on the group's own page because that page has to stand alone:
     * somebody arriving from a search result must not be sent to an
     * authentication section elsewhere to learn whether to bring a token.
     */
    public function authenticationSummary(): string
    {
        $total = count($this->endpoints);
        $authenticated = count(array_filter(
            $this->endpoints,
            static fn (Endpoint $e): bool => $e->authenticated,
        ));

        return match (true) {
            $total === 0 => 'No operations.',
            $authenticated === 0 => $total === 1
                ? 'Does not require authentication.'
                : 'None of these operations requires authentication.',
            $authenticated === $total => $total === 1
                ? 'Requires authentication.'
                : 'All of these operations require authentication.',
            default => "{$authenticated} of {$total} operations require authentication.",
        };
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
            'order' => $this->order,
            'description' => $this->description,
            'endpoints' => array_map(static fn (Endpoint $e): array => $e->toArray(), $this->endpoints),
        ], static fn (mixed $v): bool => $v !== null);
    }
}
