<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Schema;

/**
 * Flattens a schema into table rows.
 *
 * A response schema is a tree, and a reader scanning for one field should not
 * have to unfold nested JSON to find it. Dotted paths - `data.items[].price` -
 * read the way people actually talk about response fields.
 *
 * Shared by the HTML table and the Markdown mirror so both list the same
 * fields in the same order.
 */
final class SchemaFields
{
    /**
     * Deep enough for a wrapped, paginated collection of nested resources;
     * past that a table stops helping.
     */
    private const MAX_DEPTH = 4;

    /**
     * @return list<array{name: string, type: string, required: bool, description: string}>
     */
    public static function flatten(Schema $schema, string $prefix = '', int $depth = 0): array
    {
        $rows = [];

        foreach (self::paths($schema, $prefix, $depth) as $path => $field) {
            $rows[] = [
                'name' => $path,
                'type' => $field['schema']->label(),
                'required' => $field['required'],
                'description' => $field['schema']->description ?? '',
            ];
        }

        return $rows;
    }

    /**
     * The same walk, keyed by path and keeping the schema itself.
     *
     * A table only needs the rendered label. Anything comparing two builds of
     * a response needs the schema behind it, so that `integer` becoming
     * `string` can be told apart from a field whose type nobody knew before -
     * one is a broken client, the other is a better docs build.
     *
     * @return array<string, array{schema: Schema, required: bool}>
     */
    public static function paths(Schema $schema, string $prefix = '', int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        // A top-level array unwraps to its element shape, since the caller
        // already knows it is a list.
        if ($schema->type === SchemaType::Array && $schema->items !== null && $prefix === '') {
            return self::paths($schema->items, '[]', $depth);
        }

        $paths = [];

        foreach ($schema->properties as $name => $property) {
            $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;

            $paths[$path] = [
                'schema' => $property,
                'required' => in_array((string) $name, $schema->required, true),
            ];

            $paths = [...$paths, ...self::children($property, $path, $depth)];
        }

        return $paths;
    }

    public static function hasFields(?Schema $schema): bool
    {
        return $schema !== null && self::flatten($schema) !== [];
    }

    /**
     * @return array<string, array{schema: Schema, required: bool}>
     */
    private static function children(Schema $schema, string $path, int $depth): array
    {
        if ($schema->type === SchemaType::Object && $schema->properties !== []) {
            return self::paths($schema, $path, $depth + 1);
        }

        if ($schema->type === SchemaType::Array && $schema->items !== null) {
            return self::paths($schema->items, $path.'[]', $depth + 1);
        }

        return [];
    }
}
