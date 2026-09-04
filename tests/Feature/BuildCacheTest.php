<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Build\BuildCache;
use Lusen\Collect\RouteCollector;
use Lusen\Extract\ExtractionPipeline;
use Lusen\Ir\Endpoint;
use Lusen\Support\Ast;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\Requests\StoreOrderRequest;
use Lusen\Tests\Fixtures\UserController;

/**
 * Runs a build against a throwaway controller file, so a source change can be
 * simulated by rewriting it.
 */
function cachePath(): string
{
    return test()->cacheDir.'/endpoints.json';
}

function buildWith(BuildCache $cache): array
{
    Ast::flushCache();

    $pipeline = new ExtractionPipeline(
        extractors: array_map(fn (string $class) => app($class), config('lusen.extractors')),
        cache: $cache,
    );

    $endpoints = $pipeline->run((new RouteCollector(app('router'), ['include' => ['api/*']]))->collect());

    return [$endpoints, $cache->stats()];
}

function newCache(bool $enabled = true): BuildCache
{
    return new BuildCache(cachePath(), 'test-key', $enabled);
}

beforeEach(function (): void {
    $this->cacheDir = sys_get_temp_dir().'/lusen-cache-'.bin2hex(random_bytes(4));
    mkdir($this->cacheDir, 0o755, true);

    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::post('api/orders', [OrderController::class, 'store'])->name('orders.store');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->cacheDir));
});

it('writes a cache file on the first build', function (): void {
    buildWith(newCache());

    expect(cachePath())->toBeFile();
});

it('misses everything on a cold build', function (): void {
    [, $stats] = buildWith(newCache());

    expect($stats['hits'])->toBe(0)
        ->and($stats['misses'])->toBe(2);
});

it('reuses every endpoint when nothing changed', function (): void {
    buildWith(newCache());
    [, $stats] = buildWith(newCache());

    expect($stats['hits'])->toBe(2)
        ->and($stats['misses'])->toBe(0);
});

it('produces identical output whether cached or not', function (): void {
    [$cold] = buildWith(newCache());
    [$warm] = buildWith(newCache());

    $toArray = fn (array $endpoints): array => array_map(fn (Endpoint $e): array => $e->toArray(), $endpoints);

    expect($toArray($warm))->toBe($toArray($cold));
});

it('re-analyses an endpoint whose source file changed', function (): void {
    buildWith(newCache());

    // Touch a file the orders endpoint was derived from.
    $request = (new ReflectionClass(StoreOrderRequest::class))->getFileName();
    $original = file_get_contents($request);
    file_put_contents($request, $original."\n// changed\n");

    try {
        [, $stats] = buildWith(newCache());
    } finally {
        file_put_contents($request, $original);
    }

    expect($stats['misses'])->toBe(1)
        ->and($stats['hits'])->toBe(1);
});

it('tracks files no extractor declared, like a form request', function (): void {
    // The recording spans the whole pipeline, so dependencies are exact rather
    // than whatever each extractor remembered to report.
    [$endpoints] = buildWith(newCache());

    $orders = array_values(array_filter($endpoints, fn (Endpoint $e): bool => $e->id === 'orders.store'));

    expect(implode(' ', $orders[0]->sourceFiles))
        ->toContain('StoreOrderRequest.php')
        ->toContain('OrderController.php');
});

it('re-analyses when the route itself changed', function (): void {
    // A renamed middleware changes the docs without touching a file.
    buildWith(newCache());

    app('router')->getRoutes()->refreshNameLookups();
    Route::middleware('auth:sanctum')->get('api/users', [UserController::class, 'index'])->name('users.index');

    [, $stats] = buildWith(newCache());

    expect($stats['misses'])->toBeGreaterThan(0);
});

it('ignores a cache written under a different key', function (): void {
    buildWith(newCache());

    [, $stats] = buildWith(new BuildCache(cachePath(), 'different-key'));

    expect($stats['hits'])->toBe(0);
});

it('survives a corrupt cache file', function (): void {
    buildWith(newCache());
    file_put_contents(cachePath(), '{not json');

    [$endpoints, $stats] = buildWith(newCache());

    // A corrupt cache is a slow build, never a failed one.
    expect($endpoints)->toHaveCount(2)
        ->and($stats['hits'])->toBe(0);
});

it('drops entries for endpoints that no longer exist', function (): void {
    buildWith(newCache());

    $before = strlen((string) file_get_contents(cachePath()));

    // Rebuild with only one route in scope.
    $pipeline = new ExtractionPipeline(
        extractors: array_map(fn (string $class) => app($class), config('lusen.extractors')),
        cache: newCache(),
    );
    $collector = new RouteCollector(app('router'), ['include' => ['api/users']]);
    $pipeline->run($collector->collect());

    expect(strlen((string) file_get_contents(cachePath())))->toBeLessThan($before);
});

it('does nothing at all when disabled', function (): void {
    buildWith(newCache(enabled: false));

    expect(file_exists(cachePath()))->toBeFalse();
});
