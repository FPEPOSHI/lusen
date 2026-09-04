<?php

declare(strict_types=1);

namespace Lusen\Extract\Models;

use BackedEnum;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;
use Lusen\Support\Ast;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;

/**
 * Reads a model's casts.
 *
 * Casts beat column types when the two disagree, because a cast is what the
 * value looks like by the time a resource serialises it. A `json` column cast
 * to `array` is an array in the response, whatever the database stores.
 *
 * Handles both spellings: the `$casts` property, and the `casts()` method
 * Laravel 11 introduced.
 */
final class CastReader
{
    /**
     * @param  class-string  $model
     * @return array<string, Schema>
     */
    public static function read(string $model): array
    {
        $array = self::castsArray($model);

        if ($array === null) {
            return [];
        }

        $casts = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $schema = self::toSchema($item->value);

            if ($schema !== null) {
                $casts[$item->key->value] = $schema;
            }
        }

        return $casts;
    }

    /**
     * @param  class-string  $model
     */
    private static function castsArray(string $model): ?Array_
    {
        $method = Ast::method($model, 'casts');

        if ($method !== null) {
            $returned = Ast::returnedArray($method);

            if ($returned !== null) {
                return $returned;
            }
        }

        $class = Ast::class_($model);

        if ($class === null) {
            return null;
        }

        foreach ($class->getProperties() as $property) {
            if (self::isCastsProperty($property)) {
                foreach ($property->props as $declaration) {
                    if ($declaration->default instanceof Array_) {
                        return $declaration->default;
                    }
                }
            }
        }

        return null;
    }

    private static function isCastsProperty(Property $property): bool
    {
        foreach ($property->props as $declaration) {
            if ($declaration->name->toString() === 'casts') {
                return true;
            }
        }

        return false;
    }

    private static function toSchema(Expr $value): ?Schema
    {
        // `'status' => Status::class` - a backed enum's cases are the values.
        if ($value instanceof ClassConstFetch) {
            return self::fromEnum($value);
        }

        if (! $value instanceof String_) {
            return null;
        }

        return self::fromCastName($value->value);
    }

    private static function fromCastName(string $cast): ?Schema
    {
        // `decimal:2`, `datetime:Y-m-d` and friends carry an argument.
        $name = strtolower(explode(':', $cast)[0]);

        return match ($name) {
            'int', 'integer', 'timestamp' => Schema::integer(),
            'real', 'float', 'double', 'decimal' => Schema::number(),
            'bool', 'boolean' => Schema::boolean(),
            'string' => Schema::string(),
            'array', 'json', 'collection' => Schema::arrayOf(Schema::any()),
            'object' => Schema::object(),
            'date' => Schema::string('date'),
            'datetime', 'immutable_datetime', 'custom_datetime' => Schema::string('date-time'),
            'encrypted' => Schema::string(),
            'hashed' => Schema::string(),
            default => self::fromEnumName($cast),
        };
    }

    private static function fromEnum(ClassConstFetch $fetch): ?Schema
    {
        if (! $fetch->class instanceof Name) {
            return null;
        }

        return self::fromEnumName($fetch->class->toString());
    }

    private static function fromEnumName(string $class): ?Schema
    {
        $class = ltrim($class, '\\');

        if (! enum_exists($class)) {
            return null;
        }

        $values = [];

        foreach ($class::cases() as $case) {
            $values[] = $case instanceof BackedEnum ? $case->value : $case->name;
        }

        if ($values === []) {
            return null;
        }

        $isInt = is_int($values[0]);

        /** @var list<string|int|float|bool> $values */
        return Schema::enum(
            $values,
            $isInt ? SchemaType::Integer : SchemaType::String,
        );
    }
}
