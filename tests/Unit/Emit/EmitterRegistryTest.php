<?php

declare(strict_types=1);

use Lusen\Emit\Contracts\Emitter;
use Lusen\Emit\EmitterRegistry;
use Lusen\Emit\LlmsTxtEmitter;
use Lusen\Emit\MarkdownEmitter;
use Lusen\Emit\OpenApiEmitter;
use Lusen\Ir\ApiSpec;

it('resolves enabled emitters in config order', function (): void {
    $registry = new EmitterRegistry(['emitters' => ['llms', 'openapi']]);

    expect($registry->enabled())->toHaveCount(2)
        ->and($registry->enabled()[0])->toBeInstanceOf(LlmsTxtEmitter::class)
        ->and($registry->enabled()[1])->toBeInstanceOf(OpenApiEmitter::class);
});

it('skips names with no implementation instead of failing the build', function (): void {
    $registry = new EmitterRegistry(['emitters' => ['openapi', 'not-built-yet']]);

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->missing())->toBe(['not-built-yet']);
});

it('resolves nothing when no emitters are enabled', function (): void {
    expect((new EmitterRegistry)->enabled())->toBe([]);
});

it('accepts a host-registered emitter', function (): void {
    $registry = new EmitterRegistry(['emitters' => ['custom']]);

    $registry->extend('custom', fn (): Emitter => new class implements Emitter
    {
        public function name(): string
        {
            return 'custom';
        }

        public function emit(ApiSpec $spec): array
        {
            return [];
        }
    });

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->missing())->toBe([]);
});

it('passes the configured docs url to the llms emitter', function (): void {
    $registry = new EmitterRegistry(['emitters' => ['llms'], 'url' => '/api-docs']);

    /** @var LlmsTxtEmitter $emitter */
    $emitter = $registry->enabled()[0];

    expect($emitter->index(fixtureSpec()))->toContain('/api-docs/openapi.json');
});

it('resolves the markdown emitter', function (): void {
    $registry = new EmitterRegistry(['emitters' => ['markdown']]);

    expect($registry->enabled()[0])->toBeInstanceOf(MarkdownEmitter::class)
        ->and($registry->missing())->toBe([]);
});
