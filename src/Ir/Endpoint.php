<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Support\Data;

/**
 * One documented operation.
 *
 * The `id` is the package's stability contract: it is derived from the route
 * name (or, failing that, from method + URI) and must not change between
 * builds. It is the OpenAPI operationId, the HTML anchor, the Markdown file
 * name and the citation target, so churn here breaks every inbound link an
 * LLM or search engine has learned.
 */
final readonly class Endpoint
{
    /**
     * @param  list<Parameter>  $parameters
     * @param  list<Response>  $responses
     * @param  list<string>  $tags
     * @param  list<string>  $sourceFiles  absolute paths the extractors read; drives incremental rebuilds
     * @param  string|null  $version  the API version this endpoint belongs to, as its URL spells it
     * @param  string|null  $supersededBy  id of the same operation in a newer version, when there is one
     * @param  bool|null  $tryIt  false withholds the playground from this operation; null leaves the site's setting alone
     */
    public function __construct(
        public string $id,
        public HttpMethod $method,
        public string $uri,
        public ?string $routeName = null,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $group = null,
        public array $parameters = [],
        public array $responses = [],
        public bool $authenticated = false,
        public bool $deprecated = false,
        public array $tags = [],
        public array $sourceFiles = [],
        public ?RateLimit $rateLimit = null,
        public ?SecurityScheme $security = null,
        public ?string $version = null,
        public ?string $supersededBy = null,
        public ?bool $tryIt = null,
    ) {}

    public static function make(
        HttpMethod $method,
        string $uri,
        ?string $routeName = null,
        ?string $id = null,
    ): self {
        return new self(
            id: $id ?? self::deriveId($method, $uri, $routeName),
            method: $method,
            uri: ltrim($uri, '/'),
            routeName: $routeName,
        );
    }

    /**
     * Stable, collision-resistant identifier. Route names win because they are
     * already the host app's own stable handle for the operation.
     */
    public static function deriveId(HttpMethod $method, string $uri, ?string $routeName = null): string
    {
        if ($routeName !== null && $routeName !== '') {
            return $routeName;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($uri, '/'))) ?? '';

        return strtolower($method->value).'-'.trim($slug, '-');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Data::string($data, 'id'),
            method: HttpMethod::from(Data::string($data, 'method', 'GET')),
            uri: Data::string($data, 'uri'),
            routeName: Data::nullableString($data, 'routeName'),
            summary: Data::nullableString($data, 'summary'),
            description: Data::nullableString($data, 'description'),
            group: Data::nullableString($data, 'group'),
            parameters: array_map(
                static fn (array $parameter): Parameter => Parameter::fromArray($parameter),
                Data::maps($data, 'parameters'),
            ),
            responses: array_map(
                static fn (array $response): Response => Response::fromArray($response),
                Data::maps($data, 'responses'),
            ),
            authenticated: Data::bool($data, 'authenticated'),
            deprecated: Data::bool($data, 'deprecated'),
            tags: Data::strings($data, 'tags'),
            sourceFiles: Data::strings($data, 'sourceFiles'),
            rateLimit: isset($data['rateLimit']) && is_array($data['rateLimit'])
                ? RateLimit::fromArray(Data::map($data, 'rateLimit'))
                : null,
            security: isset($data['security']) && is_array($data['security'])
                ? SecurityScheme::fromArray(Data::map($data, 'security'))
                : null,
            version: Data::nullableString($data, 'version'),
            supersededBy: Data::nullableString($data, 'supersededBy'),
            tryIt: isset($data['tryIt']) ? Data::bool($data, 'tryIt') : null,
        );
    }

    /**
     * Leading-slash path as it appears in requests.
     */
    public function path(): string
    {
        return '/'.ltrim($this->uri, '/');
    }

    /**
     * URL-safe segment for the static HTML/Markdown file name.
     */
    public function slug(): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->id)) ?? '', '-');
    }

    /**
     * Title used for <h1>, <title> and the Markdown heading. Kept identical
     * across surfaces so a page and its mirror are recognisably the same doc.
     */
    public function title(): string
    {
        return $this->summary ?? "{$this->method->value} {$this->path()}";
    }

    /**
     * @return list<Parameter>
     */
    public function parametersIn(ParameterLocation $in): array
    {
        return array_values(array_filter(
            $this->parameters,
            static fn (Parameter $p): bool => $p->in === $in,
        ));
    }

    public function hasBody(): bool
    {
        return $this->parametersIn(ParameterLocation::Body) !== [];
    }

    /**
     * A body carrying a file has to be sent as multipart; documenting it as
     * JSON produces an example that cannot work.
     */
    public function hasUpload(): bool
    {
        foreach ($this->parametersIn(ParameterLocation::Body) as $parameter) {
            if ($parameter->schema->format === 'binary'
                || $parameter->schema->items?->format === 'binary') {
                return true;
            }
        }

        return false;
    }

    public function requestContentType(): string
    {
        return $this->hasUpload() ? 'multipart/form-data' : 'application/json';
    }

    /**
     * The scheme this endpoint expects, defaulting to a bearer token when it
     * is known to need credentials but nothing more specific was detected.
     */
    public function securityScheme(): ?SecurityScheme
    {
        if ($this->security !== null) {
            return $this->security;
        }

        return $this->authenticated ? new SecurityScheme : null;
    }

    /**
     * @param  list<Parameter>  $parameters
     */
    public function withParameters(array $parameters): self
    {
        return $this->with(parameters: $parameters);
    }

    /**
     * @param  list<Response>  $responses
     */
    public function withResponses(array $responses): self
    {
        return $this->with(responses: $responses);
    }

    /**
     * Copy-with helper. Extractors are pipeline stages over an immutable
     * Endpoint, so they all funnel through here.
     *
     * @param  list<Parameter>|null  $parameters
     * @param  list<Response>|null  $responses
     * @param  list<string>|null  $tags
     * @param  list<string>|null  $sourceFiles
     */
    public function with(
        ?string $summary = null,
        ?string $description = null,
        ?string $group = null,
        ?array $parameters = null,
        ?array $responses = null,
        ?bool $authenticated = null,
        ?bool $deprecated = null,
        ?array $tags = null,
        ?array $sourceFiles = null,
        ?RateLimit $rateLimit = null,
        ?SecurityScheme $security = null,
        ?string $version = null,
        ?string $supersededBy = null,
        ?bool $tryIt = null,
    ): self {
        return new self(
            id: $this->id,
            method: $this->method,
            uri: $this->uri,
            routeName: $this->routeName,
            summary: $summary ?? $this->summary,
            description: $description ?? $this->description,
            group: $group ?? $this->group,
            parameters: $parameters ?? $this->parameters,
            responses: $responses ?? $this->responses,
            authenticated: $authenticated ?? $this->authenticated,
            deprecated: $deprecated ?? $this->deprecated,
            tags: $tags ?? $this->tags,
            sourceFiles: $sourceFiles ?? $this->sourceFiles,
            rateLimit: $rateLimit ?? $this->rateLimit,
            security: $security ?? $this->security,
            version: $version ?? $this->version,
            supersededBy: $supersededBy ?? $this->supersededBy,
            tryIt: $tryIt ?? $this->tryIt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'method' => $this->method->value,
            'uri' => $this->uri,
            'routeName' => $this->routeName,
            'summary' => $this->summary,
            'description' => $this->description,
            'group' => $this->group,
            'parameters' => $this->parameters === []
                ? null
                : array_map(static fn (Parameter $p): array => $p->toArray(), $this->parameters),
            'responses' => $this->responses === []
                ? null
                : array_map(static fn (Response $r): array => $r->toArray(), $this->responses),
            'authenticated' => $this->authenticated ?: null,
            'deprecated' => $this->deprecated ?: null,
            'tags' => $this->tags ?: null,
            'sourceFiles' => $this->sourceFiles ?: null,
            'rateLimit' => $this->rateLimit?->toArray(),
            'security' => $this->security?->toArray(),
            'version' => $this->version,
            'supersededBy' => $this->supersededBy,
            'tryIt' => $this->tryIt,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
