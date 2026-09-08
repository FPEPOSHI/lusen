<?php

declare(strict_types=1);

namespace Lusen\Extract\Rules;

use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Enums\SchemaType;
use Lusen\Ir\Parameter;
use Lusen\Ir\Schema;

/**
 * Turns Laravel's flat, dot-notated rule keys into nested schemas.
 *
 * A real FormRequest describes a tree using a flat map:
 *
 *     'customer'            => 'required|array',
 *     'customer.email'      => 'required|email',
 *     'items'               => 'required|array|min:1',
 *     'items.*.product_id'  => 'required|integer',
 *
 * Reproducing that as four sibling parameters would be useless. This rebuilds
 * the tree so the body documents as one object with a nested customer and an
 * array of line items - which is what the endpoint actually accepts.
 */
final class RuleTree
{
    /**
     * @param  array<string, RuleSet>  $rules  dot-notated path => rules
     * @return list<Parameter>
     */
    public static function toParameters(array $rules, ParameterLocation $in): array
    {
        $tree = [];

        foreach ($rules as $path => $set) {
            if ($set->isProhibited()) {
                continue;
            }

            self::insert($tree, explode('.', $path), $set);
        }

        $parameters = [];

        foreach ($tree as $name => $node) {
            $set = $node['rules'] ?? null;

            $parameters[] = new Parameter(
                name: (string) $name,
                in: $in,
                schema: self::schema($node),
                required: $set?->isRequired() ?? false,
                description: $set?->description(),
            );
        }

        return $parameters;
    }

    /**
     * @param  array<string, array{rules?: RuleSet, children: array<string, mixed>}>  $tree
     * @param  list<string>  $segments
     */
    private static function insert(array &$tree, array $segments, RuleSet $set): void
    {
        $segment = array_shift($segments);

        if ($segment === null || $segment === '') {
            return;
        }

        if (! isset($tree[$segment])) {
            $tree[$segment] = ['children' => []];
        }

        if ($segments === []) {
            $tree[$segment]['rules'] = $set;

            return;
        }

        /** @var array<string, array{rules?: RuleSet, children: array<string, mixed>}> $children */
        $children = $tree[$segment]['children'];

        self::insert($children, $segments, $set);

        $tree[$segment]['children'] = $children;
    }

    /**
     * The description travels on the schema, not beside it.
     *
     * A top-level field's docblock reaches the page as the parameter's own
     * description, and until this carried it too, everything below the top
     * level lost the sentence its author wrote: `items.*.product_id`
     * documented as `integer, required` and nothing else, on the very fields
     * a caller cannot guess. The schema is the only thing that survives the
     * walk down into a nested body, so it has to hold the prose.
     *
     * @param  array{rules?: RuleSet, children: array<string, mixed>}  $node
     */
    private static function schema(array $node): Schema
    {
        $set = $node['rules'] ?? null;

        /** @var array<string, array{rules?: RuleSet, children: array<string, mixed>}> $children */
        $children = $node['children'];

        $base = $set?->toSchema() ?? new Schema;

        // `items.*` - a wildcard child means this node is a list, and its own
        // rules (min, max) constrain the list rather than an element.
        if (isset($children['*'])) {
            $base = new Schema(
                type: SchemaType::Array,
                nullable: $base->nullable,
                items: self::schema($children['*']),
                constraints: $base->constraints,
            );
        } elseif ($children !== []) {
            $properties = [];
            $required = [];

            foreach ($children as $name => $child) {
                $properties[(string) $name] = self::schema($child);

                if (($child['rules'] ?? null)?->isRequired() === true) {
                    $required[] = (string) $name;
                }
            }

            $base = new Schema(
                type: SchemaType::Object,
                nullable: $base->nullable,
                properties: $properties,
                required: $required,
                constraints: $base->constraints,
            );
        }

        return $base->describedAs($set?->description());
    }
}
