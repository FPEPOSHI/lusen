<?php

declare(strict_types=1);

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Group;

function described(?string $description): Group
{
    return new Group('Onboarding', [Endpoint::make(HttpMethod::Get, 'onboarding')], $description);
}

it('has no lede when nobody wrote a description', function (): void {
    expect(described(null)->lede())->toBeNull();
});

it('takes the opening paragraph as the lede', function (): void {
    $group = described("Everything a new company does first.\n\n**Steps:**\n\n1. Register.\n2. Upload.");

    expect($group->lede())->toBe('Everything a new company does first.');
});

it('reads a wrapped paragraph as the one line it was written as', function (): void {
    // The newlines are the author's line wrapping, not structure.
    $group = described("Everything a new company\ndoes before its first\ninvoice.\n\nThen the steps.");

    expect($group->lede())->toBe('Everything a new company does before its first invoice.');
});

it('takes the whole of a description that is one paragraph', function (): void {
    expect(described('Clients, in the team\'s own words.')->lede())
        ->toBe('Clients, in the team\'s own words.');
});

it('summarises a group by its lede, not by its whole landing copy', function (): void {
    // The summary is a meta description and a search result; the rest of the
    // description would be truncated mid-workflow.
    $group = described("What this is for.\n\n1. A step nobody needs in a meta description.");

    expect($group->summary())->toBe('What this is for.');
});

it('still names the operations where nobody wrote a description', function (): void {
    $group = new Group('Users', [
        Endpoint::make(HttpMethod::Get, 'users')->with(summary: 'List users'),
        Endpoint::make(HttpMethod::Post, 'users')->with(summary: 'Create a user'),
    ]);

    expect($group->summary())->toBe('List users, Create a user.');
});
