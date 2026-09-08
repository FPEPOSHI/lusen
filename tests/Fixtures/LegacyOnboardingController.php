<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\ApiGroup;

/**
 * One operation that a route files under someone else's group.
 *
 * The awkward case for a group's description: this controller describes only
 * its own corner, sorts first by path, and would otherwise speak for a group
 * whose other operations live elsewhere and say something else.
 */
#[ApiGroup('Onboarding', 'The one call left over from the old flow.', order: 1)]
final class LegacyOnboardingController
{
    public function legacyRegister(): void {}
}
