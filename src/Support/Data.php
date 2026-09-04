<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * Typed reads out of untrusted arrays.
 *
 * Every `fromArray()` in the IR deserialises data that came off disk, so none
 * of it can be trusted to have the shape it claims. Doing the narrowing here,
 * once, keeps the DTOs free of casts and puts every coercion rule in one
 * place - a value of the wrong type reads as absent rather than becoming a
 * surprising `0` or `""` deeper in an emitter.
 */
final class Data
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v)));
    }

    /**
     * Scalars usable as a JSON Schema enum value.
     *
     * @param  array<string, mixed>  $data
     * @return list<string|int|float|bool>
     */
    public static function scalars(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $v): bool => is_string($v) || is_int($v) || is_float($v) || is_bool($v),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $result = [];

        foreach (self::map($data, $key) as $mapKey => $value) {
            if (is_string($value)) {
                $result[$mapKey] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|int|float|bool>
     */
    public static function scalarMap(array $data, string $key): array
    {
        $result = [];

        foreach (self::map($data, $key) as $mapKey => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $result[$mapKey] = $value;
            }
        }

        return $result;
    }

    /**
     * A nested object, keyed by string.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $mapKey => $mapValue) {
            $result[(string) $mapKey] = $mapValue;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function nullableMap(array $data, string $key): ?array
    {
        return isset($data[$key]) && is_array($data[$key]) ? self::map($data, $key) : null;
    }

    /**
     * A list of nested objects - the shape every `fromArray` collection takes.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function maps(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $entry = [];

            foreach ($item as $itemKey => $itemValue) {
                $entry[(string) $itemKey] = $itemValue;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * A map of nested objects, e.g. Schema::$properties.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    public static function mapOfMaps(array $data, string $key): array
    {
        $result = [];

        foreach (self::map($data, $key) as $mapKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            $entry = [];

            foreach ($value as $itemKey => $itemValue) {
                $entry[(string) $itemKey] = $itemValue;
            }

            $result[$mapKey] = $entry;
        }

        return $result;
    }
}
