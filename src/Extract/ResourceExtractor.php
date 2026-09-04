<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Resources\ResourceReader;
use Lusen\Extract\Resources\ResourceReturn;
use Lusen\Extract\Resources\ReturnAnalyzer;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Example;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use Lusen\Support\Examples;
use ReflectionClass;
use ReflectionMethod;

/**
 * Documents the success response from the API resource an action returns.
 *
 * Responses are the half of an endpoint that inference usually cannot reach,
 * so most generated documentation shows a request in full detail and then
 * says nothing about what comes back. A JsonResource's `toArray()` is a
 * precise statement of the response shape, sitting in the codebase already.
 *
 * Runs after FormRequestExtractor and before AttributeExtractor, so an
 * explicit #[ApiResponse] still wins.
 */
final readonly class ResourceExtractor implements Extractor
{
    public function extract(Endpoint $endpoint, RouteCandidate $candidate): Endpoint
    {
        $action = $this->reflectAction($candidate);

        if ($action === null) {
            return $endpoint;
        }

        $return = ReturnAnalyzer::analyse($action);

        if ($return->isEmpty()) {
            return $endpoint;
        }

        $status = $return->status ?? $this->successStatus($endpoint);

        // An explicitly documented status is left alone; this only fills gaps.
        if ($this->hasResponse($endpoint, $status)) {
            return $endpoint;
        }

        $schema = $this->schemaFor($return);

        $response = new Response(
            status: $status,
            schema: $schema,
            examples: $schema === null ? [] : [
                new Example('Example', Examples::forSchema($schema)),
            ],
        );

        $responses = [...$endpoint->responses, $response];

        usort($responses, static fn (Response $a, Response $b): int => $a->status <=> $b->status);

        return $endpoint
            ->withResponses($responses)
            ->with(sourceFiles: $this->sourceFiles($endpoint, $return));
    }

    private function schemaFor(ResourceReturn $return): ?Schema
    {
        if ($return->literal !== null) {
            return $return->literal;
        }

        if ($return->resource === null) {
            return null;
        }

        $shape = ResourceReader::read($return->resource);

        if ($shape === null) {
            return null;
        }

        $body = $return->collection ? Schema::arrayOf($shape) : $shape;

        if (! ResourceReader::wrapsResponses($return->resource)) {
            return $body;
        }

        // Laravel wraps resource responses in `data` unless the resource opts
        // out with `public static $wrap = null`.
        $properties = ['data' => $body];

        if ($return->paginated) {
            $properties['links'] = $this->paginationLinks();
            $properties['meta'] = $this->paginationMeta();
        }

        return Schema::object($properties, required: array_keys($properties));
    }

    private function paginationLinks(): Schema
    {
        return Schema::object([
            'first' => new Schema(format: 'uri', nullable: true),
            'last' => new Schema(format: 'uri', nullable: true),
            'prev' => new Schema(format: 'uri', nullable: true),
            'next' => new Schema(format: 'uri', nullable: true),
        ]);
    }

    private function paginationMeta(): Schema
    {
        return Schema::object([
            'current_page' => Schema::integer(),
            'from' => new Schema(type: SchemaType::Integer, nullable: true),
            'last_page' => Schema::integer(),
            'per_page' => Schema::integer(),
            'to' => new Schema(type: SchemaType::Integer, nullable: true),
            'total' => Schema::integer(),
        ]);
    }

    /**
     * The conventional success status for the verb, when the action did not
     * state one itself.
     */
    private function successStatus(Endpoint $endpoint): int
    {
        return match ($endpoint->method) {
            HttpMethod::Post => 201,
            HttpMethod::Delete => 204,
            default => 200,
        };
    }

    private function hasResponse(Endpoint $endpoint, int $status): bool
    {
        foreach ($endpoint->responses as $response) {
            if ($response->status === $status || $response->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(Endpoint $endpoint, ResourceReturn $return): array
    {
        if ($return->resource === null) {
            return $endpoint->sourceFiles;
        }

        $file = Ast::fileFor($return->resource);

        return $file === null
            ? $endpoint->sourceFiles
            : array_values(array_unique([...$endpoint->sourceFiles, $file]));
    }

    private function reflectAction(RouteCandidate $candidate): ?ReflectionMethod
    {
        if ($candidate->controller === null || $candidate->action === null) {
            return null;
        }

        if (! class_exists($candidate->controller)) {
            return null;
        }

        $class = new ReflectionClass($candidate->controller);

        return $class->hasMethod($candidate->action)
            ? $class->getMethod($candidate->action)
            : null;
    }
}
