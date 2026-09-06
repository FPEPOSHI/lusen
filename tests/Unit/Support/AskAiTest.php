<?php

declare(strict_types=1);

use Lusen\Support\AskAi;

function askConfig(array $overrides = []): array
{
    return array_merge([
        'providers' => [
            'ChatGPT' => 'https://chatgpt.com/?q={prompt}',
            'Claude' => 'https://claude.ai/new?q={prompt}',
        ],
        'prompt' => 'Read {url} about {subject} in the {title}.',
    ], $overrides);
}

it('asks each configured assistant the same question', function (): void {
    $links = AskAi::links(askConfig(), 'https://docs.test/endpoints/users-index.md', 'GET /api/users', 'Acme API');

    expect(array_keys($links))->toBe(['ChatGPT', 'Claude'])
        ->and($links['ChatGPT'])->toStartWith('https://chatgpt.com/?q=')
        ->and($links['Claude'])->toStartWith('https://claude.ai/new?q=');
});

it('carries the page, the subject and the api in the prompt', function (): void {
    $links = AskAi::links(askConfig(), 'https://docs.test/endpoints/users-index.md', 'GET /api/users', 'Acme API');
    $prompt = rawurldecode(substr($links['ChatGPT'], strlen('https://chatgpt.com/?q=')));

    expect($prompt)->toBe('Read https://docs.test/endpoints/users-index.md about GET /api/users in the Acme API.');
});

it('encodes the prompt, so a question mark cannot end the query string', function (): void {
    $links = AskAi::links(askConfig(['prompt' => 'What is {subject}? Ask & see.']), 'https://docs.test/x.md', 'this', 'API');

    expect($links['ChatGPT'])->toContain('What%20is%20this%3F%20Ask%20%26%20see.')
        ->and($links['ChatGPT'])->not->toContain('? Ask & see');
});

it('says nothing without an address a model can fetch', function (): void {
    // The prompt asks a model to read a URL, and /docs/x.md is not one.
    expect(AskAi::links(askConfig(), null, 'GET /api/users', 'Acme API'))->toBe([])
        ->and(AskAi::links(askConfig(), '', 'GET /api/users', 'Acme API'))->toBe([]);
});

it('is turned off by an empty provider list', function (): void {
    expect(AskAi::links(askConfig(['providers' => []]), 'https://docs.test/x.md', 'x', 'API'))->toBe([])
        ->and(AskAi::links(null, 'https://docs.test/x.md', 'x', 'API'))->toBe([]);
});

it('skips a template that has nowhere to put the prompt', function (): void {
    // Rather than opening an assistant with an empty box and no explanation.
    $links = AskAi::links(askConfig(['providers' => ['Broken' => 'https://example.test/']]), 'https://docs.test/x.md', 'x', 'API');

    expect($links)->toBe([]);
});

it('falls back to its own question when the template is blank', function (): void {
    $links = AskAi::links(askConfig(['prompt' => '']), 'https://docs.test/x.md', 'GET /x', 'Acme API');

    expect(rawurldecode($links['Claude']))->toContain('Read https://docs.test/x.md')
        ->toContain('GET /x')
        ->toContain('Acme API');
});
