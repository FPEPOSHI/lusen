<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\PlainController;
use Lusen\Tests\Fixtures\Requests\DynamicRulesRequest;
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

it('reports a form request whose rules it could not read', function (): void {
    // The finding this exists for. DynamicRulesRequest builds its rules in a
    // loop, so nothing comes out of it - and without this the endpoint reads
    // as an operation that takes no input, which is a different and untrue
    // statement. It names the class, because that is the file to go and look
    // at.
    Route::post('api/orders/dynamic', [OrderController::class, 'dynamic'])->name('orders.dynamic');

    $this->withoutMockingConsoleOutput();

    Artisan::call('lusen:check');

    expect(Artisan::output())->toContain('no fields read from `DynamicRulesRequest`');
});

it('says nothing about a write that genuinely takes no body', function (): void {
    // A state transition takes nothing and is right to. Flagging every POST
    // with no body would put a finding on it that nobody could ever clear,
    // which is how a team learns to ignore this command.
    Route::post('api/orders/{order}/ready', [OrderController::class, 'bare'])->name('orders.ready');

    $this->artisan('lusen:check')
        ->doesntExpectOutputToContain('no fields read from')
        ->assertSuccessful();
});

it('says nothing about a form request it read fields from', function (): void {
    Route::post('api/orders', [OrderController::class, 'store'])->name('orders.store');

    $this->artisan('lusen:check')
        ->doesntExpectOutputToContain('no fields read from')
        ->assertSuccessful();
});

it('carries the unread form request through the build cache', function (): void {
    // The finding is read off the IR, so a cached endpoint that lost the
    // class would report clean on every warm build - the false negative the
    // check exists to prevent, arriving one run later.
    Route::post('api/orders/dynamic', [OrderController::class, 'dynamic'])->name('orders.dynamic');

    $endpoint = app(SpecBuilder::class)->build()->endpoint('orders.dynamic');
    $restored = Endpoint::fromArray($endpoint?->toArray() ?? []);

    expect($restored->requestClass)->toBe(DynamicRulesRequest::class)
        ->and($restored->parametersIn(ParameterLocation::Body))->toBe([]);
});
