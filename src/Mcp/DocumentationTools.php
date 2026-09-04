<?php

declare(strict_types=1);

namespace Lusen\Mcp;

use InvalidArgumentException;
use Lusen\Emit\Markdown;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Parameter;
use Lusen\Support\Snippets;
use Lusen\Support\Str;

/**
 * The documentation, as tools an agent can call.
 *
 * Every tool returns the same Markdown a person reads. That is the whole point
 * of having built the surfaces this way: the model is not given a lossy
 * summary of the docs, it is given the docs. Anything true on the page is true
 * here, and there is only one thing to keep correct.
 *
 * Scraping the HTML would also work and would be worse - it would break on
 * every restyle, and it makes the model pay for markup it cannot use.
 */
final class DocumentationTools implements ToolProvider
{
    /**
     * @var array<string, Tool>|null
     */
    private ?array $tools = null;

    public function __construct(private readonly ApiSpec $spec) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (Tool $tool): array => $tool->definition(),
            $this->tools(),
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $name, array $arguments): string
    {
        $tool = $this->tools()[$name] ?? null;

        if ($tool === null) {
            throw new InvalidArgumentException(
                "Unknown tool [{$name}]. Available: ".implode(', ', array_keys($this->tools())).'.'
            );
        }

        return $tool->call($arguments);
    }

    /**
     * @return array<string, Tool>
     */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [
            new Tool(
                name: 'search_documentation',
                description: 'Search this API\'s documentation by keyword. Returns matching endpoints and guide pages with their ids. Start here when you do not already know an endpoint id.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Words to search for, e.g. "create customer" or "refund".'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum results. Defaults to 10.'],
                    ],
                    'required' => ['query'],
                ],
                handler: fn (array $arguments): string => $this->search($arguments),
            ),
            new Tool(
                name: 'get_endpoint',
                description: 'Full documentation for one endpoint: every parameter, every response status, and a runnable example request. Takes the id returned by search_documentation or list_endpoints.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'The endpoint id, e.g. "customers.store".'],
                    ],
                    'required' => ['id'],
                ],
                handler: fn (array $arguments): string => $this->endpoint($arguments),
            ),
            new Tool(
                name: 'list_endpoints',
                description: 'Every endpoint in the API, grouped, with its id, method and path. Use this to see the whole surface at once.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'group' => ['type' => 'string', 'description' => 'Restrict to one group name.'],
                    ],
                ],
                handler: fn (array $arguments): string => $this->listEndpoints($arguments),
            ),
            new Tool(
                name: 'read_guide',
                description: 'Read a written guide page - introduction, authentication, errors, rate limiting, use cases. Call this before making requests: it explains how the API expects to be used. Omit the id to list the available pages.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Page id, e.g. "authentication". Omit to list pages.'],
                    ],
                ],
                handler: fn (array $arguments): string => $this->guide($arguments),
            ),
            new Tool(
                name: 'build_request',
                description: 'Build a runnable request for an endpoint, filling in the values you supply and schema-valid examples for the rest. Returns a curl command and the resolved URL, headers and body.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'The endpoint id.'],
                        'values' => [
                            'type' => 'object',
                            'description' => 'Parameter values to use, keyed by parameter name.',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['id'],
                ],
                handler: fn (array $arguments): string => $this->buildRequest($arguments),
            ),
        ];

        $keyed = [];

        foreach ($tools as $tool) {
            $keyed[$tool->name] = $tool;
        }

        return $this->tools = $keyed;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function search(array $arguments): string
    {
        $query = $this->string($arguments, 'query');
        $limit = max(1, min(50, $this->int($arguments, 'limit', 10)));

        $terms = array_values(array_filter(preg_split('/\s+/', strtolower(trim($query))) ?: []));

        if ($terms === []) {
            return 'Provide a search term.';
        }

        $hits = [];

        foreach ($this->spec->pages() as $page) {
            $score = $this->score($terms, $page->title, '', $page->markdown);

            if ($score > 0) {
                $hits[] = [$score, "- **{$page->title}** — guide page, id `{$page->id}`. Read with `read_guide`."];
            }
        }

        foreach ($this->spec->endpoints() as $endpoint) {
            $score = $this->score(
                $terms,
                $endpoint->title(),
                $endpoint->path(),
                ($endpoint->description ?? '').' '.$this->parameterNames($endpoint),
            );

            if ($score > 0) {
                $hits[] = [$score, sprintf(
                    '- **%s** — `%s %s`, id `%s`. Read with `get_endpoint`.',
                    $endpoint->title(),
                    $endpoint->method->value,
                    $endpoint->path(),
                    $endpoint->id,
                )];
            }
        }

        if ($hits === []) {
            return "No matches for \"{$query}\". Try `list_endpoints` to see the whole API.";
        }

        usort($hits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $lines = ['Results for "'.$query.'":', ''];

        foreach (array_slice($hits, 0, $limit) as [, $line]) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $terms
     */
    private function score(array $terms, string $title, string $path, string $body): int
    {
        $title = strtolower($title);
        $path = strtolower($path);
        $body = strtolower($body);
        $total = 0;

        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $total += 8;
            } elseif (str_contains($path, $term)) {
                $total += 5;
            } elseif (str_contains($body, $term)) {
                $total += 1;
            } else {
                // Every term must appear somewhere, so a two-word query does
                // not match on the commoner word alone.
                return 0;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function endpoint(array $arguments): string
    {
        $endpoint = $this->findEndpoint($this->string($arguments, 'id'));

        $lines = ["# {$endpoint->title()}", ''];

        if ($endpoint->group !== null) {
            $lines[] = "Part of **{$endpoint->group}** in the {$this->spec->title}.";
            $lines[] = '';
        }

        if ($endpoint->rateLimit !== null) {
            $lines[] = "Rate limit: {$endpoint->rateLimit->label()}.";
            $lines[] = '';
        }

        $successor = $this->spec->endpoint($endpoint->supersededBy);

        // The id as well as the path: a model reading this reaches the newer
        // edition by calling get_endpoint, and an id is what that takes.
        if ($successor !== null) {
            $lines[] = "Superseded by id `{$successor->id}`.";
            $lines[] = '';
        }

        return implode("\n", [...$lines, ...Markdown::endpoint(
            $endpoint,
            $this->spec->baseUrl,
            2,
            successor: $successor,
        )]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function listEndpoints(array $arguments): string
    {
        $filter = $this->string($arguments, 'group', required: false);

        $lines = ["# {$this->spec->title}", ''];

        if ($this->spec->baseUrl !== null) {
            $lines[] = "Base URL: `{$this->spec->baseUrl}`";
            $lines[] = '';
        }

        $matched = false;

        $lines = [...$lines, ...Markdown::versions($this->spec)];

        foreach ($this->spec->groups as $group) {
            // Matched on the plain name so "Orders" still finds the group in
            // every version, and on the display name so "Orders (v2)" - which
            // is what the listing shows - finds exactly one.
            if ($filter !== '' && strcasecmp($group->name, $filter) !== 0 && strcasecmp($group->displayName(), $filter) !== 0) {
                continue;
            }

            $matched = true;
            $lines[] = "## {$group->displayName()}";
            $lines[] = '';

            foreach ($group->endpoints as $endpoint) {
                $lines[] = sprintf(
                    '- `%s %s` — %s (id `%s`)%s%s',
                    $endpoint->method->value,
                    $endpoint->path(),
                    $endpoint->summary ?? $endpoint->title(),
                    $endpoint->id,
                    $endpoint->authenticated ? ', authenticated' : '',
                    $endpoint->deprecated ? ', deprecated' : '',
                );
            }

            $lines[] = '';
        }

        if (! $matched) {
            $names = array_map(static fn ($group): string => $group->displayName(), $this->spec->groups);

            return "No group named \"{$filter}\". Available groups: ".implode(', ', $names).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function guide(array $arguments): string
    {
        $id = $this->string($arguments, 'id', required: false);

        if ($id === '') {
            return $this->listGuides();
        }

        $page = $this->spec->page($id);

        if ($page === null) {
            return "No guide page with id \"{$id}\".\n\n".$this->listGuides();
        }

        return "# {$page->title}\n\n".$page->markdown;
    }

    private function listGuides(): string
    {
        $pages = $this->spec->pages();

        if ($pages === []) {
            return 'This API has no guide pages.';
        }

        $lines = ['Available guide pages:', ''];

        foreach ($pages as $page) {
            $lines[] = "- `{$page->id}` — {$page->title}: ".Str::summarise($page->summary(), 120);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function buildRequest(array $arguments): string
    {
        $endpoint = $this->findEndpoint($this->string($arguments, 'id'));

        /** @var array<string, mixed> $values */
        $values = is_array($arguments['values'] ?? null) ? $arguments['values'] : [];

        $unknown = array_diff(array_keys($values), $this->allParameterNames($endpoint));

        $lines = ["# {$endpoint->method->value} {$endpoint->path()}", ''];

        if ($unknown !== []) {
            // Silently dropping a value the caller supplied would leave the
            // model believing it was used.
            $lines[] = 'Ignored, because this endpoint has no such parameter: `'
                .implode('`, `', $unknown).'`.';
            $lines[] = '';
        }

        $applied = $this->applyValues($endpoint, $values);

        $lines[] = '```bash';
        $lines[] = Snippets::curl($applied, $this->spec->baseUrl);
        $lines[] = '```';
        $lines[] = '';

        if ($endpoint->authenticated) {
            $lines[] = 'This endpoint requires a bearer token; replace `YOUR_TOKEN`.';
            $lines[] = '';
        }

        $missing = $this->missingRequired($endpoint, $values);

        if ($missing !== []) {
            $lines[] = 'Values shown for these required parameters are examples, not real data: `'
                .implode('`, `', $missing).'`.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Rebuilds the endpoint with caller-supplied values baked into the
     * parameter examples, so the snippet generator renders them.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyValues(Endpoint $endpoint, array $values): Endpoint
    {
        if ($values === []) {
            return $endpoint;
        }

        $parameters = array_map(
            static function (Parameter $parameter) use ($values): Parameter {
                if (! array_key_exists($parameter->name, $values)) {
                    return $parameter;
                }

                return new Parameter(
                    name: $parameter->name,
                    in: $parameter->in,
                    schema: $parameter->schema->withExample($values[$parameter->name]),
                    required: $parameter->required,
                    description: $parameter->description,
                );
            },
            $endpoint->parameters,
        );

        return $endpoint->withParameters($parameters);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function missingRequired(Endpoint $endpoint, array $values): array
    {
        $missing = [];

        foreach ($endpoint->parameters as $parameter) {
            if ($parameter->required && ! array_key_exists($parameter->name, $values)) {
                $missing[] = $parameter->name;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function allParameterNames(Endpoint $endpoint): array
    {
        return array_map(
            static fn (Parameter $parameter): string => $parameter->name,
            $endpoint->parameters,
        );
    }

    private function parameterNames(Endpoint $endpoint): string
    {
        return implode(' ', $this->allParameterNames($endpoint));
    }

    private function findEndpoint(string $id): Endpoint
    {
        $endpoint = $this->spec->endpoint($id);

        if ($endpoint !== null) {
            return $endpoint;
        }

        $suggestions = array_slice(array_map(
            static fn (Endpoint $candidate): string => $candidate->id,
            $this->spec->endpoints(),
        ), 0, 8);

        throw new InvalidArgumentException(
            "No endpoint with id \"{$id}\". Try `search_documentation`, or one of: "
            .implode(', ', $suggestions).'.'
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function string(array $arguments, string $key, bool $required = true): string
    {
        $value = $arguments[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($required) {
            throw new InvalidArgumentException("The \"{$key}\" argument is required.");
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function int(array $arguments, string $key, int $default): int
    {
        $value = $arguments[$key] ?? null;

        return is_int($value) ? $value : $default;
    }
}
