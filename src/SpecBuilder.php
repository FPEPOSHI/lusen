<?php

declare(strict_types=1);

namespace Lusen;

use Lusen\Collect\PageCollector;
use Lusen\Collect\RouteCollector;
use Lusen\Extract\ExtractionPipeline;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Group;
use Lusen\Ir\Page;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Ir\Section;
use Lusen\Pages\DefaultPages;
use Lusen\Pages\PageSections;
use Lusen\Support\Data;
use Lusen\Support\Versions;

/**
 * Collect, extract, group. Produces the ApiSpec every emitter reads.
 *
 * The only class that knows about more than one stage, and deliberately thin:
 * if logic wants to live here it almost always belongs in an extractor.
 */
final readonly class SpecBuilder
{
    /**
     * @param  array<string, mixed>  $config  the whole config/lusen.php array
     */
    public function __construct(
        private RouteCollector $collector,
        private ExtractionPipeline $pipeline,
        private array $config = [],
        private ?PageCollector $pages = null,
    ) {}

    public function build(): ApiSpec
    {
        $endpoints = array_map(
            $this->describeThrottling(...),
            $this->pipeline->run($this->collector->collect()),
        );

        // After the pipeline, like the throttling headers below and for the
        // same reason: no extractor looking at one route can see the other
        // versions of it.
        $versions = Versions::catalogue($endpoints, $this->versionConfig());
        $endpoints = Versions::relate($endpoints, $versions);

        $spec = new ApiSpec(
            title: $this->string('title', 'API'),
            version: $this->string('version', '1.0.0'),
            groups: $this->group($endpoints, $versions),
            description: $this->nullableString('description'),
            baseUrl: $this->nullableString('base_url'),
            servers: $this->servers(),
            versions: $versions,
        );

        // Two phases on purpose: the derived pages describe the endpoints, so
        // they can only be written once the reference half of the spec exists.
        return $spec->withSections($this->sections($spec));
    }

    /**
     * Authored pages first, then whichever standard pages the author did not
     * write. An authored page of the same id always wins.
     *
     * @return list<Section>
     */
    private function sections(ApiSpec $spec): array
    {
        return PageSections::build(
            spec: $spec,
            authored: $this->pages?->collect() ?? [],
            order: $this->sectionOrder(),
            generate: $this->generateDefaults(),
        );
    }

    /**
     * @return list<string>
     */
    private function sectionOrder(): array
    {
        $pages = $this->config['pages'] ?? [];

        if (! is_array($pages)) {
            return [DefaultPages::SECTION];
        }

        $order = $pages['sections'] ?? [];

        if (! is_array($order) || $order === []) {
            return [DefaultPages::SECTION];
        }

        return array_values(array_filter($order, static fn (mixed $v): bool => is_string($v)));
    }

    private function generateDefaults(): bool
    {
        $pages = $this->config['pages'] ?? [];

        if (! is_array($pages)) {
            return true;
        }

        return (bool) ($pages['generate'] ?? true);
    }

    /**
     * A 429 from a throttled route always carries Retry-After, and somebody
     * handling that error looks at the response, not at a prose page.
     *
     * Applied after extraction rather than inside it: the 429 itself usually
     * arrives from an attribute or a resource, which is later in the pipeline
     * than any extractor that knows about middleware.
     */
    private function describeThrottling(Endpoint $endpoint): Endpoint
    {
        if ($endpoint->rateLimit === null) {
            return $endpoint;
        }

        $responses = $endpoint->responses;
        $changed = false;

        foreach ($responses as $index => $response) {
            if ($response->status !== 429 || $response->headers !== []) {
                continue;
            }

            $responses[$index] = new Response(
                status: $response->status,
                description: $response->description,
                schema: $response->schema,
                examples: $response->examples,
                contentType: $response->contentType,
                headers: ['Retry-After' => new Schema(
                    type: SchemaType::Integer,
                    description: 'Seconds to wait before retrying.',
                )],
            );

            $changed = true;
        }

        return $changed ? $endpoint->withResponses($responses) : $endpoint;
    }

    /**
     * Endpoints carry a group name; groups are derived, not configured, so a
     * new controller shows up in the docs without anyone editing a file.
     *
     * A versioned API groups by version first, newest version first, so a
     * reader meets the version they should be writing against before the one
     * they should be leaving. Endpoints outside any version — a health check
     * that has never moved — sort last, since they belong to no era.
     *
     * Below two versions nothing is scoped at all, so a single-version API
     * keeps exactly the group names and anchors it had before.
     *
     * @param  list<Endpoint>  $endpoints
     * @param  list<ApiVersion>  $versions
     * @return list<Group>
     */
    private function group(array $endpoints, array $versions = []): array
    {
        $scoped = count($versions) > 1;
        $order = Versions::order($versions);

        /** @var array<string, array{version: string|null, name: string, endpoints: list<Endpoint>}> $buckets */
        $buckets = [];

        foreach ($endpoints as $endpoint) {
            $version = $scoped ? $endpoint->version : null;
            $name = $endpoint->group ?? 'General';

            $buckets[$version.'|'.$name] ??= ['version' => $version, 'name' => $name, 'endpoints' => []];
            $buckets[$version.'|'.$name]['endpoints'][] = $endpoint;
        }

        uasort($buckets, static fn (array $a, array $b): int => [
            $a['version'] === null ? PHP_INT_MAX : ($order[$a['version']] ?? PHP_INT_MAX), $a['name'],
        ] <=> [
            $b['version'] === null ? PHP_INT_MAX : ($order[$b['version']] ?? PHP_INT_MAX), $b['name'],
        ]);

        return array_values(array_map(
            static fn (array $bucket): Group => new Group(
                name: $bucket['name'],
                endpoints: $bucket['endpoints'],
                version: $bucket['version'],
            ),
            $buckets,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function versionConfig(): array
    {
        return Data::map($this->config, 'versions');
    }

    /**
     * @return array<string, string>
     */
    private function servers(): array
    {
        $servers = $this->config['servers'] ?? [];

        if (! is_array($servers)) {
            return [];
        }

        /** @var array<string, string> $filtered */
        $filtered = array_filter(
            $servers,
            static fn (mixed $v): bool => is_string($v),
        );

        return $filtered;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
