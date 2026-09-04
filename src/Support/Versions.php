<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\ApiVersion;
use Lusen\Ir\Endpoint;

/**
 * Which version of the API an endpoint belongs to.
 *
 * A Laravel API that has lived long enough serves `/api/v1/orders` next to
 * `/api/v2/orders`, and undocumented that is the single most confusing thing
 * a reference can do: two pages titled "List orders", two identical meta
 * descriptions competing for the same query, and a retrieval model with no way
 * to tell which one it should be telling somebody to call.
 *
 * Detection is deliberately narrow. A leading `v1`, `v2.1` or ISO date segment
 * is a version; anything else is a resource. Scanning further into the path
 * would eventually read `/api/reports/v2` as a version and split a perfectly
 * ordinary API in half, and inventing a version is far worse than missing one
 * an author can declare with `#[ApiDoc(version: 'v2')]`.
 */
final class Versions
{
    /**
     * `v1`, `v2_1`, `V3.0`, or a dated version such as `2026-01-15`.
     */
    private const SEGMENT = '/^(?:v\d+(?:[._-]\d+)*|\d{4}-\d{2}-\d{2})$/i';

    /**
     * Segments that may sit in front of the version. Only ever matched as the
     * very first segment, so `/api/v2/…` resolves and `/orders/api/v2` does
     * not pretend to.
     *
     * @var list<string>
     */
    private const PREFIXES = ['api'];

    public static function looksLikeVersion(string $segment): bool
    {
        return preg_match(self::SEGMENT, $segment) === 1;
    }

    /**
     * The version a URI declares, lowercased so `/API/V2` and `/api/v2` are
     * one version rather than two.
     */
    public static function fromUri(string $uri): ?string
    {
        return self::scan(explode('/', trim($uri, '/')));
    }

    /**
     * The version a route name declares — `api.v2.orders.index`.
     *
     * Only consulted when the URI is silent, which is how a header-versioned
     * API still gets documented: its routes carry the version in their names
     * even though their paths do not.
     */
    public static function fromRouteName(?string $name): ?string
    {
        return $name === null || $name === ''
            ? null
            : self::scan(explode('.', trim($name, '.')));
    }

    /**
     * The URI with its version segment removed: the identity an operation
     * keeps across versions, and so the key that pairs `v1`'s list of orders
     * with `v2`'s.
     */
    public static function strip(string $uri): string
    {
        $version = self::fromUri($uri);

        if ($version === null) {
            return $uri;
        }

        $segments = explode('/', trim($uri, '/'));

        foreach ($segments as $index => $segment) {
            if (strtolower($segment) === $version) {
                unset($segments[$index]);

                break;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Newest first, because a reader arriving at the documentation wants the
     * version they should be writing against, not the one they should be
     * leaving. Numeric where it can be — `v10` is newer than `v9`, which
     * string ordering gets backwards.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function sort(array $names): array
    {
        usort($names, static fn (string $a, string $b): int => self::compare($b, $a));

        return $names;
    }

    /**
     * Ascending: older version first.
     */
    public static function compare(string $a, string $b): int
    {
        $left = self::numbers($a);
        $right = self::numbers($b);

        if ($left === null || $right === null) {
            return strnatcasecmp($a, $b);
        }

        $length = max(count($left), count($right));

        for ($i = 0; $i < $length; $i++) {
            $order = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);

            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * Every version the endpoints actually use, newest first, with the two
     * facts an integrator acts on: which one to write against, and which ones
     * are on the way out.
     *
     * @param  list<Endpoint>  $endpoints
     * @param  array<string, mixed>  $config  the `versions` section of config/lusen.php
     * @return list<ApiVersion>
     */
    public static function catalogue(array $endpoints, array $config = []): array
    {
        $names = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->version !== null) {
                $names[$endpoint->version] = true;
            }
        }

        if ($names === []) {
            return [];
        }

        $names = self::sort(array_keys($names));
        $sunset = self::deprecations($config);
        $current = self::current($config, $names);

        return array_map(
            static fn (string $name): ApiVersion => new ApiVersion(
                name: $name,
                current: $name === $current,
                deprecated: array_key_exists($name, $sunset)
                    || ($name !== $current && self::allDeprecated($endpoints, $name)),
                sunset: $sunset[$name] ?? null,
            ),
            $names,
        );
    }

    /**
     * Everything about an endpoint that can only be known once every version
     * is known: whether it belongs to a version on its way out, and where its
     * newer edition lives.
     *
     * @param  list<Endpoint>  $endpoints
     * @param  list<ApiVersion>  $versions
     * @return list<Endpoint>
     */
    public static function relate(array $endpoints, array $versions): array
    {
        if ($versions === []) {
            return $endpoints;
        }

        $order = self::order($versions);
        $current = self::currentIndex($versions);
        $deprecated = [];

        foreach ($versions as $version) {
            $deprecated[$version->name] = $version->deprecated;
        }

        /** @var array<string, array<string, string>> $editions  operation => version => endpoint id */
        $editions = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->version !== null) {
                $editions[self::operationKey($endpoint)][$endpoint->version] = $endpoint->id;
            }
        }

        return array_map(
            static fn (Endpoint $endpoint): Endpoint => $endpoint->version === null
                ? $endpoint
                : $endpoint->with(
                    // Never false: a version being current must not un-deprecate
                    // an endpoint the codebase deprecated on its own.
                    deprecated: ($deprecated[$endpoint->version] ?? false) ? true : null,
                    supersededBy: self::successor($endpoint, $order, $editions, $current),
                ),
            $endpoints,
        );
    }

    /**
     * Version name => position, newest first.
     *
     * @param  list<ApiVersion>  $versions
     * @return array<string, int>
     */
    public static function order(array $versions): array
    {
        $order = [];

        foreach ($versions as $index => $version) {
            $order[$version->name] = $index;
        }

        return $order;
    }

    /**
     * The identity an operation keeps across versions: the method plus the
     * path with the version taken out. This is what makes
     * `GET /api/v1/orders` and `GET /api/v2/orders` recognisably two editions
     * of one thing rather than two unrelated endpoints.
     */
    public static function operationKey(Endpoint $endpoint): string
    {
        return $endpoint->method->value.' '.self::strip($endpoint->uri);
    }

    /**
     * The edition of this operation a reader should move to.
     *
     * Never anything newer than the current version. An author who pins
     * `current` to `v1` while `v2` is still a preview is saying v2 is not the
     * one to use, and sending every v1 reader there would contradict them.
     *
     * @param  array<string, int>  $order
     * @param  array<string, array<string, string>>  $editions
     * @param  int  $current  position of the version to write against
     */
    private static function successor(Endpoint $endpoint, array $order, array $editions, int $current): ?string
    {
        $position = $order[$endpoint->version] ?? null;

        if ($position === null || $position <= $current) {
            return null;
        }

        $candidates = $editions[self::operationKey($endpoint)] ?? [];

        foreach ($order as $name => $index) {
            if ($index < $current) {
                continue;
            }

            if ($index >= $position) {
                break;
            }

            if (isset($candidates[$name])) {
                return $candidates[$name];
            }
        }

        return null;
    }

    /**
     * Where the version to write against sits in the ordering. Normally the
     * newest, which is position zero.
     *
     * @param  list<ApiVersion>  $versions
     */
    private static function currentIndex(array $versions): int
    {
        foreach ($versions as $index => $version) {
            if ($version->current) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * The version list scanned for the first thing that names a version,
     * stopping at the first thing that does not. Stopping is the point: it is
     * what keeps `/api/orders/v2/items` from claiming to be versioned.
     *
     * @param  list<string>  $segments
     */
    private static function scan(array $segments): ?string
    {
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            if (self::looksLikeVersion($segment)) {
                return strtolower($segment);
            }

            if ($index === 0 && in_array(strtolower($segment), self::PREFIXES, true)) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private static function numbers(string $name): ?array
    {
        if (preg_match('/^v(\d+(?:[._-]\d+)*)$/i', $name, $matches) !== 1) {
            return null;
        }

        return array_map(intval(...), preg_split('/[._-]/', $matches[1]) ?: []);
    }

    /**
     * The configured current version, or the newest one. Configuration only
     * wins when it names a version that exists — a typo should not silently
     * leave every version looking superseded.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $names
     */
    private static function current(array $config, array $names): string
    {
        $configured = $config['current'] ?? null;

        return is_string($configured) && in_array(strtolower($configured), $names, true)
            ? strtolower($configured)
            : $names[0];
    }

    /**
     * `['v1']` or `['v1' => '2026-06-01']`, and any mixture: the value is a
     * sunset date when there is one. Written both ways because most APIs have
     * nothing to promise about when a version stops answering, and the ones
     * that do should not have to configure it somewhere else.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|null>
     */
    private static function deprecations(array $config): array
    {
        $declared = $config['deprecated'] ?? [];

        if (! is_array($declared)) {
            return [];
        }

        $deprecations = [];

        foreach ($declared as $key => $value) {
            if (is_string($key)) {
                $deprecations[strtolower($key)] = is_string($value) && $value !== '' ? $value : null;

                continue;
            }

            if (is_string($value)) {
                $deprecations[strtolower($value)] = null;
            }
        }

        return $deprecations;
    }

    /**
     * A version every one of whose endpoints is marked deprecated is a
     * deprecated version — the codebase already said so, one action at a time.
     *
     * Never applied to the current version: an API whose newest endpoints all
     * carry `@deprecated` is telling you something, but not that the version
     * you are supposed to be using is finished.
     *
     * @param  list<Endpoint>  $endpoints
     */
    private static function allDeprecated(array $endpoints, string $version): bool
    {
        $found = false;

        foreach ($endpoints as $endpoint) {
            if ($endpoint->version !== $version) {
                continue;
            }

            if (! $endpoint->deprecated) {
                return false;
            }

            $found = true;
        }

        return $found;
    }
}
