<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Rules\FormRequestReader;
use Lusen\Extract\Rules\RuleTree;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Documents an endpoint's input from the FormRequest its action type-hints.
 *
 * This is where most of a generated spec's detail comes from: validation
 * rules are the one place a Laravel application already states, precisely,
 * what it accepts. Reading them means a typical app documents its request
 * bodies without anyone writing an annotation.
 *
 * Runs before AttributeExtractor, so an explicit #[ApiParam] still wins.
 */
final readonly class FormRequestExtractor implements Extractor
{
    public function extract(Endpoint $endpoint, RouteCandidate $candidate): Endpoint
    {
        $request = $this->findRequestClass($candidate);

        if ($request === null) {
            return $endpoint;
        }

        $file = $this->fileFor($request);

        if ($file === null) {
            return $endpoint;
        }

        $rules = FormRequestReader::read($file, $request);

        if ($rules === []) {
            return $endpoint;
        }

        $location = $endpoint->method->hasBody()
            ? ParameterLocation::Body
            : ParameterLocation::Query;

        return $endpoint
            ->withParameters($this->merge($endpoint->parameters, RuleTree::toParameters($rules, $location)))
            ->with(sourceFiles: array_values(array_unique([...$endpoint->sourceFiles, $file])));
    }

    /**
     * A rule whose name matches an existing path parameter describes that
     * parameter rather than a new body field - `{user}` validated by
     * `'user' => 'exists:users,id'` is one parameter, not two.
     *
     * @param  list<Parameter>  $existing
     * @param  list<Parameter>  $derived
     * @return list<Parameter>
     */
    private function merge(array $existing, array $derived): array
    {
        $merged = $existing;

        foreach ($derived as $parameter) {
            $pathIndex = $this->indexOf($merged, $parameter->name, ParameterLocation::Path);

            if ($pathIndex !== null) {
                $merged[$pathIndex] = $this->enrichPathParameter($merged[$pathIndex], $parameter);

                continue;
            }

            $sameIndex = $this->indexOf($merged, $parameter->name, $parameter->in);

            if ($sameIndex !== null) {
                $merged[$sameIndex] = $parameter;

                continue;
            }

            $merged[] = $parameter;
        }

        return array_values($merged);
    }

    /**
     * Keeps the route's own knowledge - a path parameter is always required -
     * while taking the richer schema the rules describe.
     */
    private function enrichPathParameter(Parameter $path, Parameter $derived): Parameter
    {
        return new Parameter(
            name: $path->name,
            in: ParameterLocation::Path,
            schema: $this->preferSpecific($path->schema, $derived->schema),
            required: true,
            description: $path->description ?? $derived->description,
        );
    }

    /**
     * The route's `whereNumber` beats a rule set that never mentioned a type.
     */
    private function preferSpecific(Schema $fromRoute, Schema $fromRules): Schema
    {
        if ($fromRules->format !== null || $fromRules->enum !== [] || $fromRules->constraints !== []) {
            return $fromRules;
        }

        return $fromRoute;
    }

    /**
     * @param  array<int, Parameter>  $parameters
     */
    private function indexOf(array $parameters, string $name, ParameterLocation $in): ?int
    {
        foreach ($parameters as $index => $parameter) {
            if ($parameter->name === $name && $parameter->in === $in) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The first action parameter whose type declares a `rules()` method.
     *
     * Reflection reads the signature without constructing anything, so this
     * stays within the no-side-effects rule.
     *
     * @return class-string|null
     */
    private function findRequestClass(RouteCandidate $candidate): ?string
    {
        $action = $this->reflectAction($candidate);

        if ($action === null) {
            return null;
        }

        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (class_exists($name) && method_exists($name, 'rules')) {
                /** @var class-string $name */
                return $name;
            }
        }

        return null;
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

    /**
     * @param  class-string  $class
     */
    private function fileFor(string $class): ?string
    {
        $file = (new ReflectionClass($class))->getFileName();

        return $file === false ? null : $file;
    }
}
