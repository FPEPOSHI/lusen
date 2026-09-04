<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Schema;

class MoneyShape
{
    /** ISO 4217 currency code. */
    public string $currency;

    public float $gross;
}
