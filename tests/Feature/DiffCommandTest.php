<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Lusen\Tests\Fixtures\UserController;

beforeEach(function (): void {
    $this->baseline = sys_get_temp_dir().'/lusen-baseline-'.bin2hex(random_bytes(4)).'.json';
});

afterEach(function (): void {
    if (is_file($this->baseline)) {
        unlink($this->baseline);
    }
});

it('says how to record a baseline rather than failing without one', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    // A first CI run has nothing to compare against, and going red there
    // would make adding the command to a pipeline the thing that broke it.
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('lusen:diff', ['--against' => $this->baseline, '--strict' => true]))->toBe(0);

    expect(Artisan::output())->toContain('No baseline')
        ->toContain('lusen:diff --save');
});

it('records the current build as the baseline', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])
        ->expectsOutputToContain('Recorded the baseline')
        ->assertSuccessful();

    $recorded = json_decode((string) file_get_contents($this->baseline), true);

    expect($recorded['title'])->toBe('Test API')
        ->and($recorded['groups'][0]['endpoints'][0]['id'])->toBe('users.index');
});

it('keeps one machine out of a committed baseline', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    // sourceFiles are absolute paths kept for the incremental cache. In a
    // file people commit they are one developer's home directory and a diff
    // on every other machine.
    expect(file_get_contents($this->baseline))
        ->not->toContain('sourceFiles')
        ->not->toContain(base_path());
});

it('reports nothing when the build has not moved', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--strict' => true])
        ->expectsOutputToContain('Nothing changed')
        ->assertSuccessful();
});

it('fails a strict run when an endpoint disappears', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::get('api/orders', [UserController::class, 'index'])->name('orders.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    config()->set('lusen.routes.exclude', ['api/orders']);

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--strict' => true])
        ->assertFailed();

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('lusen:diff', ['--against' => $this->baseline]))->toBe(0);

    expect(Artisan::output())->toContain('Breaking')
        ->toContain('GET /api/orders')
        ->toContain('is no longer documented');
});

it('passes a strict run when the only change is an addition', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    Route::get('api/orders', [UserController::class, 'index'])->name('orders.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--strict' => true])
        ->expectsOutputToContain('none of them breaking')
        ->assertSuccessful();
});

it('reports the same changes as json for a script to read', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::get('api/orders', [UserController::class, 'index'])->name('orders.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    config()->set('lusen.routes.exclude', ['api/orders']);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('lusen:diff', ['--against' => $this->baseline, '--json' => true]))->toBe(0);

    $report = json_decode(Artisan::output(), true);

    expect($report['summary'])->toBe(['breaking' => 1, 'additive' => 0, 'notice' => 0])
        ->and($report['changes'][0])->toBe([
            'severity' => 'breaking',
            'kind' => 'endpoint.removed',
            'subject' => 'GET /api/orders',
            'detail' => 'is no longer documented',
        ]);
});

it('still fails under --strict when reporting json', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--save' => true])->assertSuccessful();

    config()->set('lusen.routes.exclude', ['api/users']);

    $this->artisan('lusen:diff', ['--against' => $this->baseline, '--json' => true, '--strict' => true])
        ->assertFailed();
});

it('resolves a relative baseline against the project root', function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    config()->set('lusen.diff.baseline', 'lusen-diff-test/baseline.json');

    $this->artisan('lusen:diff', ['--save' => true])->assertSuccessful();

    expect(base_path('lusen-diff-test/baseline.json'))->toBeFile();

    exec('rm -rf '.escapeshellarg(base_path('lusen-diff-test')));
});
