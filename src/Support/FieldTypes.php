<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Schema;

/**
 * Types a response field from its name.
 *
 * A resource that returns `$this->created_at` gives no type away, but the
 * name does. This is deliberately conservative: only conventions strong
 * enough that being wrong would be surprising. Everything else stays
 * `any`, because an unknown type documented as `string` is a guess a reader
 * would act on.
 *
 * Ambiguous-but-tempting names are left alone on purpose. `price` and
 * `amount` are integers of minor units in some codebases and floats in
 * others; `status` is a string in most and an integer in plenty.
 */
final class FieldTypes
{
    public static function forName(string $name): Schema
    {
        $needle = strtolower($name);

        // Identifiers. `uuid` first: it is a string id, and would otherwise
        // be caught by the integer rule below.
        if ($needle === 'uuid' || str_ends_with($needle, '_uuid')) {
            return Schema::string('uuid');
        }

        if ($needle === 'id' || str_ends_with($needle, '_id')) {
            return Schema::integer();
        }

        // Laravel's timestamp convention is near-universal.
        if (str_ends_with($needle, '_at')) {
            return Schema::string('date-time');
        }

        if (str_ends_with($needle, '_date') || $needle === 'date') {
            return Schema::string('date');
        }

        // Eloquent's withCount() suffix, and the boolean prefixes Laravel's
        // own conventions and most style guides use.
        if (str_ends_with($needle, '_count')) {
            return Schema::integer();
        }

        if (str_starts_with($needle, 'is_') || str_starts_with($needle, 'has_')
            || str_starts_with($needle, 'can_') || str_starts_with($needle, 'should_')) {
            return Schema::boolean();
        }

        if ($needle === 'email' || str_ends_with($needle, '_email')) {
            return Schema::string('email');
        }

        if ($needle === 'url' || str_ends_with($needle, '_url')) {
            return Schema::string('uri');
        }

        if ($needle === 'slug' || $needle === 'name' || $needle === 'title'
            || $needle === 'description' || str_ends_with($needle, '_name')) {
            return Schema::string();
        }

        return Schema::any();
    }

    /**
     * A PHP cast in the resource is authoritative - the author said the type
     * out loud, so it beats any name convention.
     */
    public static function forCast(string $cast): ?Schema
    {
        return match (strtolower($cast)) {
            'int', 'integer' => Schema::integer(),
            'float', 'double', 'real' => Schema::number(),
            'bool', 'boolean' => Schema::boolean(),
            'string' => Schema::string(),
            'array' => Schema::arrayOf(Schema::any()),
            'object' => Schema::object(),
            default => null,
        };
    }
}
