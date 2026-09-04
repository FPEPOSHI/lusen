<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Types\TypeNames;
use Lusen\Extract\Types\TypeReader;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Example;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Support\Examples;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads the annotations another documentation tool already put in the code.
 *
 * A codebase that has been documented once should not have to be documented
 * again to change tools. ControllerExtractor already honours `@ignore` and
 * `@hideFromApiDocs` for the same reason; this extends the courtesy to
 * Scramble's attributes, which are the richest thing many Laravel APIs have:
 * error responses by status, group names in the team's own language, and query
 * and path parameters with descriptions, types and examples.
 *
 * Nothing here depends on Scramble being installed. The attributes are matched
 * by name and read through `getArguments()`, which hands back the literal
 * arguments without instantiating anything - so this works whether the package
 * is present, absent, or has moved on to another major version.
 *
 * Runs late, because an annotation somebody wrote is better evidence than
 * anything inference produced, but before AttributeExtractor, because Lusen's
 * own attributes are still the last word.
 */
final readonly class ScrambleExtractor implements Extractor
{
    /**
     * Only attributes from this namespace are read. Matching on the short name
     * alone would eventually collide with an unrelated `#[Response]` and
     * silently document the wrong thing.
     */
    private const NAMESPACE = 'Dedoc\\Scramble\\Attributes\\';

    public function __construct(private TypeReader $types = new TypeReader) {}

    public function extract(Endpoint $endpoint, RouteCandidate $candidate): Endpoint
    {
        $action = $this->reflectAction($candidate);

        if ($action === null) {
            return $endpoint;
        }

        $names = TypeNames::forClass($action->getDeclaringClass()->getName());

        $endpoint = $this->applyGroup($endpoint, $action->getDeclaringClass());
        $endpoint = $this->applyResponses($endpoint, $action, $names);

        return $this->applyParameters($endpoint, $action, $names);
    }

    /**
     * `#[Group(name: 'Klienti', description: '...')]` on the controller.
     *
     * Worth reading even where a group name is already derived: the URI
     * fallback splits `/client` from `/clients` into two groups that a team
     * calling both "Klienti" never meant to have.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function applyGroup(Endpoint $endpoint, ReflectionClass $class): Endpoint
    {
        foreach ($this->attributes($class, 'Group') as $arguments) {
            $name = $this->string($arguments, 'name', 0);

            if ($name !== null) {
                return $endpoint->with(group: $name);
            }
        }

        return $endpoint;
    }

    /**
     * `#[Response(status: 422, description: '...', type: 'ApiValidationError')]`.
     *
     * The type is resolved through the same reader that handles `@response`,
     * so a named class is read as a schema rather than left as a bare label.
     */
    private function applyResponses(Endpoint $endpoint, ReflectionMethod $action, TypeNames $names): Endpoint
    {
        $responses = $endpoint->responses;
        $changed = false;

        foreach ($this->attributes($action, 'Response') as $arguments) {
            $status = $this->integer($arguments, 'status', 0);

            if ($status === null) {
                continue;
            }

            $type = $this->string($arguments, 'type', 2);
            $schema = $type === null ? null : $this->types->read($type, $names);

            $response = new Response(
                status: $status,
                description: $this->string($arguments, 'description', 1),
                schema: $schema,
                examples: $schema === null ? [] : [new Example('Example', Examples::forSchema($schema))],
            );

            $responses = $this->replaceStatus($responses, $response);
            $changed = true;
        }

        if (! $changed) {
            return $endpoint;
        }

        usort($responses, static fn (Response $a, Response $b): int => $a->status <=> $b->status);

        return $endpoint->withResponses($responses);
    }

    /**
     * `#[QueryParameter('limit', description: '...', type: 'int', example: 20)]`
     * and its path and body counterparts.
     */
    private function applyParameters(Endpoint $endpoint, ReflectionMethod $action, TypeNames $names): Endpoint
    {
        $parameters = $endpoint->parameters;
        $changed = false;

        $locations = [
            'QueryParameter' => ParameterLocation::Query,
            'PathParameter' => ParameterLocation::Path,
            'BodyParameter' => ParameterLocation::Body,
            'HeaderParameter' => ParameterLocation::Header,
        ];

        foreach ($locations as $attribute => $in) {
            foreach ($this->attributes($action, $attribute) as $arguments) {
                $name = $this->string($arguments, 'name', 0);

                if ($name === null) {
                    continue;
                }

                $parameters = $this->replaceParameter(
                    $parameters,
                    $this->parameter($name, $in, $arguments, $names),
                );

                $changed = true;
            }
        }

        return $changed ? $endpoint->withParameters($parameters) : $endpoint;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function parameter(string $name, ParameterLocation $in, array $arguments, TypeNames $names): Parameter
    {
        $type = $this->string($arguments, 'type');
        $schema = ($type === null ? null : $this->types->read($type, $names)) ?? Schema::any();

        $example = $arguments['example'] ?? null;

        if ($example !== null) {
            $schema = $schema->withExample($example);
        }

        return new Parameter(
            name: $name,
            in: $in,
            schema: $schema,
            // A path parameter is always required, whatever the attribute says.
            required: $in === ParameterLocation::Path
                || ($arguments['required'] ?? false) === true,
            description: $this->describe($arguments),
        );
    }

    /**
     * The description, with the default appended: "which page" and "starts at
     * 1 if you leave it out" are both things a caller needs, and the schema
     * has nowhere to keep the second.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function describe(array $arguments): ?string
    {
        $description = $this->string($arguments, 'description', 1);
        $default = $arguments['default'] ?? null;

        $written = match (true) {
            is_bool($default) => $default ? 'true' : 'false',
            is_string($default) => $default,
            is_int($default), is_float($default) => (string) $default,
            default => null,
        };

        if ($written === null) {
            return $description;
        }

        $sentence = "Defaults to `{$written}`.";

        return $description === null ? $sentence : rtrim($description).' '.$sentence;
    }

    /**
     * @param  list<Response>  $responses
     * @return list<Response>
     */
    private function replaceStatus(array $responses, Response $response): array
    {
        foreach ($responses as $index => $existing) {
            if ($existing->status === $response->status) {
                $responses[$index] = $response;

                return $responses;
            }
        }

        $responses[] = $response;

        return $responses;
    }

    /**
     * @param  list<Parameter>  $parameters
     * @return list<Parameter>
     */
    private function replaceParameter(array $parameters, Parameter $parameter): array
    {
        foreach ($parameters as $index => $existing) {
            if ($existing->name === $parameter->name && $existing->in === $parameter->in) {
                $parameters[$index] = $parameter;

                return $parameters;
            }
        }

        $parameters[] = $parameter;

        return $parameters;
    }

    /**
     * The arguments of every attribute of this name, without constructing it.
     *
     * @param  ReflectionClass<object>|ReflectionMethod  $target
     * @return list<array<array-key, mixed>>
     */
    private function attributes(ReflectionClass|ReflectionMethod $target, string $short): array
    {
        $found = [];

        foreach ($target->getAttributes() as $attribute) {
            if ($attribute->getName() === self::NAMESPACE.$short) {
                $found[] = $this->arguments($attribute);
            }
        }

        return $found;
    }

    /**
     * @param  ReflectionAttribute<object>  $attribute
     * @return array<array-key, mixed>
     */
    private function arguments(ReflectionAttribute $attribute): array
    {
        // An argument that is not a constant expression throws rather than
        // resolving, and a docs build must survive that.
        try {
            return $attribute->getArguments();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function string(array $arguments, string $named, ?int $position = null): ?string
    {
        $value = $arguments[$named] ?? ($position === null ? null : ($arguments[$position] ?? null));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function integer(array $arguments, string $named, ?int $position = null): ?int
    {
        $value = $arguments[$named] ?? ($position === null ? null : ($arguments[$position] ?? null));

        return is_int($value) ? $value : null;
    }

    private function reflectAction(RouteCandidate $candidate): ?ReflectionMethod
    {
        if ($candidate->controller === null
            || $candidate->action === null
            || ! class_exists($candidate->controller)) {
            return null;
        }

        $class = new ReflectionClass($candidate->controller);

        return $class->hasMethod($candidate->action) ? $class->getMethod($candidate->action) : null;
    }
}
