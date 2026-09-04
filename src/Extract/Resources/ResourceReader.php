<?php

declare(strict_types=1);

namespace Lusen\Extract\Resources;

use Lusen\Extract\Models\ModelLocator;
use Lusen\Extract\Models\ModelSchema;
use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use Lusen\Support\FieldTypes;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use ReflectionClass;
use Throwable;

/**
 * Reads a JsonResource's `toArray()` into a response schema.
 *
 * This is the deepest static analysis in the package, and the one with the
 * least certain input: `'name' => $this->name` names a field but says nothing
 * about its type. The rule throughout is that evidence beats convention and
 * convention beats invention - an explicit `(int)` cast wins, then the field
 * name, and anything still unknown stays `any` rather than becoming a
 * plausible-looking `string`.
 *
 * Parsed, never executed. `toArray()` touches the model, its relations and
 * often the request, so calling it would mean a database - which a docs build
 * in CI cannot have.
 */
final class ResourceReader
{
    /**
     * Nested resources can legitimately cycle - a UserResource embedding
     * PostResource embedding its author. Depth is capped rather than tracked,
     * because a schema that deep is unreadable anyway.
     */
    private const MAX_DEPTH = 3;

    /**
     * @var array<string, Schema|null>
     */
    private static array $cache = [];

    private static ?ModelSchema $models = null;

    private static ?ModelLocator $locator = null;

    /**
     * The model lookup is optional: without it every `$this->field` falls back
     * to naming conventions, which is exactly how this worked before models
     * were consulted at all.
     */
    public static function useModels(?ModelSchema $models, ?ModelLocator $locator): void
    {
        self::$models = $models;
        self::$locator = $locator;
        self::$cache = [];
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * The shape of one resource, before any wrapping.
     *
     * @param  class-string  $class
     */
    public static function read(string $class, int $depth = 0): ?Schema
    {
        if ($depth >= self::MAX_DEPTH) {
            return Schema::any();
        }

        $key = $class.'@'.$depth;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        // Reserve the slot before recursing so a cyclic resource terminates.
        self::$cache[$key] = Schema::any();

        return self::$cache[$key] = self::analyse($class, $depth);
    }

    /**
     * Whether the resource opts out of Laravel's `data` wrapper by declaring
     * `public static $wrap = null;`.
     *
     * @param  class-string  $class
     */
    public static function wrapsResponses(string $class): bool
    {
        if (! property_exists($class, 'wrap')) {
            return true;
        }

        try {
            $property = (new ReflectionClass($class))->getProperty('wrap');
        } catch (Throwable) {
            return true;
        }

        return $property->getDefaultValue() !== null;
    }

    /**
     * A bare array literal returned from an action, with no resource involved.
     */
    public static function literalToSchema(Array_ $array): Schema
    {
        return self::arrayToSchema($array, 0, null);
    }

    /**
     * @param  class-string  $class
     */
    private static function analyse(string $class, int $depth): ?Schema
    {
        $method = Ast::method($class, 'toArray');

        if ($method === null) {
            return null;
        }

        $array = Ast::returnedArray($method);

        if ($array === null) {
            return null;
        }

        return self::arrayToSchema($array, $depth, self::modelFor($class));
    }

    /**
     * @param  class-string  $resource
     * @return class-string|null
     */
    private static function modelFor(string $resource): ?string
    {
        return self::$locator?->forResource($resource);
    }

    /**
     * A field's type as the model states it, when the model is known.
     *
     * @param  class-string|null  $model
     */
    private static function fromModel(?string $model, string $name): ?Schema
    {
        if ($model === null || self::$models === null) {
            return null;
        }

        return self::$models->field($model, $name);
    }

    /**
     * @param  class-string|null  $model
     */
    private static function arrayToSchema(Array_ $array, int $depth, ?string $model): Schema
    {
        $properties = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                // `...$this->extra` and computed keys cannot be read.
                continue;
            }

            $properties[$item->key->value] = self::valueToSchema(
                $item->value,
                $item->key->value,
                $depth,
                $model,
            );
        }

        return Schema::object($properties);
    }

    /**
     * @param  class-string|null  $model
     */
    private static function valueToSchema(Expr $value, string $name, int $depth, ?string $model = null): Schema
    {
        // An explicit cast is the author stating the type outright.
        $cast = self::fromCast($value);

        if ($cast !== null) {
            return $cast;
        }

        // A literal is not just evidence of the type - it is the value, and
        // showing it beats showing a guess derived from the field name.
        if ($value instanceof String_) {
            return Schema::string()->withExample($value->value);
        }

        if ($value instanceof Int_) {
            return Schema::integer()->withExample($value->value);
        }

        if ($value instanceof Float_) {
            return Schema::number()->withExample($value->value);
        }

        // `true`, `false` and `null` are constant fetches rather than scalars,
        // so they were reaching the fallback and documenting as untyped.
        if ($value instanceof ConstFetch) {
            $constant = $value->name->toLowerString();

            if ($constant === 'true' || $constant === 'false') {
                return Schema::boolean()->withExample($constant === 'true');
            }

            if ($constant === 'null') {
                return Schema::any()->asNullable();
            }
        }

        if ($value instanceof Array_) {
            return self::arrayToSchema($value, $depth, $model);
        }

        if ($value instanceof New_) {
            return self::fromNestedResource($value, $depth) ?? self::fallback($model, $name);
        }

        if ($value instanceof StaticCall) {
            return self::fromStaticCall($value, $name, $depth, $model);
        }

        if ($value instanceof MethodCall) {
            return self::fromMethodCall($value, $name, $depth, $model);
        }

        // `$this->field` and anything else unreadable: ask the model, then the
        // naming conventions, then admit the type is unknown.
        return self::fallback($model, $name);
    }

    /**
     * @param  class-string|null  $model
     */
    private static function fallback(?string $model, string $name): Schema
    {
        return self::fromModel($model, $name) ?? FieldTypes::forName($name);
    }

    private static function fromCast(Expr $value): ?Schema
    {
        return match (true) {
            $value instanceof Cast\Int_ => Schema::integer(),
            $value instanceof Cast\Double => Schema::number(),
            $value instanceof Cast\Bool_ => Schema::boolean(),
            $value instanceof Cast\String_ => Schema::string(),
            $value instanceof Cast\Array_ => Schema::arrayOf(Schema::any()),
            default => null,
        };
    }

    /**
     * `new UserResource($this->author)` - a single nested resource.
     */
    private static function fromNestedResource(New_ $new, int $depth): ?Schema
    {
        if (! $new->class instanceof Node\Name) {
            return null;
        }

        $class = $new->class->toString();

        if (! class_exists($class) || ! method_exists($class, 'toArray')) {
            return null;
        }

        /** @var class-string $class */
        return self::read($class, $depth + 1) ?? Schema::any();
    }

    /**
     * `UserResource::collection($this->authors)` - a list of nested resources.
     */
    /**
     * @param  class-string|null  $model
     */
    private static function fromStaticCall(StaticCall $call, string $name, int $depth, ?string $model = null): Schema
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return self::fallback($model, $name);
        }

        $class = $call->class->toString();

        if ($call->name->toLowerString() !== 'collection'
            || ! class_exists($class)
            || ! method_exists($class, 'toArray')) {
            return self::fallback($model, $name);
        }

        /** @var class-string $class */
        $items = self::read($class, $depth + 1) ?? Schema::any();

        return Schema::arrayOf($items);
    }

    /**
     * `$this->whenLoaded('author')`, `$this->when($x, ...)` and friends.
     *
     * These are conditional fields: present only sometimes. The shape of the
     * value comes from the wrapped argument where there is one.
     */
    /**
     * @param  class-string|null  $model
     */
    private static function fromMethodCall(MethodCall $call, string $name, int $depth, ?string $model = null): Schema
    {
        if (! $call->name instanceof Node\Identifier) {
            return self::fallback($model, $name);
        }

        $method = $call->name->toLowerString();
        $arguments = $call->getArgs();

        if (in_array($method, ['when', 'whenloaded', 'whennotnull', 'whenappended'], true)) {
            // The value argument is second for when(), first for whenLoaded().
            $index = $method === 'when' ? 1 : 1;

            if (isset($arguments[$index])) {
                return self::valueToSchema($arguments[$index]->value, $name, $depth, $model);
            }

            return self::fallback($model, $name);
        }

        // A formatting call on a date, e.g. $this->created_at->toIso8601String().
        if (str_contains($method, 'iso8601') || str_contains($method, 'todatetimestring')) {
            return Schema::string('date-time');
        }

        if ($method === 'todatestring') {
            return Schema::string('date');
        }

        return self::fallback($model, $name);
    }
}
