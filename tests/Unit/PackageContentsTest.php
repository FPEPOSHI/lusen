<?php

declare(strict_types=1);

use Lusen\Support\Assets;

/**
 * What a consumer actually receives.
 *
 * `.gitattributes` decides this: GitHub builds the dist tarball Composer
 * downloads with `git archive`, which honours `export-ignore`. A path the code
 * reads at runtime but the archive drops fails silently in every install and
 * never once in this repository, where the file is right there - which is the
 * worst shape a bug can have.
 */
function exportIgnoredPatterns(): array
{
    $lines = file(packageRoot().'/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    $patterns = [];

    foreach ($lines as $line) {
        if (! str_contains($line, 'export-ignore')) {
            continue;
        }

        $fields = preg_split('/\s+/', trim($line)) ?: [];
        $patterns[] = '/'.trim($fields[0] ?? '', '/');
    }

    return $patterns;
}

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<string>
 */
function shippedPaths(): array
{
    return [
        // Read by Support\Assets on every render.
        Assets::distPath(),
        Assets::jsPath(),
        // Registered by the service provider.
        packageRoot().'/resources/views',
        packageRoot().'/resources/stubs/pages',
        packageRoot().'/config/lusen.php',
        packageRoot().'/routes/lusen.php',
    ];
}

it('ships every path the package reads at runtime', function (): void {
    $patterns = exportIgnoredPatterns();

    expect($patterns)->not->toBeEmpty();

    foreach (shippedPaths() as $path) {
        $relative = '/'.ltrim(str_replace(packageRoot(), '', $path), '/');

        foreach ($patterns as $pattern) {
            expect(str_starts_with($relative, $pattern))->toBeFalse(
                "{$relative} is export-ignored by \"{$pattern}\", so it will be missing from an installed package"
            );
        }
    }
});

it('has every runtime path actually present in the repository', function (): void {
    foreach (shippedPaths() as $path) {
        expect(file_exists($path))->toBeTrue("{$path} does not exist");
    }
});

it('still keeps the build-only sources out of the package', function (): void {
    // The Tailwind source is not read at runtime - dist/lusen.css is - so it
    // stays out, along with the tests, the tooling and the showcase.
    expect(exportIgnoredPatterns())
        ->toContain('/resources/css')
        ->toContain('/tests')
        ->toContain('/tools')
        ->toContain('/docs');
});
