<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
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
     * @return list<array{name: string, parent: string, leaf: string, type: string, required: bool, description: string, depth: int}>
     */
    public static function flatten(Schema $schema, string $prefix = '', int $depth = 0): array
    {
        $rows = [];

        foreach (self::paths($schema, $prefix, $depth) as $path => $field) {
            $separator = strrpos($path, '.');

            $rows[] = [
                'name' => $path,
                // The path split at its last separator. `items[].product_id`
                // already says "inside items", so a table that also indents
                // says it twice - and pays for it with the aligned left edge
                // that makes a column scannable, in a layout where width is
                // the scarce thing. Muting the parent lets the eye land on
                // the part that differs while the whole path stays one
                // string to search for and to copy.
                'parent' => $separator === false ? '' : substr($path, 0, $separator + 1),
                'leaf' => $separator === false ? $path : substr($path, $separator + 1),
                'type' => $field['schema']->label(),
                'required' => $field['required'],
                'description' => $field['schema']->description ?? '',
                'depth' => $field['depth'],
            ];
        }

        return $rows;
    }

    /**
     * The same rows, for a list of parameters rather than one schema.
     *
     * A request body arrives as a flat list of top-level parameters, and
     * `items` being `array` is not documentation - somebody building the
     * request needs `items[].product_id`. The list becomes the object it
     * describes and goes through the same walk a response does, so a request
     * table and a response table can no longer say different things about the
     * same shape.
     *
     * A parameter's description belongs to the parameter and is the more
     * specific of the two, so it wins over one the schema carries. It is
     * applied to the finished rows rather than to the schema because
     * `Schema::describedAs()` deliberately refuses to overwrite - there, a
     * description already written is the one that was meant.
     *
     * @param  list<Parameter>  $parameters
     * @return list<array{name: string, parent: string, leaf: string, type: string, required: bool, description: string, depth: int}>
     */
    public static function forParameters(array $parameters): array
    {
        $properties = [];
        $required = [];
        $descriptions = [];

        foreach ($parameters as $parameter) {
            $properties[$parameter->name] = $parameter->schema;

            if ($parameter->required) {
                $required[] = $parameter->name;
            }

            if ($parameter->description !== null && $parameter->description !== '') {
                $descriptions[$parameter->name] = $parameter->description;
            }
        }

        if ($properties === []) {
            return [];
        }

        $rows = self::flatten(Schema::object($properties, $required));

        foreach ($rows as $index => $row) {
            if ($row['depth'] === 0 && isset($descriptions[$row['name']])) {
                $rows[$index]['description'] = $descriptions[$row['name']];
            }
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
     * @return array<string, array{schema: Schema, required: bool, depth: int}>
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
                // How deep the row sits, so a table can indent it. The dotted
                // path already says where a field lives; the indent is what
                // makes the shape visible without reading every path.
                'depth' => $depth,
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
     * @return array<string, array{schema: Schema, required: bool, depth: int}>
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
