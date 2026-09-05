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

it('reduces markdown to the text it renders as', function (): void {
    // A backtick renders as code on the page and as a backtick in a search
    // result, and the same string is used for both.
    expect(Str::plain('Send the `Idempotency-Key` header'))->toBe('Send the Idempotency-Key header')
        ->and(Str::plain('Reads **every** field and *some* others'))->toBe('Reads every field and some others')
        ->and(Str::plain('See [the guide](https://example.test/guide)'))->toBe('See the guide')
        ->and(Str::plain('See [the guide][guide]'))->toBe('See the guide')
        ->and(Str::plain('![A diagram](diagram.png) follows'))->toBe('A diagram follows');
});

it('leaves underscores alone, because identifiers use them', function (): void {
    expect(Str::plain('Filter by customer_id or created_at'))->toBe('Filter by customer_id or created_at');
});

it('flattens whitespace and html the same way it always did', function (): void {
    expect(Str::plain("Two\n\nparagraphs and <b>markup</b>"))->toBe('Two paragraphs and markup');
});

it('strips markdown before measuring the limit', function (): void {
    // Otherwise the backticks eat into the budget and the sentence is cut
    // shorter than it needed to be.
    expect(Str::summarise('`'.str_repeat('a', 150).'`', 155))->toBe(str_repeat('a', 150));
});
