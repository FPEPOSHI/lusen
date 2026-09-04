<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Excludes an action, or a whole controller, from the documentation.
 *
 * Prefer this over config-file URI patterns: it lives next to the code, so it
 * survives refactors and route renames.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Hidden {}
