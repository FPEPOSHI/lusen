<?php

declare(strict_types=1);

use Lusen\Diff\Baseline;
use Lusen\Diff\SpecDiff;
use Lusen\Ir\Group;

it('round-trips a spec through the file', function (): void {
    $spec = fixtureSpec();

    $restored = Baseline::decode(Baseline::encode($spec));

    // The point of recording the IR rather than a format of its own: a
    // baseline read back has to diff clean against the build that wrote it.
    expect(SpecDiff::between($restored, $spec))->toBe([])
        ->and($restored->title)->toBe($spec->title)
        ->and(count($restored->endpoints()))->toBe(count($spec->endpoints()));
});

it('drops the absolute paths that would tie it to one machine', function (): void {
    $spec = fixtureSpec();
    $endpoint = $spec->endpoints()[0];

    $withSources = $spec->withGroups([
        new Group('Users', [$endpoint->with(sourceFiles: ['/Users/someone/app/Http/Controllers/UserController.php'])]),
    ]);

    expect(Baseline::encode($withSources))
        ->not->toContain('sourceFiles')
        ->not->toContain('/Users/someone');
});

it('writes something a person can read in a pull request', function (): void {
    $encoded = Baseline::encode(fixtureSpec());

    // Pretty-printed, slashes unescaped and newline-terminated: this file
    // gets committed, so its diff is read by people.
    expect($encoded)->toContain("\n    \"title\"")
        ->and($encoded)->toContain('api/users')
        ->and($encoded)->not->toContain('api\/users')
        ->and(str_ends_with($encoded, "\n"))->toBeTrue();
});
