<?php

declare(strict_types=1);

namespace Lusen\Attributes;

use Attribute;

/**
 * Names the group a controller's endpoints belong to, and optionally
 * describes it. The description becomes the group's landing-page copy, which
 * is the page most likely to be surfaced for a broad query - write it for a
 * reader who has never seen the API.
 *
 *     #[ApiGroup('Users', 'Create, read and deactivate user accounts.')]
 *     final class UserController extends Controller
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ApiGroup
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
