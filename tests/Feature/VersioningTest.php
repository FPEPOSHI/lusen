<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Emit\Contracts\Renderer;
use Lusen\Emit\HtmlEmitter;
use Lusen\Ir\ApiSpec;
use Lusen\SpecBuilder;
use Lusen\Support\Links;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\UserController;
use Lusen\Tests\Fixtures\VersionedController;

function versionedApi(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

/**
 * Real Blade, so the views are under test rather than the emitter's
 * bookkeeping about them.
 */
function versionedHtml(string $id): string
{
    $spec = versionedApi();

    $emitter = new HtmlEmitter(
        app(Renderer::class),
        new Links('/docs', static: true, canonicalOrigin: 'https://example.com'),
    );

    return $emitter->endpoint($spec->endpoint($id), $spec);
}

beforeEach(function (): void {
    // The same operation in both versions, plus one endpoint that only v1 has
    // and one that only v2 has.
    Route::get('api/v1/users', [UserController::class, 'index'])->name('v1.users.index');
    Route::get('api/v1/exports', [OrderController::class, 'bare'])->name('v1.exports.index');
    Route::get('api/v2/users', [UserController::class, 'index'])->name('v2.users.index');
    Route::get('api/v2/orders', [OrderController::class, 'index'])->name('v2.orders.index');
});

it('finds every version the routes serve, newest first', function (): void {
    $versions = versionedApi()->versions;

    expect(array_map(fn ($v): string => $v->name, $versions))->toBe(['v2', 'v1'])
        ->and($versions[0]->current)->toBeTrue();
});

it('files each endpoint under the version its path names', function (): void {
    $spec = versionedApi();

    expect($spec->endpoint('v1.users.index')?->version)->toBe('v1')
        ->and($spec->endpoint('v2.users.index')?->version)->toBe('v2');
});

it('points an older endpoint at its newer edition', function (): void {
    expect(versionedApi()->endpoint('v1.users.index')?->supersededBy)->toBe('v2.users.index');
});

it('leaves the current version pointing nowhere', function (): void {
    expect(versionedApi()->endpoint('v2.users.index')?->supersededBy)->toBeNull();
});

it('points nowhere when the operation was not carried over', function (): void {
    expect(versionedApi()->endpoint('v1.exports.index')?->supersededBy)->toBeNull();
});

it('groups by version, newest version first', function (): void {
    $groups = array_map(
        fn ($group): string => $group->displayName(),
        versionedApi()->groups,
    );

    expect($groups)->toBe(['Orders (v2)', 'Users (v2)', 'Exports (v1)', 'Users (v1)']);
});

it('gives each version of a group its own anchor', function (): void {
    $slugs = array_map(fn ($group): string => $group->slug(), versionedApi()->groups);

    expect($slugs)->toContain('v2-users')->toContain('v1-users');
});

it('never invents a group called V4', function (): void {
    // The old skip list stopped at v3, so a fourth version took its own name
    // as the group for every endpoint under it.
    Route::get('api/v4/widgets', [OrderController::class, 'bare'])->name('v4.widgets.index');

    expect(versionedApi()->endpoint('v4.widgets.index')?->group)->toBe('Widgets');
});

it('deprecates every endpoint in a version configured as deprecated', function (): void {
    config()->set('lusen.versions.deprecated', ['v1' => '2026-06-01']);

    $spec = versionedApi();

    expect($spec->endpoint('v1.users.index')?->deprecated)->toBeTrue()
        ->and($spec->endpoint('v2.users.index')?->deprecated)->toBeFalse()
        ->and($spec->apiVersion('v1')?->sunset)->toBe('2026-06-01');
});

it('does not recommend a version the author has not made current', function (): void {
    // v2 is present but pinned as not-the-one-to-use, so v1 readers are not
    // sent to it.
    config()->set('lusen.versions.current', 'v1');

    $spec = versionedApi();

    expect($spec->currentVersion()?->name)->toBe('v1')
        ->and($spec->endpoint('v1.users.index')?->supersededBy)->toBeNull();
});

it('documents nothing as versioned when detection is off', function (): void {
    config()->set('lusen.versions.enabled', false);

    $spec = versionedApi();

    expect($spec->versions)->toBe([])
        ->and($spec->isVersioned())->toBeFalse()
        ->and($spec->endpoint('v1.users.index')?->version)->toBeNull()
        ->and($spec->groups[0]->version)->toBeNull();
});

it('takes a declared version when the path has none', function (): void {
    Route::get('api/reports', [VersionedController::class, 'index'])->name('reports.index');

    expect(versionedApi()->endpoint('reports.index')?->version)->toBe('v9');
});

it('leaves a single-version api scoped exactly as it was', function (): void {
    // One version is not worth disambiguating: the groups, their anchors and
    // their names must come out identical to an unversioned API's.
    config()->set('lusen.routes.exclude', ['api/v2/*']);

    $spec = versionedApi();

    expect($spec->isVersioned())->toBeFalse()
        ->and(array_map(fn ($group): string => $group->displayName(), $spec->groups))->toBe(['Exports', 'Users'])
        ->and($spec->groups[1]->slug())->toBe('users')
        ->and($spec->groups[1]->version)->toBeNull()
        // The endpoint still knows which version it serves; only the
        // presentation stops mentioning it.
        ->and($spec->endpoint('v1.users.index')?->version)->toBe('v1');
});

it('badges the version on the endpoint page', function (): void {
    expect(versionedHtml('v1.users.index'))->toMatch('/<span[^>]*>\s*v1\s*<\/span>/');
});

it('sends a reader on an old page to the newer one', function (): void {
    expect(versionedHtml('v1.users.index'))
        ->toContain('A newer version of this operation is available')
        ->toContain('href="/docs/endpoints/v2-users-index.html"')
        ->toContain('GET /api/v2/users');
});

it('leaves the current version without a notice to chase', function (): void {
    expect(versionedHtml('v2.users.index'))->not->toContain('A newer version of this operation');
});

it('breadcrumbs to the group of the right version', function (): void {
    // The group anchor carries the version, so a breadcrumb rebuilt from the
    // group name alone would point at a section that is not there.
    expect(versionedHtml('v1.users.index'))
        ->toContain('href="/docs/index.html#v1-users"')
        ->toContain('>Users (v1)</a>');
});

it('heads each run of sidebar groups with its version and standing', function (): void {
    config()->set('lusen.versions.deprecated', ['v1']);

    $html = versionedHtml('v1.users.index');

    // One heading per version, not a "(v1)" suffix on every group label.
    expect($html)->toMatch('/<span[^>]*>\s*v2\s*<\/span>/')
        ->toMatch('/<span[^>]*>\s*v1 \(deprecated\)\s*<\/span>/');
});

it('keeps the heading outline intact on a versioned page', function (): void {
    preg_match_all('/<h([1-6])/', versionedHtml('v1.users.index'), $matches);

    $levels = array_map(intval(...), $matches[1]);
    $previous = 0;

    foreach ($levels as $level) {
        // A skipped level is a real accessibility defect, and the version
        // notice sits between the badges and the first heading.
        expect($level)->toBeLessThanOrEqual($previous + 1);
        $previous = $level;
    }

    expect($levels[0])->toBe(1);
});
