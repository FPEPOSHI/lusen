<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Lusen\Tests\Fixtures\PlainController;
use Lusen\Tests\Fixtures\UserController;

it('reports an endpoint with nothing documented', function (): void {
    Route::get('api/bare', [PlainController::class, 'index'])->name('bare');

    $this->artisan('lusen:check')
        ->expectsOutputToContain('no description')
        ->expectsOutputToContain('missing documentation')
        ->assertSuccessful();
});

it('exits non-zero under --strict, so CI can fail on it', function (): void {
    Route::get('api/bare', [PlainController::class, 'index'])->name('bare');

    $this->artisan('lusen:check', ['--strict' => true])->assertFailed();
});

it('passes when everything is documented', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:check')
        ->expectsOutputToContain('are documented')
        ->assertSuccessful();
});

it('warns rather than failing when no routes match', function (): void {
    config()->set('lusen.routes.include', ['nothing/*']);

    $this->artisan('lusen:check', ['--strict' => true])
        ->expectsOutputToContain('No routes matched')
        ->assertSuccessful();
});

it('reports the same findings as json for a script to read', function (): void {
    Route::get('api/bare', [PlainController::class, 'index'])->name('bare');

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('lusen:check', ['--json' => true]))->toBe(0);

    expect(json_decode(Artisan::output(), true))->toBe([
        'endpoints' => 1,
        'documented' => 0,
        'findings' => [
            ['endpoint' => 'GET /api/bare', 'problems' => ['no description', 'no documented response']],
        ],
    ]);
});

it('still fails under --strict when reporting json', function (): void {
    Route::get('api/bare', [PlainController::class, 'index'])->name('bare');

    $this->artisan('lusen:check', ['--json' => true, '--strict' => true])->assertFailed();
});

it('reports clean json when everything is documented', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('lusen:check', ['--json' => true, '--strict' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true))
        ->toBe(['endpoints' => 1, 'documented' => 1, 'findings' => []]);
});
