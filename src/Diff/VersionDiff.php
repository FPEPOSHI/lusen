<?php

declare(strict_types=1);

namespace Lusen\Diff;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Support\Versions;

/**
 * What changed between two versions of the same API.
 *
 * The versioning page could already say which operations `v2` added and which
 * `v1` had that it dropped, and then stopped at "the other eight exist in
 * both versions at the same path" - which is the sentence a reader migrating
 * has the most questions about. The spec holds both editions of every one of
 * those eight, so the answer is derivable and was simply not being asked for.
 *
 * The comparison itself is `SpecDiff`. Only the pairing differs: two builds of
 * one API pair on `Endpoint::$id`, but two versions of one API cannot - the
 * ids differ by design (`api.v1.orders.store`, `api.v2.orders.store`), and so
 * do the paths. They pair on `Versions::operationKey()` instead, method plus
 * the version-free path, which is the same key supersession already uses, so
 * "changed since v1" and "superseded by v2" can never disagree about which
 * two endpoints are the same operation.
 *
 * The severity grading carries over intact and means something slightly
 * different here, which is the useful thing about it: `Breaking` between two
 * versions is exactly the list of work a client has to do to migrate.
 */
final class VersionDiff
{
    /**
     * Every operation both versions expose, and what changed in each.
     *
     * Keyed by operation, and operations that changed in no way at all are
     * left out: a migration guide listing forty untouched endpoints buries
     * the three that matter.
     *
     * @return array<string, list<Change>>
     */
    public static function between(ApiSpec $spec, string $older, string $newer): array
    {
        $before = self::operations($spec, $older);
        $after = self::operations($spec, $newer);
        $changes = [];

        foreach ($after as $key => $endpoint) {
            if (! isset($before[$key])) {
                continue;
            }

            $found = SpecDiff::operation($before[$key], $endpoint);

            if ($found !== []) {
                $changes[$key] = $found;
            }
        }

        return $changes;
    }

    /**
     * What changed in this one endpoint since the version before it.
     *
     * Returns nothing for an endpoint in the oldest version, or one whose
     * operation the previous version did not have: there is no earlier
     * edition to have changed from, and "new in v2" is already what the
     * versioning page calls it.
     *
     * @return list<Change>
     */
    public static function forEndpoint(ApiSpec $spec, Endpoint $endpoint): array
    {
        $previous = self::previousVersion($spec, $endpoint->version);

        if ($previous === null) {
            return [];
        }

        $before = self::operations($spec, $previous)[Versions::operationKey($endpoint)] ?? null;

        return $before === null ? [] : SpecDiff::operation($before, $endpoint);
    }

    /**
     * The version served immediately before this one.
     *
     * `ApiSpec::$versions` is newest first, so the previous version is the
     * next entry along - read from the catalogue rather than by sorting the
     * names here, so ordering stays the one thing `Support\Versions` decides.
     */
    public static function previousVersion(ApiSpec $spec, ?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $names = array_map(static fn ($candidate): string => $candidate->name, $spec->versions);
        $index = array_search($version, $names, true);

        if ($index === false) {
            return null;
        }

        return $names[$index + 1] ?? null;
    }

    /**
     * One version's operations, keyed the way versions of one operation find
     * each other.
     *
     * @return array<string, Endpoint>
     */
    private static function operations(ApiSpec $spec, string $version): array
    {
        $operations = [];

        foreach ($spec->endpointsIn($version) as $endpoint) {
            $operations[Versions::operationKey($endpoint)] = $endpoint;
        }

        return $operations;
    }
}
