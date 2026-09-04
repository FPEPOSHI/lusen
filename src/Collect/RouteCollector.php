<?php

declare(strict_types=1);

namespace Lusen\Collect;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Lusen\Ir\Enums\HttpMethod;

/**
 * Turns the host application's route table into a filtered, deterministically
 * ordered list of candidates.
 *
 * The only stage that touches Illuminate's Router.
 */
final readonly class RouteCollector
{
    /**
     * @param  array<string, mixed>  $config  the `routes` section of config/lusen.php
     */
    public function __construct(
        private Router $router,
        private array $config = [],
    ) {}

    /**
     * @return list<RouteCandidate>
     */
    public function collect(): array
    {
        $candidates = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $this->passes($route)) {
                continue;
            }

            foreach ($this->documentableMethods($route) as $method) {
                $candidates[] = RouteCandidate::fromRoute($route, $method, $this->middlewareGroups());
            }
        }

        // Sort by URI then method so output ordering never depends on the
        // order routes happened to be registered in.
        usort($candidates, static fn (RouteCandidate $a, RouteCandidate $b): int => [$a->uri, $a->method->value] <=> [$b->uri, $b->method->value]);

        return $candidates;
    }

    /**
     * Route middleware can contain closures as well as strings; only the
     * strings are matchable.
     *
     * @return list<string>
     */
    /**
     * @param  array<string, array<mixed>>  $groups
     * @return list<string>
     */
    public static function middlewareStrings(Route $route, array $groups = []): array
    {
        /** @var array<mixed> $middleware */
        $middleware = $route->gatherMiddleware();

        return self::expand(array_values(array_filter(
            $middleware,
            static fn (mixed $m): bool => is_string($m),
        )), $groups);
    }

    /**
     * Middleware groups, resolved to what they contain.
     *
     * `gatherMiddleware()` reports middleware as it was declared, so a route
     * in Laravel's `api` group reports the string "api" and everything inside
     * it - the throttle every request is subject to, sometimes the
     * authentication too - is invisible. Documentation that misses a rate
     * limit every caller will hit is documentation that gets someone paged.
     *
     * The group name is kept alongside its contents, so a `routes.middleware`
     * filter written against the group still matches.
     *
     * @param  list<string>  $middleware
     * @param  array<string, array<mixed>>  $groups
     * @return list<string>
     */
    private static function expand(array $middleware, array $groups, int $depth = 0): array
    {
        // Groups may reference groups; a handful of levels is more than any
        // real application uses, and stops a cycle dead.
        if ($depth > 4) {
            return $middleware;
        }

        $expanded = [];

        foreach ($middleware as $item) {
            $expanded[] = $item;

            if (! isset($groups[$item])) {
                continue;
            }

            $inner = array_values(array_filter(
                $groups[$item],
                static fn (mixed $m): bool => is_string($m),
            ));

            $expanded = [...$expanded, ...self::expand($inner, $groups, $depth + 1)];
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function middlewareGroups(): array
    {
        /** @var array<string, array<mixed>> $groups */
        $groups = $this->router->getMiddlewareGroups();

        return $groups;
    }

    private function passes(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');

        if (! $this->matchesAny($uri, $this->patterns('include', ['api/*']))) {
            return false;
        }

        if ($this->matchesAny($uri, $this->patterns('exclude'))) {
            return false;
        }

        $name = $route->getName();

        if ($name !== null && $this->matchesAny($name, $this->patterns('exclude_names'))) {
            return false;
        }

        return $this->hasRequiredMiddleware($route);
    }

    private function hasRequiredMiddleware(Route $route): bool
    {
        $required = $this->patterns('middleware');

        if ($required === []) {
            return true;
        }

        $gathered = self::middlewareStrings($route);

        foreach ($required as $pattern) {
            if (! $this->matchesAny($gathered, [$pattern])) {
                return false;
            }
        }

        return true;
    }

    /**
     * HEAD is Laravel's automatic companion to GET and would double every
     * page for no reader benefit.
     *
     * @return list<HttpMethod>
     */
    private function documentableMethods(Route $route): array
    {
        $methods = [];

        foreach ($route->methods() as $method) {
            if (! is_string($method) || $method === 'HEAD') {
                continue;
            }

            $resolved = HttpMethod::tryFrom($method);

            if ($resolved !== null) {
                $methods[] = $resolved;
            }
        }

        return $methods;
    }

    /**
     * @param  string|list<string>  $subject
     * @param  list<string>  $patterns
     */
    private function matchesAny(string|array $subject, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach ((array) $subject as $value) {
            if (Str::is($patterns, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function patterns(string $key, array $default = []): array
    {
        $value = $this->config[$key] ?? $default;

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v)));
    }
}
