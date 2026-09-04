<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

/**
 * What a route's `throttle` middleware allows.
 *
 * Rate limits are one of the few operational facts a Laravel application
 * states precisely and almost never documents. The number is sitting in the
 * middleware stack; surfacing it costs the author nothing and answers a
 * question every integrator eventually asks under production load.
 *
 * A named limiter (`throttle:api`) resolves its numbers at runtime through a
 * closure, so only the name is knowable statically. Reporting the name alone
 * is honest; inventing a number would not be.
 */
final readonly class RateLimit
{
    public function __construct(
        public ?int $maxAttempts = null,
        public int $perMinutes = 1,
        public ?string $limiter = null,
    ) {}

    /**
     * Reads one middleware string, e.g. `throttle:60,1` or `throttle:api`.
     */
    public static function fromMiddleware(string $middleware): ?self
    {
        if ($middleware === 'throttle') {
            return new self(limiter: 'default');
        }

        if (! str_starts_with($middleware, 'throttle:')) {
            return null;
        }

        $arguments = explode(',', substr($middleware, 9));
        $first = trim($arguments[0]);

        if ($first === '') {
            return null;
        }

        // `throttle:api` - a named limiter whose numbers live in a closure.
        if (! ctype_digit($first)) {
            return new self(limiter: $first);
        }

        $per = isset($arguments[1]) && ctype_digit(trim($arguments[1]))
            ? (int) trim($arguments[1])
            : 1;

        return new self(maxAttempts: (int) $first, perMinutes: max(1, $per));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $max = Data::int($data, 'maxAttempts');

        return new self(
            maxAttempts: $max > 0 ? $max : null,
            perMinutes: max(1, Data::int($data, 'perMinutes', 1)),
            limiter: Data::nullableString($data, 'limiter'),
        );
    }

    public function isNamed(): bool
    {
        return $this->maxAttempts === null;
    }

    /**
     * "60 requests per minute", "1000 requests per 60 minutes", or the
     * limiter's name when the numbers are not statically knowable.
     */
    public function label(): string
    {
        if ($this->maxAttempts === null) {
            return $this->limiter === null
                ? 'Rate limited'
                : "Rate limited by the `{$this->limiter}` limiter";
        }

        $window = $this->perMinutes === 1 ? 'minute' : "{$this->perMinutes} minutes";

        return "{$this->maxAttempts} requests per {$window}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'maxAttempts' => $this->maxAttempts,
            'perMinutes' => $this->maxAttempts === null ? null : $this->perMinutes,
            'limiter' => $this->limiter,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
