<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Http\Controllers\DocsController;

/*
|--------------------------------------------------------------------------
| Lusen runtime documentation routes
|--------------------------------------------------------------------------
|
| Loaded only when `lusen.runtime.enabled` is true. The default deployment
| path is static output written by `php artisan lusen:build`, served as flat
| files with no PHP involved.
|
*/

$path = config('lusen.runtime.path', 'docs');
$path = is_string($path) ? trim($path, '/') : 'docs';

/** @var list<string> $middleware */
$middleware = config('lusen.runtime.middleware', ['web']);

Route::middleware($middleware)->group(function () use ($path): void {
    Route::get($path, [DocsController::class, 'index'])->name('lusen.index');
    Route::get("{$path}/openapi.json", [DocsController::class, 'openapi'])->name('lusen.openapi');
    Route::get("{$path}/llms.txt", [DocsController::class, 'llms'])->name('lusen.llms');
    Route::get("{$path}/llms-full.txt", [DocsController::class, 'llmsFull'])->name('lusen.llms-full');
    Route::get('.well-known/api-docs', [DocsController::class, 'discovery'])->name('lusen.discovery');
});
