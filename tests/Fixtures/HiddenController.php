<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

use Lusen\Attributes\Hidden;

#[Hidden]
final class HiddenController
{
    public function index(): void {}
}
