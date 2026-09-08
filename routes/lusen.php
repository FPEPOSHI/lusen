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

    // The Markdown twin of the index, at the URL a model constructs by adding
    // `.md` to the page it is on, as it can for every page below.
    Route::get("{$path}.md", [DocsController::class, 'markdown'])->name('lusen.markdown');

    Route::get("{$path}/openapi.json", [DocsController::class, 'openapi'])->name('lusen.openapi');
    Route::get("{$path}/spec.json", [DocsController::class, 'specDocument'])->name('lusen.spec');
    Route::get("{$path}/postman.json", [DocsController::class, 'postman'])->name('lusen.postman');
    Route::get("{$path}/search-index.json", [DocsController::class, 'searchIndex'])->name('lusen.search');
    Route::get("{$path}/llms.txt", [DocsController::class, 'llms'])->name('lusen.llms');
    Route::get("{$path}/llms-full.txt", [DocsController::class, 'llmsFull'])->name('lusen.llms-full');

    // Every page's Markdown twin. Static output writes these as files; here
    // they are rendered on request, at the same paths, so llms.txt and the
    // links between pages are true in both modes.
    Route::get("{$path}/endpoints/{slug}.md", [DocsController::class, 'endpointMarkdown'])
        ->where('slug', '[a-z0-9-]+')->name('lusen.endpoint.markdown');
    Route::get("{$path}/groups/{slug}.md", [DocsController::class, 'groupMarkdown'])
        ->where('slug', '[a-z0-9-]+')->name('lusen.group.markdown');
    Route::get("{$path}/pages/{slug}.md", [DocsController::class, 'pageMarkdown'])
        ->where('slug', '[a-z0-9-]+')->name('lusen.page.markdown');

    Route::get('.well-known/api-docs', [DocsController::class, 'discovery'])->name('lusen.discovery');
});
