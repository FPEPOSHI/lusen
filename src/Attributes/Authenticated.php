<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Forces the authenticated flag on, for endpoints whose credential
 * requirement is not visible in the middleware stack.
 *
 * Pass false to force it off - useful for a route inside an authenticated
 * group that is deliberately public.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Authenticated
{
    public function __construct(public bool $required = true) {}
}
