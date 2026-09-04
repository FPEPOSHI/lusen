<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

/**
 * A resource controller with no documentation at all, which is the common
 * case in a codebase that never got round to it.
 */
final class PlainController
{
    public function index(): void {}

    public function store(): void {}

    public function show(): void {}

    public function update(): void {}

    public function destroy(): void {}

    public function restore(): void {}
}
