<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Schema;

/** Refers to itself, which the reader has to survive. */
class CyclicShape
{
    public string $name;

    public ?CyclicShape $parent;
}
