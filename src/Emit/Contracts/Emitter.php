<?php

declare(strict_types=1);

namespace Lusen\Emit\Contracts;

use Lusen\Emit\EmittedFile;
use Lusen\Ir\ApiSpec;

/**
 * Produces one output surface from the spec.
 *
 * The hard rule for this namespace: an emitter reads the ApiSpec and nothing
 * else. No Route facade, no reflection, no container, no filesystem. That is
 * what makes the surfaces independent - adding a format is a new class here
 * and one config entry, and it can never change what another format emits.
 */
interface Emitter
{
    /**
     * Config key that enables this emitter, e.g. 'openapi'.
     */
    public function name(): string;

    /**
     * @return list<EmittedFile>
     */
    public function emit(ApiSpec $spec): array;
}
