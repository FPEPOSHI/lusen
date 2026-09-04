<?php

declare(strict_types=1);

use Lusen\Ir\RateLimit;

it('reads attempts and window from throttle middleware', function (): void {
    $limit = RateLimit::fromMiddleware('throttle:60,1');

    expect($limit?->maxAttempts)->toBe(60)
        ->and($limit?->perMinutes)->toBe(1)
        ->and($limit?->label())->toBe('60 requests per minute');
});

it('handles a multi-minute window', function (): void {
    expect(RateLimit::fromMiddleware('throttle:1000,60')?->label())
        ->toBe('1000 requests per 60 minutes');
});

it('ignores a trailing prefix argument', function (): void {
    expect(RateLimit::fromMiddleware('throttle:5,1,login')?->maxAttempts)->toBe(5);
});

it('reports a named limiter by name rather than inventing a number', function (): void {
    // throttle:api resolves through a closure at runtime; the number is not
    // statically knowable and must not be guessed.
    $limit = RateLimit::fromMiddleware('throttle:api');

    expect($limit?->isNamed())->toBeTrue()
        ->and($limit?->maxAttempts)->toBeNull()
        ->and($limit?->label())->toBe('Rate limited by the `api` limiter');
});

it('handles bare throttle middleware', function (): void {
    expect(RateLimit::fromMiddleware('throttle')?->isNamed())->toBeTrue();
});

it('ignores middleware that is not throttling', function (): void {
    expect(RateLimit::fromMiddleware('auth:sanctum'))->toBeNull()
        ->and(RateLimit::fromMiddleware('web'))->toBeNull();
});

it('round-trips through an array', function (): void {
    $limit = new RateLimit(maxAttempts: 30, perMinutes: 5);

    expect(RateLimit::fromArray($limit->toArray())->label())->toBe($limit->label());
});

it('round-trips a named limiter', function (): void {
    $limit = new RateLimit(limiter: 'uploads');

    expect(RateLimit::fromArray($limit->toArray())->label())->toBe($limit->label());
});
