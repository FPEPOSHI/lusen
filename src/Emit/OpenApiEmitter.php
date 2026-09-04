<?php

declare(strict_types=1);

namespace Lusen\Emit;

use Lusen\Emit\Contracts\Emitter;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Group;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;
use Lusen\Support\Str;
use stdClass;

/**
 * OpenAPI 3.1 document.
 *
 * This is the surface most machine consumers reach for first, so it is worth
 * getting exactly right: 3.1 rather than 3.0 because its schemas are real
 * JSON Schema, which means `nullable` becomes a type union and generated
 * clients stop guessing.
 */
final readonly class OpenApiEmitter implements Emitter
{
    public function __construct(private string $fileName = 'openapi.json') {}

    public function name(): string
    {
        return 'openapi';
    }

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array
    {
        return [EmittedFile::json($this->fileName, $this->document($spec))];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(ApiSpec $spec): array
    {
        $document = [
            'openapi' => '3.1.0',
            'info' => array_filter([
                'title' => $spec->title,
                'version' => $spec->version,
                'description' => $spec->description,
            ], static fn (mixed $v): bool => $v !== null),
            'servers' => $this->servers($spec),
            'tags' => $this->tags($spec),
            'paths' => $this->paths($spec),
        ];

        $schemes = $this->securitySchemes($spec);

        if ($schemes !== []) {
            $document['components'] = ['securitySchemes' => $schemes];
        }

        return $document;
    }

    /**
     * @return list<array<string, string>>
     */
    private function servers(ApiSpec $spec): array
    {
        $servers = [];

        if ($spec->baseUrl !== null) {
            $servers[] = ['url' => $spec->baseUrl];
        }

        foreach ($spec->servers as $label => $url) {
            $servers[] = ['url' => $url, 'description' => $label];
        }

        return $servers;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(ApiSpec $spec): array
    {
        return array_map(
            static fn (Group $g): array => array_filter([
                // The version has to be in the tag. A generated client that
                // saw two `Users` tags would fold v1 and v2 into one class
                // and produce methods that collide.
                'name' => $g->displayName(),
                'description' => $g->description,
            ], static fn (mixed $v): bool => $v !== null),
            $spec->groups,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(ApiSpec $spec): array
    {
        $paths = [];

        foreach ($spec->groups as $group) {
            foreach ($group->endpoints as $endpoint) {
                $path = $this->templatePath($endpoint);
                $paths[$path][strtolower($endpoint->method->value)] = $this->operation($endpoint, $group);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * Laravel's optional-parameter syntax `{user?}` is not valid OpenAPI, and
     * an optional path segment has no 3.1 representation at all - the closest
     * honest thing is a required parameter on the concrete path.
     */
    private function templatePath(Endpoint $endpoint): string
    {
        return str_replace('?}', '}', $endpoint->path());
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Endpoint $endpoint, Group $group): array
    {
        $operation = array_filter([
            'operationId' => $endpoint->id,
            'summary' => $endpoint->summary,
            'description' => $endpoint->description,
            'tags' => $endpoint->group === null ? null : [$group->displayName()],
            'deprecated' => $endpoint->deprecated ?: null,
            'parameters' => $this->parameters($endpoint) ?: null,
            'requestBody' => $this->requestBody($endpoint),
            'responses' => $this->responses($endpoint),
        ], static fn (mixed $v): bool => $v !== null);

        $scheme = $endpoint->securityScheme();

        if ($scheme !== null) {
            $requirement = [];

            // Several schemes inside one requirement object means all of them,
            // which is exactly what a client id plus a client secret is.
            foreach (array_keys($scheme->schemes()) as $key) {
                $requirement[$key] = $scheme->type === SecurityScheme::OAUTH2 ? $scheme->scopes : [];
            }

            $operation['security'] = [$requirement];
        }

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(Endpoint $endpoint): array
    {
        $parameters = [];

        foreach ($endpoint->parameters as $parameter) {
            if (! $parameter->in->isOpenApiParameter()) {
                continue;
            }

            $parameters[] = array_filter([
                'name' => $parameter->name,
                'in' => $parameter->in->value,
                'required' => $parameter->in === ParameterLocation::Path ? true : ($parameter->required ?: null),
                'description' => $parameter->description,
                'deprecated' => $parameter->deprecated ?: null,
                'schema' => $this->schemaValue($parameter->schema),
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(Endpoint $endpoint): ?array
    {
        $body = $endpoint->parametersIn(ParameterLocation::Body);

        if ($body === []) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($body as $parameter) {
            $properties[$parameter->name] = $this->schemaValue($parameter->schema, $parameter->description);

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $schema = array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?: null,
        ], static fn (mixed $v): bool => $v !== null);

        return [
            'required' => $required !== [],
            'content' => [
                // A body carrying a file is multipart; describing it as JSON
                // gives a client something that cannot be sent.
                $endpoint->requestContentType() => ['schema' => $schema],
            ],
        ];
    }

    /**
     * PHP casts a numeric string key to an int, so the keys here are ints at
     * runtime. json_encode still renders them as the object keys OpenAPI
     * wants, so the shape is correct - only the type is surprising.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function responses(Endpoint $endpoint): array
    {
        if ($endpoint->responses === []) {
            // A missing responses object is invalid OpenAPI. Emit the honest
            // minimum rather than inventing a schema.
            return ['200' => ['description' => 'OK']];
        }

        $responses = [];

        foreach ($endpoint->responses as $response) {
            $responses[(string) $response->status] = $this->response($response);
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function response(Response $response): array
    {
        $body = array_filter([
            'schema' => $response->schema === null ? null : $this->schemaValue($response->schema),
            'examples' => $this->examples($response),
        ], static fn (mixed $v): bool => $v !== null);

        return array_filter([
            'description' => $response->label(),
            'headers' => $response->headers === [] ? null : array_map(
                fn (Schema $header): array => array_filter([
                    'description' => $header->description,
                    'schema' => $this->schemaValue(new Schema(
                        type: $header->type,
                        format: $header->format,
                        enum: $header->enum,
                    )),
                ], static fn (mixed $v): bool => $v !== null),
                $response->headers,
            ),
            'content' => $body === [] ? null : [$response->contentType => $body],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function examples(Response $response): ?array
    {
        if ($response->examples === []) {
            return null;
        }

        $examples = [];

        foreach ($response->examples as $index => $example) {
            $key = Str::slug($example->label) ?: 'example-'.$index;

            $examples[$key] = array_filter([
                'summary' => $example->label,
                'description' => $example->description,
                'value' => $example->value,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $examples;
    }

    /**
     * A schema as it should appear in the document.
     *
     * An untyped schema has no keywords at all, and PHP encodes an empty array
     * as `[]`, which is not a valid schema. JSON Schema spells "any" as `{}`,
     * so an empty one becomes an object.
     *
     * @return array<string, mixed>|stdClass
     */
    private function schemaValue(Schema $schema, ?string $description = null): array|stdClass
    {
        $output = $this->schema($schema, $description);

        return $output === [] ? new stdClass : $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(Schema $schema, ?string $description = null): array
    {
        // An unknown type is expressed by omitting `type`, which is what JSON
        // Schema means by "any". Emitting a guess would be worse than silence.
        $type = match (true) {
            $schema->type === SchemaType::Any => null,
            $schema->nullable => [$schema->type->value, 'null'],
            default => $schema->type->value,
        };

        $output = array_filter([
            'type' => $type,
            'format' => $schema->format,
            'description' => $description ?? $schema->description,
            'enum' => $schema->enum ?: null,
            'example' => $schema->example,
        ], static fn (mixed $v): bool => $v !== null);

        if ($schema->type === SchemaType::Array && $schema->items !== null) {
            $output['items'] = $this->schemaValue($schema->items);
        }

        if ($schema->type === SchemaType::Object && $schema->properties !== []) {
            $output['properties'] = array_map(
                fn (Schema $s): array|stdClass => $this->schemaValue($s),
                $schema->properties,
            );

            if ($schema->required !== []) {
                $output['required'] = $schema->required;
            }
        }

        foreach ($schema->constraints as $key => $value) {
            $output[$this->constraintKey($key)] = $value;
        }

        return $output;
    }

    /**
     * Lusen's constraint names are reader-facing; OpenAPI wants JSON Schema's.
     */
    private function constraintKey(string $key): string
    {
        return match ($key) {
            'min' => 'minimum',
            'max' => 'maximum',
            default => $key,
        };
    }

    /**
     * One entry per distinct scheme actually used, so an app mixing bearer
     * tokens and basic auth documents both rather than being flattened to
     * whichever the emitter assumed.
     *
     * @return array<string, array<string, mixed>>
     */
    private function securitySchemes(ApiSpec $spec): array
    {
        $schemes = [];

        foreach ($spec->endpoints() as $endpoint) {
            $scheme = $endpoint->securityScheme();

            if ($scheme === null) {
                continue;
            }

            if ($scheme->type === SecurityScheme::API_KEY) {
                foreach ($scheme->schemes() as $key => $header) {
                    $schemes[$key] = ['type' => 'apiKey', 'in' => 'header', 'name' => $header];
                }

                continue;
            }

            $schemes[$scheme->name()] = match ($scheme->type) {
                SecurityScheme::BASIC => ['type' => 'http', 'scheme' => 'basic'],
                SecurityScheme::OAUTH2 => [
                    'type' => 'oauth2',
                    'flows' => ['clientCredentials' => [
                        'tokenUrl' => rtrim($spec->baseUrl ?? '', '/').'/oauth/token',
                        'scopes' => $this->allScopes($spec),
                    ]],
                ],
                default => ['type' => 'http', 'scheme' => 'bearer'],
            };
        }

        return $schemes;
    }

    /**
     * @return array<string, string>
     */
    private function allScopes(ApiSpec $spec): array
    {
        $scopes = [];

        foreach ($spec->endpoints() as $endpoint) {
            $scheme = $endpoint->securityScheme();

            if ($scheme === null) {
                continue;
            }

            foreach ($scheme->scopes as $scope) {
                $scopes[$scope] = $scope;
            }
        }

        return $scopes;
    }
}
