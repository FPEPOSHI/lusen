<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;

/**
 * The anchor of every section an endpoint page is built from.
 *
 * The headings are rendered in one place and named in another - `Outline` is
 * that one place, so a heading and the id a reader lands on cannot drift.
 *
 * The anchors are prefixed with the endpoint's own slug because the same
 * markup renders inline for every endpoint at runtime, where a bare
 * `#responses` would appear twenty times on one page. They are a stability
 * contract like the endpoint id itself: `#users-index-responses` is what a
 * search result deep-links to and what a model cites.
 */
final class Outline
{
    public static function parameterHeading(ParameterLocation $location): string
    {
        return ucfirst($location->value).' parameters';
    }

    public static function id(Endpoint $endpoint, string $heading): string
    {
        return $endpoint->slug().'-'.Str::slug($heading);
    }
}
