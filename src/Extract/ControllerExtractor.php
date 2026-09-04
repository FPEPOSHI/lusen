<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Extract\Types\TypeNames;
use Lusen\Extract\Types\TypeReader;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Example;
use Lusen\Ir\Response;
use Lusen\Support\DocBlock;
use Lusen\Support\Examples;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reads the controller's own documentation.
 *
 * Most codebases already describe their actions in PHPDoc, for the sake of
 * whoever reads the code next. Lifting that into the API reference means a
 * team gets prose without writing it twice, and without adopting an annotation
 * dialect they had no reason to learn.
 *
 * Runs after RouteExtractor so it overrides the derived summary, and before
 * AttributeExtractor so an explicit #[ApiDoc] still wins.
 *
 * It also reads `@response`, which is how an API that answers with plain
 * arrays says what it returns. Reading it here rather than in
 * ResourceExtractor gets the precedence right for free: this runs first, and
 * ResourceExtractor only fills gaps, so a shape somebody wrote by hand beats
 * one inferred from `toArray()`.
 */
final readonly class ControllerExtractor implements Extractor
{
    /**
     * The docblocks Laravel's own scaffolding writes.
     *
     * They are identical in every controller in every project, so lifting them
     * would give a hundred endpoints the same summary and, worse, the same
     * meta description - which is exactly the duplicate content that makes a
     * documentation site rank for nothing. The derived summary is better.
     *
     * @var list<string>
     */
    private const BOILERPLATE = [
        'display a listing of the resource',
        'show the form for creating a new resource',
        'store a newly created resource in storage',
        'display the specified resource',
        'show the form for editing the specified resource',
        'update the specified resource in storage',
        'remove the specified resource from storage',
        'handle the incoming request',
        'create a new controller instance',
    ];

    public function __construct(private TypeReader $types = new TypeReader) {}

    public function extract(Endpoint $endpoint, RouteCandidate $candidate): ?Endpoint
    {
        $action = $this->reflectAction($candidate);

        if ($action === null) {
            return $endpoint;
        }

        $class = DocBlock::parse($action->getDeclaringClass()->getDocComment());
        $method = DocBlock::parse($action->getDocComment());

        if ($this->isHidden($class) || $this->isHidden($method)) {
            return null;
        }

        $group = $method->tag('group') ?? $class->tag('group');

        $endpoint = $endpoint->with(
            summary: $this->summary($method),
            description: $this->description($method),
            group: $group === '' ? null : $group,
            authenticated: $this->authenticated($method, $class),
            deprecated: $method->hasTag('deprecated') ? true : null,
        );

        return $this->describeResponses($endpoint, $method, $action);
    }

    /**
     * `@response array{status: bool, data: Invoice}`, optionally with a status
     * code in front of it.
     *
     * This is the only response an application with no API resources can
     * offer, and it is a better one: a shape written by hand states what the
     * endpoint promises, where a parsed `toArray()` only reports what today's
     * code happens to build.
     */
    private function describeResponses(Endpoint $endpoint, DocBlock $method, ReflectionMethod $action): Endpoint
    {
        $written = $method->tagValues('response');

        if ($written === []) {
            return $endpoint;
        }

        $names = TypeNames::forClass($action->getDeclaringClass()->getName());
        $responses = $endpoint->responses;
        $added = false;

        foreach ($written as $value) {
            [$status, $expression] = $this->statusAndType($value, $endpoint);

            if ($expression === '' || $this->hasStatus($responses, $status)) {
                continue;
            }

            $schema = $this->types->read($expression, $names);

            if ($schema === null) {
                continue;
            }

            $responses[] = new Response(
                status: $status,
                schema: $schema,
                examples: [new Example('Example', Examples::forSchema($schema))],
            );

            $added = true;
        }

        if (! $added) {
            return $endpoint;
        }

        usort($responses, static fn (Response $a, Response $b): int => $a->status <=> $b->status);

        return $endpoint->withResponses($responses);
    }

    /**
     * @return array{int, string}
     */
    private function statusAndType(string $value, Endpoint $endpoint): array
    {
        $value = trim($value);

        if (preg_match('/^(\d{3})\s+(.*)$/s', $value, $match) === 1) {
            return [(int) $match[1], trim($match[2])];
        }

        // Never 204 for a documented body: a shape says there is one, whatever
        // the verb would otherwise imply.
        return [$endpoint->method === HttpMethod::Post ? 201 : 200, $value];
    }

    /**
     * @param  list<Response>  $responses
     */
    private function hasStatus(array $responses, int $status): bool
    {
        foreach ($responses as $response) {
            if ($response->status === $status) {
                return true;
            }
        }

        return false;
    }

    private function summary(DocBlock $doc): ?string
    {
        if ($doc->summary === '' || $this->isBoilerplate($doc->summary)) {
            return null;
        }

        return $doc->summary;
    }

    private function description(DocBlock $doc): ?string
    {
        if ($doc->description === '') {
            return null;
        }

        // A boilerplate summary with a real description below it still has
        // something worth keeping.
        return $doc->description;
    }

    /**
     * `@authenticated` and `@unauthenticated` both override what the
     * middleware suggested; anything else leaves that inference alone.
     */
    private function authenticated(DocBlock $method, DocBlock $class): ?bool
    {
        foreach ([$method, $class] as $doc) {
            if ($doc->hasTag('unauthenticated')) {
                return false;
            }

            if ($doc->hasTag('authenticated')) {
                return true;
            }
        }

        return null;
    }

    /**
     * The tags other documentation tools use for "leave this out", so a
     * codebase migrating to Lusen keeps its existing exclusions.
     */
    private function isHidden(DocBlock $doc): bool
    {
        foreach (['ignore', 'hidefromapidocs', 'internal', 'lusenignore'] as $tag) {
            if ($doc->hasTag($tag)) {
                return true;
            }
        }

        return false;
    }

    private function isBoilerplate(string $summary): bool
    {
        return in_array(strtolower(rtrim($summary, '.')), self::BOILERPLATE, true);
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
