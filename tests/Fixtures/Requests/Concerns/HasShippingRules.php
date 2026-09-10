<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests\Concerns;

/**
 * Rules shared through a trait, which is how an application stops writing the
 * same twelve lines into six requests. Nothing here is in the file that
 * declares `rules()`, so a reader that only looks at that file reports the
 * request as having no shipping fields at all.
 */
trait HasShippingRules
{
    public const CARRIERS = ['dhl', 'ups', 'royal-mail'];

    /**
     * @return array<string, mixed>
     */
    protected function shippingRules(string $unreadable): array
    {
        return [
            /**
             * Who is carrying it.
             *
             * @example dhl
             */
            'carrier' => 'required|string|in:'.implode(',', self::CARRIERS),
            'parcels' => 'required|integer|max:'.self::MAX_PARCELS,
            // The tail is built from an argument, so `in:` can never be
            // completed. The rules before it are still true and are kept.
            'service' => 'required|string|in:'.$unreadable,
        ];
    }
}
