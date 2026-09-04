<?php

declare(strict_types=1);

namespace Lusen\Extract\Models;

use Lusen\Ir\Schema;
use Lusen\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * What a model knows about the type of each of its attributes.
 *
 * Two sources, and casts win where they disagree. A cast is what the value
 * looks like by the time a resource serialises it - a `json` column cast to
 * `array` is an array in the response, whatever the database stores. The
 * column is the fallback, and the only source that reports nullability.
 */
final class ModelSchema
{
    /**
     * @var array<string, array<string, Schema>>
     */
    private static array $cache = [];

    public function __construct(private readonly MigrationReader $migrations) {}

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param  class-string  $model
     * @return array<string, Schema>
     */
    public function fields(string $model): array
    {
        if (isset(self::$cache[$model])) {
            return self::$cache[$model];
        }

        $columns = $this->migrations->columns($this->table($model));
        $casts = CastReader::read($model);

        return self::$cache[$model] = [...$columns, ...$casts];
    }

    /**
     * @param  class-string  $model
     */
    public function field(string $model, string $name): ?Schema
    {
        return $this->fields($model)[$name] ?? null;
    }

    /**
     * The model's `$table` if it declares one, else Laravel's convention.
     *
     * @param  class-string  $model
     */
    public function table(string $model): string
    {
        try {
            $declared = (new ReflectionClass($model))->getDefaultProperties()['table'] ?? null;
        } catch (Throwable) {
            $declared = null;
        }

        return is_string($declared) && $declared !== ''
            ? $declared
            : Str::tableName($model);
    }
}
