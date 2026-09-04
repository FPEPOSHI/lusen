<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
