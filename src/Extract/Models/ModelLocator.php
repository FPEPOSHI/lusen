<?php

declare(strict_types=1);

namespace Lusen\Extract\Models;

use Lusen\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Works out which Eloquent model a resource wraps.
 *
 * A JsonResource never says so outright - `$this->name` resolves through
 * `__get` at runtime - so the link has to be recovered from the evidence a
 * codebase leaves behind. In order of how much it can be trusted:
 *
 *   1. `@mixin User` on the resource, which is a deliberate statement and is
 *      already present in most projects for the sake of IDE completion.
 *   2. A `@property` docblock naming the model.
 *   3. The `UserResource` -> `App\Models\User` naming convention.
 *
 * A wrong guess here would type every field on a page from the wrong table, so
 * the convention step verifies the class exists and is a model before
 * believing it.
 */
final class ModelLocator
{
    /**
     * @var array<string, class-string|null>
     */
    private static array $cache = [];

    /**
     * @param  list<string>  $namespaces  where models live, most likely first
     */
    public function __construct(private readonly array $namespaces = ['App\\Models', 'App']) {}

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param  class-string  $resource
     * @return class-string|null
     */
    public function forResource(string $resource): ?string
    {
        $key = $resource.'@'.implode(',', $this->namespaces);

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return self::$cache[$key] = $this->locate($resource);
    }

    /**
     * @param  class-string  $resource
     * @return class-string|null
     */
    private function locate(string $resource): ?string
    {
        $documented = $this->fromDocBlock($resource);

        if ($documented !== null) {
            return $documented;
        }

        return $this->fromNamingConvention($resource);
    }

    /**
     * @param  class-string  $resource
     * @return class-string|null
     */
    private function fromDocBlock(string $resource): ?string
    {
        try {
            $doc = (new ReflectionClass($resource))->getDocComment();
        } catch (Throwable) {
            return null;
        }

        if ($doc === false) {
            return null;
        }

        // @mixin is the strongest signal: it exists precisely to say "this
        // object proxies to that one".
        if (preg_match('/@mixin\s+\\\\?([\w\\\\]+)/', $doc, $match) === 1) {
            $resolved = $this->resolve($match[1]);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (preg_match('/@property(?:-read)?\s+\\\\?([\w\\\\]+)\s+\$resource/', $doc, $match) === 1) {
            return $this->resolve($match[1]);
        }

        return null;
    }

    /**
     * `App\Http\Resources\UserResource` -> `App\Models\User`.
     *
     * @param  class-string  $resource
     * @return class-string|null
     */
    private function fromNamingConvention(string $resource): ?string
    {
        $short = Str::afterLast($resource, '\\');

        $base = match (true) {
            str_ends_with($short, 'Resource') => substr($short, 0, -8),
            str_ends_with($short, 'Collection') => substr($short, 0, -10),
            default => $short,
        };

        if ($base === '') {
            return null;
        }

        // A resource may sit beside its model, which is worth trying before
        // the configured namespaces.
        $candidates = [Str::beforeLast($resource, '\\').'\\'.$base];

        foreach ($this->namespaces as $namespace) {
            $candidates[] = trim($namespace, '\\').'\\'.$base;
        }

        foreach ($candidates as $candidate) {
            $resolved = $this->resolve($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    private function resolve(string $class): ?string
    {
        $class = ltrim($class, '\\');

        if (! class_exists($class)) {
            return null;
        }

        // Only an Eloquent model can answer questions about casts and columns;
        // anything else that happens to match the name is a false positive.
        if (! is_subclass_of($class, 'Illuminate\Database\Eloquent\Model')) {
            return null;
        }

        // is_subclass_of already narrowed this to a model class-string.
        return $class;
    }
}
