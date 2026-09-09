<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Record\Recording;
use Lusen\Record\Recordings;
use Lusen\Tests\Fixtures\ProfileController;
use Lusen\Tests\Fixtures\UserController;

beforeEach(function (): void {
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');

    $this->output = sys_get_temp_dir().'/lusen-build-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    // Output is a tree now (assets/, endpoints/), not a flat directory.
    if (is_dir($this->output)) {
        exec('rm -rf '.escapeshellarg($this->output));
    }
});

it('writes the enabled surfaces to the output directory', function (): void {
    $this->artisan('lusen:build', ['--path' => $this->output])
        ->assertSuccessful();

    expect($this->output.'/openapi.json')->toBeFile()
        ->and($this->output.'/llms.txt')->toBeFile()
        ->and($this->output.'/llms-full.txt')->toBeFile();
});

it('emits valid json for the openapi surface', function (): void {
    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();

    $document = json_decode((string) file_get_contents($this->output.'/openapi.json'), true);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['paths'])->toHaveKey('/api/users');
});

it('limits output to the surfaces named by --only', function (): void {
    $this->artisan('lusen:build', ['--path' => $this->output, '--only' => ['openapi']])
        ->assertSuccessful();

    expect($this->output.'/openapi.json')->toBeFile()
        ->and(file_exists($this->output.'/llms.txt'))->toBeFalse();
});

it('writes nothing on a dry run', function (): void {
    $this->artisan('lusen:build', ['--path' => $this->output, '--dry-run' => true])
        ->assertSuccessful();

    expect(is_dir($this->output))->toBeFalse();
});

it('skips rewriting files whose contents did not change', function (): void {
    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();
    $before = filemtime($this->output.'/openapi.json');

    $this->artisan('lusen:build', ['--path' => $this->output])
        ->expectsOutputToContain('0 files written')
        ->assertSuccessful();

    expect(filemtime($this->output.'/openapi.json'))->toBe($before);
});

it('warns rather than failing when no routes match', function (): void {
    config()->set('lusen.routes.include', ['nothing/*']);

    $this->artisan('lusen:build', ['--path' => $this->output])
        ->expectsOutputToContain('No routes matched')
        ->assertSuccessful();
});

it('warns about a configured emitter that has no implementation', function (): void {
    config()->set('lusen.output.emitters', ['openapi', 'not-a-real-emitter']);

    $this->artisan('lusen:build', ['--path' => $this->output])
        ->expectsOutputToContain('No emitter registered for [not-a-real-emitter]')
        ->assertSuccessful();
});

it('reports how many endpoints it reused', function (): void {
    config()->set('lusen.cache.enabled', true);
    config()->set('lusen.cache.path', $this->output.'-cache');

    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();

    $this->artisan('lusen:build', ['--path' => $this->output])
        ->expectsOutputToContain('reused from cache')
        ->assertSuccessful();

    exec('rm -rf '.escapeshellarg($this->output.'-cache'));
});

it('documents a new recording on the next build, cache or no cache', function (): void {
    // The per-endpoint fingerprint covers the source files an endpoint was
    // read from; a recording is a JSON file no extractor parses. Seen on a
    // real application: `lusen:record` then `lusen:build` changed nothing.
    // A resource-backed endpoint: the users fixture carries an attribute
    // example, and an attribute outranks a recording on purpose.
    Route::get('api/profiles', [ProfileController::class, 'index'])->name('profiles.index');

    config()->set('lusen.cache.enabled', true);
    config()->set('lusen.cache.path', $this->output.'-cache');
    $recordings = $this->output.'-recordings.json';
    config()->set('lusen.record.path', $recordings);

    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();

    file_put_contents($recordings, Recordings::empty()->with(new Recording(
        'GET',
        'api/profiles',
        200,
        ['data' => [['id' => 42, 'name' => 'Recorded Person']]],
    ))->toJson());

    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();

    expect((string) file_get_contents($this->output.'/endpoints/profiles-index.md'))->toContain('Recorded Person');

    exec('rm -rf '.escapeshellarg($this->output.'-cache'));
    unlink($recordings);
});

it('ignores the cache when asked for a fresh build', function (): void {
    config()->set('lusen.cache.enabled', true);
    config()->set('lusen.cache.path', $this->output.'-cache');

    $this->artisan('lusen:build', ['--path' => $this->output])->assertSuccessful();

    $this->artisan('lusen:build', ['--path' => $this->output, '--fresh' => true])
        ->doesntExpectOutputToContain('reused from cache')
        ->assertSuccessful();

    exec('rm -rf '.escapeshellarg($this->output.'-cache'));
});
