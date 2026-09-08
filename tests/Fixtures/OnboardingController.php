<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\ApiDoc;
use Lusen\Attributes\ApiGroup;

/**
 * A group whose operations are the steps of a sequence.
 *
 * Alphabetically the group opens on "Add a bank account" and closes on
 * "Register the company", which is the flow backwards. The orders here are
 * deliberately not the path order and deliberately not contiguous: a reader
 * who inserts a step later should not have to renumber the ones after it.
 */
#[ApiGroup('Onboarding', <<<'DESC'
Everything a new company does before its first invoice.

**The order matters:**

1. Register the company.
2. Upload the certificate.
3. Add a bank account.
DESC, order: 1)]
final class OnboardingController
{
    #[ApiDoc(summary: 'Register the company', order: 10)]
    public function register(): void {}

    #[ApiDoc(summary: 'Upload the certificate', order: 20)]
    public function certificate(): void {}

    #[ApiDoc(summary: 'Add a bank account', order: 30)]
    public function bankAccount(): void {}

    /**
     * Deliberately unordered: it belongs to the group without belonging to the
     * sequence, and has to land after the steps rather than in the middle.
     */
    #[ApiDoc(summary: 'Check the service is up')]
    public function ping(): void {}
}
