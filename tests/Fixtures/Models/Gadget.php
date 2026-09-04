<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Gadget extends Model
{
    protected $table = 'custom_gadgets';

    /**
     * The older property spelling.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'shipped_at' => 'datetime',
        'quantity' => 'integer',
    ];
}
