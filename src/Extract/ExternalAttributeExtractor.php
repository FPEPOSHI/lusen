<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Types\ResponseFactory;
use Lusen\Extract\Types\TypeNames;
use Lusen\Extract\Types\TypeReader;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads documentation attributes that another tool put in the code.
 *
 * A codebase documented once should not have to be documented again to change
 * tools. ControllerExtractor already honours `@ignore` and `@hideFromApiDocs`
 * for the same reason; this extends the courtesy to attributes, which are
 * often the richest thing an application has: responses by status, group names
 * in the team's own language, and parameters with descriptions and examples.
 *
 * Which namespaces to read is configuration, not something baked in here, so a
 * codebase can point this at whatever it used without waiting for Lusen to
 * learn the name. Matching on the full namespace rather than the short name is
 * deliberate: an unrelated `#[Response]` would otherwise be silently
 * misdocumented.
 *
 * Nothing is instantiated. Attributes are read through `getArguments()`, which
 * never loads the class - so this keeps working in a codebase that has already
 * removed the package the attributes came from.
 *
 * Responses go through the same decoder as Lusen's own `#[ApiResponse]`, so
 * identical declarations produce identical documentation. Runs before
 * AttributeExtractor, because Lusen's own attributes are the last word.
 */
final readonly class ExternalAttributeExtractor implements Extractor
{
    /**
     * @param  list<string>  $namespaces  attribute namespaces to read, each ending in a separator
     */
    public function __construct(
        private array $namespaces = [],
        private ResponseFactory $responses = new ResponseFactory,
        private TypeReader $types = new TypeReader,
    ) {}

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

            $response = $this->responses->make(
                status: $status,
                description: $this->string($arguments, 'description', 1),
                type: $this->string($arguments, 'type', 2),
                example: $arguments['example'] ?? null,
                names: $names,
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
            if (in_array($attribute->getName(), $this->qualified($short), true)) {
                $found[] = $this->arguments($attribute);
            }
        }

        return $found;
    }

    /**
     * Every fully-qualified name this short name could have, across the
     * configured namespaces.
     *
     * @return list<string>
     */
    private function qualified(string $short): array
    {
        return array_map(
            static fn (string $namespace): string => rtrim($namespace, '\\').'\\'.$short,
            $this->namespaces,
        );
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
