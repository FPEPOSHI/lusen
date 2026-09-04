<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

/**
 * The root of the intermediate representation and the only thing emitters are
 * allowed to read.
 *
 * Deliberately free of timestamps and absolute build paths: the spec must
 * serialise deterministically so `hash()` is a usable cache key and so a
 * rebuild that changed nothing produces byte-identical output.
 */
final readonly class ApiSpec
{
    /**
     * @param  string  $version  the documentation's own release number, not the API's
     * @param  list<Group>  $groups  endpoint reference
     * @param  list<Section>  $sections  authored prose pages
     * @param  array<string, string>  $servers  label => base URL
     * @param  list<ApiVersion>  $versions  the API versions the routes serve, newest first
     */
    public function __construct(
        public string $title,
        public string $version = '1.0.0',
        public array $groups = [],
        public ?string $description = null,
        public ?string $baseUrl = null,
        public array $servers = [],
        public array $sections = [],
        public array $versions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: Data::string($data, 'title'),
            version: Data::string($data, 'version', '1.0.0'),
            groups: array_map(
                static fn (array $group): Group => Group::fromArray($group),
                Data::maps($data, 'groups'),
            ),
            description: Data::nullableString($data, 'description'),
            baseUrl: Data::nullableString($data, 'baseUrl'),
            servers: Data::stringMap($data, 'servers'),
            sections: array_map(
                static fn (array $section): Section => Section::fromArray($section),
                Data::maps($data, 'sections'),
            ),
            versions: array_map(
                static fn (array $version): ApiVersion => ApiVersion::fromArray($version),
                Data::maps($data, 'versions'),
            ),
        );
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($decoded);
    }

    /**
     * Every endpoint across every group, in group order.
     *
     * @return list<Endpoint>
     */
    public function endpoints(): array
    {
        return array_merge(...array_map(
            static fn (Group $g): array => $g->endpoints,
            $this->groups,
        )) ?: [];
    }

    /**
     * Every prose page across every section, in section order.
     *
     * @return list<Page>
     */
    public function pages(): array
    {
        return array_merge(...array_map(
            static fn (Section $s): array => $s->pages,
            $this->sections,
        ));
    }

    public function page(string $id): ?Page
    {
        foreach ($this->pages() as $page) {
            if ($page->id === $id) {
                return $page;
            }
        }

        return null;
    }

    public function endpoint(?string $id): ?Endpoint
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint->id === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * Whether the API serves more than one version at once.
     *
     * One version is not worth disambiguating: stamping "v1" on forty page
     * titles adds noise to every one of them and tells a reader nothing they
     * could act on. Two is the point at which every title, tag and anchor has
     * to say which version it belongs to.
     */
    public function isVersioned(): bool
    {
        return count($this->versions) > 1;
    }

    public function currentVersion(): ?ApiVersion
    {
        foreach ($this->versions as $version) {
            if ($version->current) {
                return $version;
            }
        }

        return null;
    }

    public function apiVersion(?string $name): ?ApiVersion
    {
        foreach ($this->versions as $version) {
            if ($version->name === $name) {
                return $version;
            }
        }

        return null;
    }

    /**
     * The endpoints belonging to one API version, in group order.
     *
     * @return list<Endpoint>
     */
    public function endpointsIn(string $version): array
    {
        return array_values(array_filter(
            $this->endpoints(),
            static fn (Endpoint $endpoint): bool => $endpoint->version === $version,
        ));
    }

    /**
     * The group an endpoint was filed under.
     *
     * Looked up rather than rebuilt from the endpoint's group name: with two
     * versions in play the name alone no longer identifies the group, and a
     * breadcrumb reconstructing the slug would link to a section that is not
     * there.
     */
    public function groupFor(Endpoint $endpoint): ?Group
    {
        foreach ($this->groups as $group) {
            foreach ($group->endpoints as $member) {
                if ($member->id === $endpoint->id) {
                    return $group;
                }
            }
        }

        return null;
    }

    public function group(string $slug): ?Group
    {
        foreach ($this->groups as $group) {
            if ($group->slug() === $slug) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Content hash of the whole spec. Used to short-circuit builds and as the
     * sitemap `lastmod` discriminator.
     */
    public function hash(): string
    {
        return hash('xxh128', $this->toJson());
    }

    /**
     * @param  list<Group>  $groups
     */
    public function withGroups(array $groups): self
    {
        return $this->with(groups: $groups);
    }

    /**
     * @param  list<Section>  $sections
     */
    public function withSections(array $sections): self
    {
        return $this->with(sections: $sections);
    }

    /**
     * @param  list<Group>|null  $groups
     * @param  list<Section>|null  $sections
     * @param  list<ApiVersion>|null  $versions
     */
    public function with(?array $groups = null, ?array $sections = null, ?array $versions = null): self
    {
        return new self(
            title: $this->title,
            version: $this->version,
            groups: $groups ?? $this->groups,
            description: $this->description,
            baseUrl: $this->baseUrl,
            servers: $this->servers,
            sections: $sections ?? $this->sections,
            versions: $versions ?? $this->versions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'baseUrl' => $this->baseUrl,
            'servers' => $this->servers ?: null,
            'versions' => $this->versions === []
                ? null
                : array_map(static fn (ApiVersion $v): array => $v->toArray(), $this->versions),
            'sections' => $this->sections === []
                ? null
                : array_map(static fn (Section $s): array => $s->toArray(), $this->sections),
            'groups' => array_map(static fn (Group $g): array => $g->toArray(), $this->groups),
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode(
            $this->toArray(),
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
