<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Schema;

/**
 * Documentation-only shape of an order, of the kind an API with no resources
 * keeps beside its controllers.
 */
class OrderShape
{
    /** The order's own identifier. */
    public int $id;

    public string $reference;

    /** Null until the order is paid. */
    public ?float $paid_total;

    public MoneyShape $amount;

    /**
     * One entry per line item.
     *
     * @var list<MoneyShape>
     */
    public array $lines;

    /** @var string[] Free-form tags. */
    public array $tags;

    /** Untyped on purpose: the reader must not invent one. */
    public $anything;

    public static string $ignored = 'static properties are not fields';

    protected string $hidden = 'nor are non-public ones';
}
