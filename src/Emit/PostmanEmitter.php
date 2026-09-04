<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Support\Examples;

/**
 * A Postman collection, v2.1.
 *
 * The other machine surfaces are for tooling and models. This one is for the
 * person who wants to poke the API before writing any code, which is how most
 * integrations actually begin.
 *
 * Written to be usable rather than merely importable: the host is a
 * `{{baseUrl}}` variable so environments can be switched, auth is declared
 * once on the collection and overridden only where an endpoint is public,
 * optional query parameters arrive present but disabled so they can be toggled
 * rather than retyped, and documented responses are imported as saved
 * examples.
 */
final readonly class PostmanEmitter implements Emitter
{
    private const SCHEMA = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    public function __construct(private string $fileName = 'postman.json') {}

    public function name(): string
    {
        return 'postman';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        return [EmittedFile::json($this->fileName, $this->collection($spec))];
    }

    /**
     * @return array<string, mixed>
     */
    public function collection(ApiSpec $spec): array
    {
        $collection = [
            'info' => array_filter([
                // Deterministic: a fresh id on every build would make Postman
                // treat each import as a new collection rather than an update.
                '_postman_id' => $this->identifier($spec),
                'name' => $spec->title,
                'description' => $spec->description,
                'schema' => self::SCHEMA,
            ], static fn (mixed $value): bool => $value !== null),
            'variable' => $this->variables($spec),
            'item' => $this->folders($spec),
        ];

        if ($this->hasAuthenticatedEndpoint($spec)) {
            $collection['auth'] = [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
            ];
        }

        return $collection;
    }

    /**
     * @return list<array<string, string>>
     */
    private function variables(ApiSpec $spec): array
    {
        $variables = [[
            'key' => 'baseUrl',
            'value' => $spec->baseUrl ?? 'http://localhost',
            'type' => 'string',
        ]];

        if ($this->hasAuthenticatedEndpoint($spec)) {
            // Left empty on purpose: a collection that ships with a filled-in
            // token is a collection someone eventually commits.
            $variables[] = ['key' => 'token', 'value' => '', 'type' => 'string'];
        }

        return $variables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function folders(ApiSpec $spec): array
    {
        $folders = [];

        foreach ($spec->groups as $group) {
            $folders[] = array_filter([
                // Folders are a flat list in Postman's sidebar, so two
                // versions of one resource have to be told apart there.
                'name' => $group->displayName(),
                'description' => $group->description,
                'item' => array_map(
                    fn (Endpoint $endpoint): array => $this->request($endpoint, $spec),
                    $group->endpoints,
                ),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $folders;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(Endpoint $endpoint, ApiSpec $spec): array
    {
        $request = array_filter([
            'method' => $endpoint->method->value,
            'header' => $this->headers($endpoint),
            'url' => $this->url($endpoint),
            'description' => $this->description($endpoint),
            'body' => $this->body($endpoint),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        // Auth is declared once on the collection; only the exceptions need
        // to say anything.
        if (! $endpoint->authenticated && $this->hasAuthenticatedEndpoint($spec)) {
            $request['auth'] = ['type' => 'noauth'];
        }

        return array_filter([
            'name' => $endpoint->title(),
            'request' => $request,
            'response' => $this->examples($endpoint, $spec),
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @return list<array<string, string>>
     */
    private function headers(Endpoint $endpoint): array
    {
        $headers = [['key' => 'Accept', 'value' => 'application/json']];

        if ($endpoint->hasBody()) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        }

        foreach ($endpoint->parametersIn(ParameterLocation::Header) as $parameter) {
            $headers[] = array_filter([
                'key' => $parameter->name,
                'value' => $this->scalar(Examples::forParameter($parameter)),
                'description' => $parameter->description,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $headers;
    }

    /**
     * Postman wants the URL both raw and taken apart, and uses `:name` for
     * path variables where Laravel uses `{name}`.
     *
     * @return array<string, mixed>
     */
    private function url(Endpoint $endpoint): array
    {
        $path = [];

        foreach (explode('/', trim($endpoint->uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $path[] = preg_match('/^\{(\w+)\??\}$/', $segment, $match) === 1
                ? ':'.$match[1]
                : $segment;
        }

        $query = $this->query($endpoint);
        $enabled = array_values(array_filter($query, static fn (array $item): bool => ! ($item['disabled'] ?? false)));

        $raw = '{{baseUrl}}/'.implode('/', $path);

        if ($enabled !== []) {
            $pairs = [];

            foreach ($enabled as $item) {
                $key = is_string($item['key'] ?? null) ? $item['key'] : '';
                $value = is_string($item['value'] ?? null) ? $item['value'] : '';

                if ($key !== '') {
                    $pairs[] = $key.'='.rawurlencode($value);
                }
            }

            $raw .= $pairs === [] ? '' : '?'.implode('&', $pairs);
        }

        return array_filter([
            'raw' => $raw,
            'host' => ['{{baseUrl}}'],
            'path' => $path,
            'query' => $query,
            'variable' => $this->pathVariables($endpoint),
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * Optional parameters are included but disabled, so they can be switched
     * on rather than looked up and retyped.
     *
     * @return list<array<string, mixed>>
     */
    private function query(Endpoint $endpoint): array
    {
        $query = [];

        foreach ($endpoint->parametersIn(ParameterLocation::Query) as $parameter) {
            $query[] = array_filter([
                'key' => $parameter->name,
                'value' => $this->scalar(Examples::forParameter($parameter)),
                'description' => $this->parameterDescription($parameter),
                'disabled' => $parameter->required ? null : true,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    private function pathVariables(Endpoint $endpoint): array
    {
        $variables = [];

        foreach ($endpoint->parametersIn(ParameterLocation::Path) as $parameter) {
            $variables[] = [
                'key' => $parameter->name,
                'value' => $this->scalar(Examples::forParameter($parameter)),
                'description' => $this->parameterDescription($parameter),
            ];
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Endpoint $endpoint): ?array
    {
        $parameters = $endpoint->parametersIn(ParameterLocation::Body);

        if ($parameters === []) {
            return null;
        }

        $json = json_encode(
            Examples::body($parameters),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            'mode' => 'raw',
            'raw' => $json === false ? '{}' : $json,
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    /**
     * Postman renders Markdown, so the body parameters go here as a table -
     * otherwise they exist only as keys in the example body, with no types and
     * no explanation.
     */
    private function description(Endpoint $endpoint): ?string
    {
        $parts = [];

        if ($endpoint->description !== null) {
            $parts[] = $endpoint->description;
        }

        if ($endpoint->deprecated) {
            $parts[] = '**Deprecated.**';
        }

        if ($endpoint->rateLimit !== null) {
            $parts[] = 'Rate limit: '.$endpoint->rateLimit->label().'.';
        }

        $body = $endpoint->parametersIn(ParameterLocation::Body);

        if ($body !== []) {
            $rows = ['| Field | Type | Required |', '| --- | --- | --- |'];

            foreach ($body as $parameter) {
                $rows[] = sprintf(
                    '| `%s` | %s | %s |',
                    $parameter->name,
                    str_replace('|', '\\|', $parameter->schema->label()),
                    $parameter->required ? 'yes' : 'no',
                );
            }

            $parts[] = implode("\n", $rows);
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Documented responses become saved examples, so the collection shows what
     * success and failure look like before anything has been sent.
     *
     * @return list<array<string, mixed>>
     */
    private function examples(Endpoint $endpoint, ApiSpec $spec): array
    {
        $examples = [];

        foreach ($endpoint->responses as $response) {
            $body = $this->exampleBody($response);

            if ($body === null) {
                continue;
            }

            $examples[] = [
                'name' => $response->status.' '.$response->reasonPhrase(),
                'originalRequest' => [
                    'method' => $endpoint->method->value,
                    'header' => $this->headers($endpoint),
                    'url' => $this->url($endpoint),
                ],
                'status' => $response->reasonPhrase(),
                'code' => $response->status,
                '_postman_previewlanguage' => 'json',
                'header' => [['key' => 'Content-Type', 'value' => $response->contentType]],
                'body' => $body,
            ];
        }

        return $examples;
    }

    private function exampleBody(Response $response): ?string
    {
        foreach ($response->examples as $example) {
            return $example->render();
        }

        if ($response->schema === null) {
            return null;
        }

        $json = json_encode(
            Examples::forSchema($response->schema),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $json === false ? null : $json;
    }

    private function parameterDescription(Parameter $parameter): string
    {
        $label = $parameter->schema->label();
        $description = $parameter->description;

        if ($description === null) {
            return $label;
        }

        return $description.' ('.$label.')';
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * A UUID derived from the collection's identity, so re-importing updates
     * the existing collection instead of creating a duplicate.
     */
    private function identifier(ApiSpec $spec): string
    {
        $hash = md5('lusen:'.$spec->title.':'.$spec->version);

        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function hasAuthenticatedEndpoint(ApiSpec $spec): bool
    {
        foreach ($spec->endpoints() as $endpoint) {
            if ($endpoint->authenticated) {
                return true;
            }
        }

        return false;
    }
}
