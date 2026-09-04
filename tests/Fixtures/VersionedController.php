<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\ApiDoc;
use Lusen\Attributes\ApiGroup;

/**
 * A controller whose version is declared rather than derivable.
 *
 * Its routes carry no version in their paths, which is the shape of an API
 * that negotiates the version in a header - the one case Lusen cannot work out
 * on its own.
 */
#[ApiGroup('Reports')]
#[ApiDoc(version: 'v9')]
final class VersionedController
{
    #[ApiDoc(summary: 'List reports')]
    public function index(): void {}
}
