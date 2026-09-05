<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;

/**
 * The sections an endpoint page is built from, and the anchor of each one.
 *
 * Two things need this and must agree: the page renders the headings, and the
 * contents beside it links to them. Deriving both from one place is what keeps
 * a table of contents from pointing at anchors that are not there.
 *
 * The anchors are prefixed with the endpoint's own slug because the same
 * markup renders inline for every endpoint at runtime, where a bare
 * `#responses` would appear twenty times on one page. They are a stability
 * contract like the endpoint id itself: `#users-index-responses` is what a
 * search result deep-links to and what a model cites.
 */
final class Outline
{
    /**
     * Every section the endpoint actually has, in the order the page renders
     * them.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public static function forEndpoint(Endpoint $endpoint): array
    {
        $sections = [];

        foreach (ParameterLocation::cases() as $location) {
            if ($endpoint->parametersIn($location) !== []) {
                $sections[] = self::section($endpoint, self::parameterHeading($location));
            }
        }

        $sections[] = self::section($endpoint, 'Example request');

        if ($endpoint->responses !== []) {
            $sections[] = self::section($endpoint, 'Responses');
        }

        return $sections;
    }

    public static function parameterHeading(ParameterLocation $location): string
    {
        return ucfirst($location->value).' parameters';
    }

    public static function id(Endpoint $endpoint, string $heading): string
    {
        return $endpoint->slug().'-'.Str::slug($heading);
    }

    /**
     * @return array{level: int, text: string, id: string}
     */
    private static function section(Endpoint $endpoint, string $heading): array
    {
        // Level 2 throughout: on its own page these are the only sections
        // under the title, and the contents renders them as one flat list.
        return ['level' => 2, 'text' => $heading, 'id' => self::id($endpoint, $heading)];
    }
}
