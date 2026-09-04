<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Illuminate\Support\Str;
use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;
use Lusen\Ir\RateLimit;
use Lusen\Ir\Schema;
use Lusen\Ir\SecurityScheme;
use Lusen\Support\Str as LusenStr;
use Lusen\Support\Versions;

/**
 * Everything derivable from the route itself: path parameters, auth from
 * middleware, and a fallback group and summary.
 *
 * Runs first, so later extractors always have a complete, if plain, endpoint
 * to refine.
 */
final readonly class RouteExtractor implements Extractor
{
    /**
     * Middleware that mean "this endpoint needs credentials".
     *
     * @var list<string>
     */
    private const AUTH_MIDDLEWARE = [
        'auth', 'auth:*', 'auth.basic', 'auth.session',
        'authenticate', 'verified', 'scopes', 'scopes:*', 'scope', 'scope:*',
        'client', 'client:*', 'abilities', 'abilities:*', 'ability', 'ability:*',
    ];

    /**
     * @param  array<string, mixed>  $auth  the `auth` section of config/lusen.php,
     *                                      used when middleware cannot say which
     *                                      scheme an endpoint expects
     * @param  array<string, mixed>  $versions  the `versions` section of config/lusen.php
     */
    public function __construct(private array $auth = [], private array $versions = []) {}

    public function extract(Endpoint $endpoint, RouteCandidate $candidate): Endpoint
    {
        $sourceFile = $candidate->sourceFile();

        $group = $endpoint->group ?? $this->fallbackGroup($candidate);

        return $endpoint->with(
            summary: $endpoint->summary ?? $this->fallbackSummary($candidate, $group),
            group: $group,
            parameters: array_merge($endpoint->parameters, $this->pathParameters($candidate)),
            authenticated: $this->isAuthenticated($candidate),
            sourceFiles: $sourceFile === null ? [] : [$sourceFile],
            rateLimit: $this->rateLimit($candidate),
            security: $this->security($candidate),
            version: $this->version($candidate),
        );
    }

    /**
     * Laravel's resource-controller vocabulary, which is close to universal in
     * Laravel APIs and carries real meaning about what the action does.
     */
    public function resourceActionSummary(string $action, string $group): ?string
    {
        $plural = strtolower($group);
        $singular = LusenStr::singular($plural);
        $article = LusenStr::article($singular);

        return match (strtolower($action)) {
            'index' => 'List '.$plural,
            'store' => "Create {$article} {$singular}",
            'show' => "Retrieve {$article} {$singular}",
            'update' => "Update {$article} {$singular}",
            'destroy', 'delete' => "Delete {$article} {$singular}",
            default => null,
        };
    }

    /**
     * The API version this route serves.
     *
     * The URI first, because that is where a request actually carries it. A
     * route name is the fallback that makes a header-versioned API — one whose
     * paths look identical and whose routes are still registered as `v2.*` —
     * documented as the two versions it is.
     */
    private function version(RouteCandidate $candidate): ?string
    {
        if (($this->versions['enabled'] ?? true) === false) {
            return null;
        }

        return Versions::fromUri($candidate->uri) ?? Versions::fromRouteName($candidate->name);
    }

    /**
     * Path segments wrapped in braces, with `{param?}` marked optional.
     *
     * @return list<Parameter>
     */
    private function pathParameters(RouteCandidate $candidate): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $candidate->uri, $matches, PREG_SET_ORDER);

        $parameters = [];

        foreach ($matches as $match) {
            $name = $match[1];
            $optional = ($match[2] ?? '') === '?';

            $parameters[] = new Parameter(
                name: $name,
                in: ParameterLocation::Path,
                schema: $this->pathParameterSchema($candidate, $name),
                required: ! $optional,
                description: null,
            );
        }

        return $parameters;
    }

    /**
     * Uses the route's own regex constraint when it has one - `whereNumber`
     * is a strong signal the segment is an integer id.
     */
    private function pathParameterSchema(RouteCandidate $candidate, string $name): Schema
    {
        /** @var array<string, string> $wheres */
        $wheres = $candidate->route->wheres;

        $pattern = $wheres[$name] ?? null;

        if ($pattern !== null && in_array($pattern, ['[0-9]+', '\d+', '[1-9][0-9]*'], true)) {
            return Schema::integer();
        }

        if ($pattern !== null) {
            return new Schema(constraints: ['pattern' => $pattern]);
        }

        // Conventional Laravel resource ids.
        if ($name === 'id' || str_ends_with($name, '_id')) {
            return Schema::integer();
        }

        // `/users/{user}` is route model binding, which resolves on the
        // primary key unless the route says otherwise. Documenting it as a
        // string produces the useless example /api/users/user.
        if ($this->bindsResourceKey($candidate->uri, $name)) {
            return Schema::integer();
        }

        return Schema::string();
    }

    /**
     * True when the placeholder directly follows the plural of its own name,
     * the shape Laravel's resource routes generate.
     */
    private function bindsResourceKey(string $uri, string $name): bool
    {
        $segments = explode('/', trim($uri, '/'));

        foreach ($segments as $index => $segment) {
            if ($segment !== '{'.$name.'}' && $segment !== '{'.$name.'?}') {
                continue;
            }

            $previous = $segments[$index - 1] ?? null;

            if ($previous === null) {
                return false;
            }

            // users/{user}, categories/{category}, boxes/{box}
            return in_array($previous, [$name.'s', $name.'es', substr($name, 0, -1).'ies'], true);
        }

        return false;
    }

    /**
     * "GET api/user-profiles" -> group "User Profiles": the first URI segment
     * after the API prefix is nearly always the resource name.
     */
    private function fallbackGroup(RouteCandidate $candidate): ?string
    {
        $segments = array_values(array_filter(
            explode('/', $candidate->uri),
            static fn (string $s): bool => $s !== '' && ! str_starts_with($s, '{'),
        ));

        if ($segments === []) {
            return null;
        }

        // Skip the api prefix and the version, whichever version it is: a
        // hardcoded list of version names stops working the day an API ships
        // its fourth one and every endpoint lands in a group called "V4".
        foreach ($segments as $segment) {
            if (strtolower($segment) !== 'api' && ! Versions::looksLikeVersion($segment)) {
                return LusenStr::title($segment);
            }
        }

        return LusenStr::title($segments[0]);
    }

    /**
     * A readable stand-in until a docblock or attribute supplies a real one.
     * Never left null, because an untitled page is useless to both a reader
     * and a retrieval model.
     *
     * Resource controllers get the sentence a person would have written:
     * `index` on a Customers controller is "List customers", not "Index".
     */
    private function fallbackSummary(RouteCandidate $candidate, ?string $group): string
    {
        if ($candidate->action === null) {
            return $candidate->method->value.' '.$candidate->uri;
        }

        $conventional = $group === null
            ? null
            : $this->resourceActionSummary($candidate->action, $group);

        return $conventional ?? LusenStr::title($candidate->action);
    }

    /**
     * The route's throttle middleware, if it has one. The most restrictive
     * wins: a route inside a `throttle:60,1` group that also declares
     * `throttle:10,1` is limited to 10, and the reader needs the number that
     * will actually stop them.
     */
    private function rateLimit(RouteCandidate $candidate): ?RateLimit
    {
        $found = null;

        foreach ($candidate->middleware() as $middleware) {
            $limit = RateLimit::fromMiddleware($middleware);

            if ($limit === null) {
                continue;
            }

            if ($found === null) {
                $found = $limit;

                continue;
            }

            // A concrete number beats a named limiter whose value is unknown.
            if ($found->isNamed() && ! $limit->isNamed()) {
                $found = $limit;

                continue;
            }

            if (! $found->isNamed() && ! $limit->isNamed()
                && $this->perMinute($limit) < $this->perMinute($found)) {
                $found = $limit;
            }
        }

        return $found;
    }

    private function perMinute(RateLimit $limit): float
    {
        return ($limit->maxAttempts ?? PHP_INT_MAX) / max(1, $limit->perMinutes);
    }

    /**
     * The scheme the middleware describes, and the scopes it names.
     *
     * Passport's `scopes:` and Sanctum's `abilities:` both state exactly what
     * a token must carry. Reducing that to a boolean throws away the one
     * detail an integrator cannot guess.
     */
    private function security(RouteCandidate $candidate): ?SecurityScheme
    {
        $scopes = [];
        $type = null;

        foreach ($candidate->middleware() as $middleware) {
            $parts = explode(':', $middleware, 2);
            $name = strtolower($parts[0]);
            $argument = $parts[1] ?? null;

            if (in_array($name, ['scopes', 'scope'], true)) {
                $type ??= SecurityScheme::OAUTH2;
                $scopes = [...$scopes, ...$this->arguments($argument)];

                continue;
            }

            if (in_array($name, ['abilities', 'ability'], true)) {
                $type ??= SecurityScheme::BEARER;
                $scopes = [...$scopes, ...$this->arguments($argument)];

                continue;
            }

            if ($name === 'client') {
                $type ??= SecurityScheme::OAUTH2;
                $scopes = [...$scopes, ...$this->arguments($argument)];

                continue;
            }

            if ($middleware === 'auth.basic' || $name === 'auth.basic') {
                $type ??= SecurityScheme::BASIC;
            }
        }

        if ($type === null) {
            return $this->isAuthenticated($candidate) ? $this->configuredScheme() : null;
        }

        return new SecurityScheme(
            type: $type,
            scopes: array_values(array_unique($scopes)),
        );
    }

    /**
     * The scheme declared in config, for the common case where middleware only
     * says that credentials are needed, not what shape they take.
     */
    private function configuredScheme(): SecurityScheme
    {
        $type = $this->auth['scheme'] ?? SecurityScheme::BEARER;
        $type = is_string($type) ? $type : SecurityScheme::BEARER;

        $headers = $this->auth['headers'] ?? [];
        $headers = is_array($headers)
            ? array_values(array_filter($headers, static fn (mixed $h): bool => is_string($h)))
            : [];

        return new SecurityScheme(type: $type, headers: $headers);
    }

    /**
     * @return list<string>
     */
    private function arguments(?string $argument): array
    {
        if ($argument === null || trim($argument) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $argument))));
    }

    private function isAuthenticated(RouteCandidate $candidate): bool
    {
        foreach ($candidate->middleware() as $middleware) {
            foreach (self::AUTH_MIDDLEWARE as $pattern) {
                if (Str::is($pattern, $middleware)) {
                    return true;
                }
            }
        }

        return false;
    }
}
