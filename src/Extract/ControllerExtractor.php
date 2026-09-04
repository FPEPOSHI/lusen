<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Ir\Endpoint;
use Lusen\Support\DocBlock;
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

        return $endpoint->with(
            summary: $this->summary($method),
            description: $this->description($method),
            group: $group === '' ? null : $group,
            authenticated: $this->authenticated($method, $class),
            deprecated: $method->hasTag('deprecated') ? true : null,
        );
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
