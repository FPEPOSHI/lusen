<?php

declare(strict_types=1);

namespace Lusen\Diff;

use Lusen\Ir\ApiSpec;

/**
 * The recorded shape of the API, as a file somebody commits.
 *
 * It is the IR itself rather than a format of its own: the spec already
 * serialises deterministically and round-trips through `fromArray()`, so a
 * baseline is just yesterday's build, and every field the diff will ever want
 * to compare is already in it.
 *
 * `sourceFiles` is the one thing dropped. Those are absolute paths recorded
 * for the incremental cache, so leaving them in would put one developer's
 * home directory in a committed file and produce a diff on every machine that
 * is not theirs. Nothing about the API's contract lives there.
 */
final class Baseline
{
    public static function encode(ApiSpec $spec): string
    {
        $data = $spec->toArray();

        if (isset($data['groups']) && is_array($data['groups'])) {
            $data['groups'] = array_map(self::stripGroup(...), $data['groups']);
        }

        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    public static function decode(string $json): ApiSpec
    {
        return ApiSpec::fromJson($json);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stripGroup(mixed $group): array
    {
        if (! is_array($group)) {
            return [];
        }

        if (isset($group['endpoints']) && is_array($group['endpoints'])) {
            $group['endpoints'] = array_map(
                static function (mixed $endpoint): array {
                    if (! is_array($endpoint)) {
                        return [];
                    }

                    unset($endpoint['sourceFiles']);

                    /** @var array<string, mixed> $endpoint */
                    return $endpoint;
                },
                $group['endpoints'],
            );
        }

        /** @var array<string, mixed> $group */
        return $group;
    }
}
