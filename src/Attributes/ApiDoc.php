<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Documents a controller action, or supplies defaults for every action on a
 * controller when placed on the class.
 *
 * Attributes are the escape hatch from inference and always win: anything set
 * here overrides what the extractors worked out. Leave a property null to keep
 * the inferred value.
 *
 *     #[ApiDoc(
 *         summary: 'List users',
 *         description: 'Paginated list of every user in the account.',
 *         group: 'Users',
 *     )]
 *     public function index(): AnonymousResourceCollection
 *
 * `version` is how a header-versioned API says so. Lusen reads a version out of
 * a `/api/v2/…` path on its own; an API that negotiates its version in an
 * `Accept` header leaves nothing to read, so the controller has to say.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ApiDoc
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $group = null,
        public ?bool $authenticated = null,
        public ?bool $deprecated = null,
        public array $tags = [],
        public ?string $version = null,
    ) {}
}
