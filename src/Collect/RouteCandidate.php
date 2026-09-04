<?php

declare(strict_types=1);

namespace Lusen\Collect;

use Illuminate\Routing\Route;
use Lusen\Ir\Enums\HttpMethod;
use ReflectionClass;

/**
 * A route that passed collection, paired with the resolved controller target.
 *
 * This is the boundary object between Collect and Extract: it is the last
 * place an Illuminate Route is visible. Extractors receive the candidate but
 * write only into the IR, so nothing downstream depends on the framework.
 */
final readonly class RouteCandidate
{
    public function __construct(
        public Route $route,
        public HttpMethod $method,
        public string $uri,
        public ?string $name = null,
        public ?string $controller = null,
        public ?string $action = null,
        /** @var array<string, array<mixed>> */
        public array $middlewareGroups = [],
    ) {}

    /**
     * @param  array<string, array<mixed>>  $middlewareGroups  the router's groups, so
     *                                                         `api` resolves to what it holds
     */
    public static function fromRoute(Route $route, HttpMethod $method, array $middlewareGroups = []): self
    {
        [$controller, $action] = self::resolveTarget($route);

        return new self(
            route: $route,
            method: $method,
            uri: ltrim($route->uri(), '/'),
            name: $route->getName(),
            controller: $controller,
            action: $action,
            middlewareGroups: $middlewareGroups,
        );
    }

    /**
     * @return list<string>
     */
    public function middleware(): array
    {
        return RouteCollector::middlewareStrings($this->route, $this->middlewareGroups);
    }

    /**
     * Absolute path of the file declaring the controller, when there is one.
     * Feeds the incremental build cache.
     */
    public function sourceFile(): ?string
    {
        if ($this->controller === null || ! class_exists($this->controller)) {
            return null;
        }

        $file = (new ReflectionClass($this->controller))->getFileName();

        return $file === false ? null : $file;
    }

    public function isClosure(): bool
    {
        return $this->controller === null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveTarget(Route $route): array
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            // Closure route, or an invokable resolved elsewhere.
            return [null, null];
        }

        [$class, $method] = explode('@', $action, 2);

        return [$class, $method];
    }
}
