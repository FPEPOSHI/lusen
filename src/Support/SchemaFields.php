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
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        // A top-level array unwraps to its element shape, since the caller
        // already knows it is a list.
        if ($schema->type === SchemaType::Array && $schema->items !== null && $prefix === '') {
            return self::flatten($schema->items, '[]', $depth);
        }

        $rows = [];

        foreach ($schema->properties as $name => $property) {
            $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;

            $rows[] = [
                'name' => $path,
                'type' => $property->label(),
                'required' => in_array((string) $name, $schema->required, true),
                'description' => $property->description ?? '',
            ];

            $rows = [...$rows, ...self::children($property, $path, $depth)];
        }

        return $rows;
    }

    public static function hasFields(?Schema $schema): bool
    {
        return $schema !== null && self::flatten($schema) !== [];
    }

    /**
     * @return list<array{name: string, type: string, required: bool, description: string}>
     */
    private static function children(Schema $schema, string $path, int $depth): array
    {
        if ($schema->type === SchemaType::Object && $schema->properties !== []) {
            return self::flatten($schema, $path, $depth + 1);
        }

        if ($schema->type === SchemaType::Array && $schema->items !== null) {
            return self::flatten($schema->items, $path.'[]', $depth + 1);
        }

        return [];
    }
}
