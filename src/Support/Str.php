<?php

declare(strict_types=1);

namespace Lusen\Support;

/**
 * The handful of string helpers the package needs, kept local so extraction
 * and emission stay usable outside a booted Laravel app (tests, CI, and the
 * MCP server all run without the framework's helpers guaranteed present).
 */
final class Str
{
    /**
     * "showUserProfile" | "show_user_profile" | "show-user-profile"
     *   -> "Show User Profile"
     */
    public static function title(string $value): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;
        $spaced = str_replace(['-', '_', '.'], ' ', $spaced);
        $spaced = preg_replace('/\s+/', ' ', $spaced) ?? $spaced;

        return ucwords(trim($spaced));
    }

    /**
     * URL-safe lowercase slug. Deterministic: same input always yields the
     * same anchor, which is what keeps deep links citable across builds.
     */
    public static function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';

        return trim($slug, '-');
    }

    public static function afterLast(string $value, string $delimiter): string
    {
        $position = strrpos($value, $delimiter);

        return $position === false ? $value : substr($value, $position + strlen($delimiter));
    }

    public static function beforeLast(string $value, string $delimiter): string
    {
        $position = strrpos($value, $delimiter);

        return $position === false ? '' : substr($value, 0, $position);
    }

    /**
     * Converts a class name to its conventional table name: `OrderLine` becomes
     * `order_lines`.
     */
    public static function tableName(string $class): string
    {
        $short = self::afterLast($class, '\\');
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);

        return self::plural($snake);
    }

    /**
     * Enough English plurals for table naming. Laravel's own pluraliser is far
     * more complete, but it lives in a package this one does not require.
     */
    public static function plural(string $word): string
    {
        if ($word === '') {
            return $word;
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $word) === 1) {
            return $word.'es';
        }

        if (preg_match('/[^aeiou]y$/', $word) === 1) {
            return substr($word, 0, -1).'ies';
        }

        return $word.'s';
    }

    /**
     * The inverse of plural(), to the same limited extent.
     */
    public static function singular(string $word): string
    {
        if (str_ends_with($word, 'ies') && strlen($word) > 3) {
            return substr($word, 0, -3).'y';
        }

        foreach (['ses', 'xes', 'zes', 'ches', 'shes'] as $ending) {
            if (str_ends_with($word, $ending)) {
                return substr($word, 0, -2);
            }
        }

        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * "a user", "an order". Wrong for a handful of words (an hour, a unicorn),
     * and right often enough that the alternative - omitting the article - reads
     * worse everywhere.
     */
    public static function article(string $word): string
    {
        return in_array(strtolower(substr($word, 0, 1)), ['a', 'e', 'i', 'o'], true) ? 'an' : 'a';
    }

    /**
     * Collapses a description to a single line fit for a <meta name="description">
     * or an llms.txt entry.
     */
    public static function summarise(string $value, int $limit = 155): string
    {
        $flat = preg_replace('/\s+/', ' ', strip_tags($value)) ?? $value;
        $flat = trim($flat);

        if (mb_strlen($flat) <= $limit) {
            return $flat;
        }

        $cut = mb_substr($flat, 0, $limit - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > $limit / 2) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r,.;:").'…';
    }
}
