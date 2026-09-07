<?php

declare(strict_types=1);

use Lusen\Support\Product;

it('renders nothing at all until something is configured', function (): void {
    // A docs site that grew a signup banner because it was upgraded would be
    // a nasty surprise, so every one of these is off until filled in.
    foreach ([null, [], ['banner' => [], 'action' => []], 'nonsense'] as $config) {
        expect(Product::banner($config))->toBeNull()
            ->and(Product::action($config))->toBeNull()
            ->and(Product::site($config))->toBeNull()
            ->and(Product::any($config))->toBeFalse();
    }
});

it('reads an announcement with no link as an announcement', function (): void {
    $banner = Product::banner(['banner' => ['text' => 'v2 is live.']]);

    expect($banner)->toBe(['text' => 'v2 is live.', 'label' => null, 'url' => null, 'dismissible' => true]);
});

it('carries a link on the banner when both halves are there', function (): void {
    $banner = Product::banner(['banner' => [
        'text' => 'v2 is live.',
        'label' => 'See what changed',
        'url' => 'https://example.com/blog/v2',
    ]]);

    expect($banner['label'])->toBe('See what changed')
        ->and($banner['url'])->toBe('https://example.com/blog/v2');
});

it('drops a half-configured link rather than rendering a dead button', function (): void {
    // A button with no destination does nothing; one with no label cannot be
    // read. Neither is worth putting on every page.
    $withoutUrl = Product::banner(['banner' => ['text' => 'Hello', 'label' => 'Press me']]);
    $withoutLabel = Product::banner(['banner' => ['text' => 'Hello', 'url' => 'https://example.com']]);

    expect($withoutUrl['url'])->toBeNull()
        ->and($withoutUrl['label'])->toBeNull()
        ->and($withoutLabel['url'])->toBeNull();

    expect(Product::action(['action' => ['label' => 'Get an API key']]))->toBeNull()
        ->and(Product::action(['action' => ['url' => 'https://example.com/register']]))->toBeNull();
});

it('keeps a banner undismissable when the site says so', function (): void {
    expect(Product::banner(['banner' => ['text' => 'Scheduled maintenance.', 'dismissible' => false]])['dismissible'])
        ->toBeFalse();
});

it('reads the call to action, note and all', function (): void {
    $action = Product::action(['action' => [
        'label' => 'Get an API key',
        'url' => 'https://example.com/register',
        'note' => 'Free while you are building.',
    ]]);

    expect($action)->toBe([
        'label' => 'Get an API key',
        'url' => 'https://example.com/register',
        'note' => 'Free while you are building.',
    ]);
});

it('labels the way back to the site with the host when nothing is named', function (): void {
    // "Website" tells a reader nothing they could not already guess.
    expect(Product::site(['url' => 'https://www.acme.example/pricing']))
        ->toBe(['name' => 'acme.example', 'url' => 'https://www.acme.example/pricing']);

    expect(Product::site(['name' => 'Acme', 'url' => 'https://acme.example']))
        ->toBe(['name' => 'Acme', 'url' => 'https://acme.example']);
});

it('ignores whitespace somebody left in the config', function (): void {
    expect(Product::banner(['banner' => ['text' => '   ']]))->toBeNull()
        ->and(Product::action(['action' => ['label' => ' Sign up ', 'url' => ' https://example.com ']]))
        ->toBe(['label' => 'Sign up', 'url' => 'https://example.com', 'note' => null]);
});

it('says when anything is configured, so a view can skip the lot', function (): void {
    expect(Product::any(['url' => 'https://acme.example']))->toBeTrue()
        ->and(Product::any(['banner' => ['text' => 'Hi']]))->toBeTrue()
        ->and(Product::any(['action' => ['label' => 'Go', 'url' => 'https://x.example']]))->toBeTrue();
});
