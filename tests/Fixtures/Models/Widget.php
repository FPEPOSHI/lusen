<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Lusen\Tests\Fixtures\Requests\OrderStatus;

final class Widget extends Model
{
    /**
     * Casts spelled the Laravel 11 way, so the reader has to find the method
     * rather than only the property.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'settings' => 'array',
            'weight' => 'decimal:2',
            'featured' => 'boolean',
            'status' => OrderStatus::class,
        ];
    }
}
