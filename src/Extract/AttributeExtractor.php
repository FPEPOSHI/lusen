<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Attributes\ApiDoc;
use Lusen\Attributes\ApiGroup;
use Lusen\Attributes\ApiParam;
use Lusen\Attributes\ApiResponse;
use Lusen\Attributes\Authenticated;
use Lusen\Attributes\Hidden;
use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Types\ResponseFactory;
use Lusen\Extract\Types\TypeNames;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Applies the package's PHP attributes.
 *
 * Runs last in the default pipeline because explicit annotation is the last
 * word: whatever an author wrote in an attribute overrides every inference.
 * Method-level attributes beat class-level ones.
 *
 * This is the one extractor that uses reflection rather than a parse - the
 * attributes are typed PHP objects, and reading them through reflection is
 * both cheaper and safer than reconstructing their arguments from an AST.
 */
final readonly class AttributeExtractor implements Extractor
{
    public function __construct(private ResponseFactory $responses = new ResponseFactory) {}

    public function extract(Endpoint $endpoint, RouteCandidate $candidate): ?Endpoint
    {
        $method = $this->reflectAction($candidate);

        if ($method === null) {
            return $endpoint;
        }

        $class = $method->getDeclaringClass();

        if ($this->hasHidden($class) || $this->hasHidden($method)) {
            return null;
        }

        $endpoint = $this->applyGroup($endpoint, $class);
        $endpoint = $this->applyDoc($endpoint, $class, $method);
        $endpoint = $this->applyAuthenticated($endpoint, $class, $method);
        $endpoint = $this->applyParams($endpoint, $method);

        return $this->applyResponses($endpoint, $method);
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

        if (! $class->hasMethod($candidate->action)) {
            return null;
        }

        return $class->getMethod($candidate->action);
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     */
    private function hasHidden(ReflectionClass|ReflectionMethod $target): bool
    {
        return $target->getAttributes(Hidden::class) !== [];
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function instance(ReflectionClass|ReflectionMethod $target, string $attribute): ?object
    {
        $found = $target->getAttributes($attribute);

        if ($found === []) {
            return null;
        }

        return $found[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    private function instances(ReflectionMethod $target, string $attribute): array
    {
        return array_map(
            static fn (ReflectionAttribute $a): object => $a->newInstance(),
            $target->getAttributes($attribute),
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function applyGroup(Endpoint $endpoint, ReflectionClass $class): Endpoint
    {
        $group = $this->instance($class, ApiGroup::class);

        return $group === null ? $endpoint : $endpoint->with(group: $group->name);
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function applyDoc(Endpoint $endpoint, ReflectionClass $class, ReflectionMethod $method): Endpoint
    {
        foreach ([$this->instance($class, ApiDoc::class), $this->instance($method, ApiDoc::class)] as $doc) {
            if ($doc === null) {
                continue;
            }

            $endpoint = $endpoint->with(
                summary: $doc->summary,
                description: $doc->description,
                group: $doc->group,
                authenticated: $doc->authenticated,
                deprecated: $doc->deprecated,
                tags: $doc->tags === [] ? null : $doc->tags,
                version: $doc->version,
            );
        }

        return $endpoint;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function applyAuthenticated(Endpoint $endpoint, ReflectionClass $class, ReflectionMethod $method): Endpoint
    {
        foreach ([$this->instance($class, Authenticated::class), $this->instance($method, Authenticated::class)] as $auth) {
            if ($auth !== null) {
                $endpoint = $endpoint->with(authenticated: $auth->required);
            }
        }

        return $endpoint;
    }

    private function applyParams(Endpoint $endpoint, ReflectionMethod $method): Endpoint
    {
        $declared = $this->instances($method, ApiParam::class);

        if ($declared === []) {
            return $endpoint;
        }

        $parameters = $endpoint->parameters;

        foreach ($declared as $param) {
            $built = new Parameter(
                name: $param->name,
                in: ParameterLocation::tryFrom($param->in) ?? ParameterLocation::Query,
                schema: new Schema(
                    type: SchemaType::fromHint($param->type),
                    format: $param->format,
                    nullable: $param->nullable,
                    enum: $param->enum,
                    example: $param->example,
                ),
                required: $param->required,
                description: $param->description,
            );

            // An explicit declaration replaces an inferred parameter of the
            // same name and location rather than duplicating it.
            $parameters = array_values(array_filter(
                $parameters,
                static fn (Parameter $p): bool => ! ($p->name === $built->name && $p->in === $built->in),
            ));

            $parameters[] = $built;
        }

        return $endpoint->withParameters($parameters);
    }

    private function applyResponses(Endpoint $endpoint, ReflectionMethod $method): Endpoint
    {
        $declared = $this->instances($method, ApiResponse::class);

        if ($declared === []) {
            return $endpoint;
        }

        $responses = $endpoint->responses;

        foreach ($declared as $response) {
            $responses = array_values(array_filter(
                $responses,
                static fn (Response $r): bool => $r->status !== $response->status,
            ));

            // The same decoder the external attributes go through, so two
            // ways of declaring the same response cannot document it
            // differently.
            $responses[] = $this->responses->make(
                status: $response->status,
                description: $response->description,
                type: $response->type,
                example: $response->example,
                names: TypeNames::forClass($method->getDeclaringClass()->getName()),
                contentType: $response->contentType,
            );
        }

        usort($responses, static fn (Response $a, Response $b): int => $a->status <=> $b->status);

        return $endpoint->withResponses($responses);
    }
}
