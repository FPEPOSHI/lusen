<?php

declare(strict_types=1);

use Lusen\Support\Str;

it('titles camel, snake and kebab case the same way', function (string $input): void {
    expect(Str::title($input))->toBe('Show User Profile');
})->with(['showUserProfile', 'show_user_profile', 'show-user-profile']);

it('slugs deterministically so anchors stay citable', function (): void {
    expect(Str::slug('Users / Accounts!'))->toBe('users-accounts')
        ->and(Str::slug('users.index'))->toBe('users-index');
});

it('leaves a short summary untouched', function (): void {
    expect(Str::summarise('A short line.'))->toBe('A short line.');
});

it('truncates on a word boundary and marks the cut', function (): void {
    $summary = Str::summarise(str_repeat('word ', 60));

    expect(mb_strlen($summary))->toBeLessThanOrEqual(155)
        ->and($summary)->toEndWith('…')
        ->and($summary)->not->toContain('wor…');
});

it('flattens whitespace and strips tags for meta descriptions', function (): void {
    expect(Str::summarise("<p>Two\n\n  lines</p>"))->toBe('Two lines');
});
