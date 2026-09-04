<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\DocumentedController;
use Lusen\Tests\Fixtures\PlainController;

function docsSpec()
{
    return app(SpecBuilder::class)->build();
}

beforeEach(function (): void {
    Route::get('api/documented', [DocumentedController::class, 'index'])->name('c.index');
    Route::get('api/boilerplate', [DocumentedController::class, 'boilerplate'])->name('c.boilerplate');
    Route::get('api/boilerplate-body', [DocumentedController::class, 'boilerplateWithBody'])->name('c.body');
    Route::middleware('auth:sanctum')->post('api/archive', [DocumentedController::class, 'archive'])->name('c.archive');
    Route::middleware('auth:sanctum')->get('api/profile', [DocumentedController::class, 'publicProfile'])->name('c.profile');
    Route::get('api/internal', [DocumentedController::class, 'internal'])->name('c.internal');
    Route::get('api/customers', [DocumentedController::class, 'undocumented'])->name('c.undocumented');

    Route::get('api/invoices', [PlainController::class, 'index'])->name('p.index');
    Route::post('api/invoices', [PlainController::class, 'store'])->name('p.store');
    Route::get('api/invoices/{invoice}', [PlainController::class, 'show'])->name('p.show');
    Route::put('api/invoices/{invoice}', [PlainController::class, 'update'])->name('p.update');
    Route::delete('api/invoices/{invoice}', [PlainController::class, 'destroy'])->name('p.destroy');
    Route::post('api/invoices/{invoice}/restore', [PlainController::class, 'restore'])->name('p.restore');
    Route::get('api/entries', [PlainController::class, 'index'])->name('p.entries');
});

it('takes the summary and description from the method docblock', function (): void {
    $endpoint = docsSpec()->endpoint('c.index');

    expect($endpoint?->summary)->toBe('List every customer')
        // A soft break, kept as written. Markdown renders it as a space, so
        // the page is unchanged while a list or a fence survives intact.
        ->and($endpoint?->description)->toBe("Returns a paginated list, newest first.\nUse the status filter to narrow it.");
});

it('takes the group from the class docblock', function (): void {
    expect(docsSpec()->endpoint('c.index')?->group)->toBe('Customers');
});

it('lets a method group override the class group', function (): void {
    expect(docsSpec()->endpoint('c.archive')?->group)->toBe('Archival');
});

it('reads the deprecated tag', function (): void {
    expect(docsSpec()->endpoint('c.archive')?->deprecated)->toBeTrue()
        ->and(docsSpec()->endpoint('c.index')?->deprecated)->toBeFalse();
});

it('lets unauthenticated override what the middleware implied', function (): void {
    // The route carries auth:sanctum, but the author says otherwise.
    expect(docsSpec()->endpoint('c.profile')?->authenticated)->toBeFalse()
        ->and(docsSpec()->endpoint('c.archive')?->authenticated)->toBeTrue();
});

it('drops an endpoint tagged ignore', function (): void {
    expect(docsSpec()->endpoint('c.internal'))->toBeNull();
});

it('ignores laravel scaffold boilerplate', function (): void {
    // "Display a listing of the resource" is identical in every project, so
    // lifting it would give many endpoints the same summary and the same meta
    // description.
    expect(docsSpec()->endpoint('c.boilerplate')?->summary)->not->toContain('listing of the resource');
});

it('keeps a real description sitting under a boilerplate summary', function (): void {
    expect(docsSpec()->endpoint('c.body')?->description)
        ->toBe("Includes the customer's most recent orders.");
});

it('falls back to resource-action wording when there is no docblock', function (string $id, string $summary): void {
    // "Index" tells a reader nothing; "List invoices" is the sentence a person
    // would have written.
    expect(docsSpec()->endpoint($id)?->summary)->toBe($summary);
})->with([
    ['p.index', 'List invoices'],
    ['p.store', 'Create an invoice'],
    ['p.show', 'Retrieve an invoice'],
    ['p.update', 'Update an invoice'],
    ['p.destroy', 'Delete an invoice'],
]);

it('picks the article that reads correctly', function (): void {
    expect(docsSpec()->endpoint('p.store')?->summary)->toBe('Create an invoice')
        ->and(docsSpec()->endpoint('p.entries')?->summary)->toBe('List entries');
});

it('singularises the group for a single-record action', function (): void {
    // "entries" -> "entry", not "entrie".
    Route::get('api/entries/{entry}', [PlainController::class, 'show'])->name('p.entry');

    expect(docsSpec()->endpoint('p.entry')?->summary)->toBe('Retrieve an entry');
});

it('falls back to the action name for an action with no convention', function (): void {
    expect(docsSpec()->endpoint('p.restore')?->summary)->toBe('Restore');
});

it('does not leave any endpoint without a summary', function (): void {
    foreach (docsSpec()->endpoints() as $endpoint) {
        expect($endpoint->summary)->not->toBeNull()->not->toBe('');
    }
});
