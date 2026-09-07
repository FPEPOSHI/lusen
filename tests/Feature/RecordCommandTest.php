<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Lusen\LusenServiceProvider;
use Lusen\Record\Recorder;
use Lusen\Record\Recording;
use Lusen\Record\Recordings;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\ProfileController;
use Lusen\Tests\Fixtures\ShapedController;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->recordings = sys_get_temp_dir().'/lusen-rec-'.bin2hex(random_bytes(4)).'.json';
    config()->set('lusen.record.path', $this->recordings);
    Recorder::flush();
});

afterEach(function (): void {
    Recorder::flush();

    if (is_file($this->recordings)) {
        unlink($this->recordings);
    }
});

/**
 * A request that matched a route, which is the only kind worth recording.
 */
function matchedRequest(string $method, string $uri): Request
{
    $request = Request::create('/'.ltrim(str_replace('{order}', '91', $uri), '/'), $method);
    $request->setRouteResolver(fn (): Route => new Route([$method], $uri, []));

    return $request;
}

function jsonResponse(array $body, int $status = 200): Response
{
    return new Response((string) json_encode($body), $status, ['Content-Type' => 'application/json']);
}

it('captures a response against the route template, not the url called', function (): void {
    Recorder::start(Recordings::empty());
    Recorder::capture(matchedRequest('GET', 'api/orders/{order}'), jsonResponse(['id' => 91, 'status' => 'paid']));

    expect(Recorder::recordings()->for('GET', 'api/orders/{order}', 200)?->body)
        ->toBe(['id' => 91, 'status' => 'paid']);
});

it('redacts a value that should not be committed, and keeps the field', function (): void {
    Recorder::start(Recordings::empty());
    Recorder::capture(
        matchedRequest('POST', 'api/tokens'),
        jsonResponse(['data' => ['token' => 'sk_live_9f2b', 'expires_in' => 3600]], 201),
        ['token'],
    );

    // The shape is what documents the API; the value is the part nobody
    // should be reading in a pull request.
    expect(Recorder::recordings()->for('POST', 'api/tokens', 201)?->body)
        ->toBe(['data' => ['token' => 'REDACTED', 'expires_in' => 3600]]);
});

it('ignores a request that matched no route, and a body that is not json', function (): void {
    Recorder::start(Recordings::empty());

    $unmatched = Request::create('/nowhere', 'GET');
    Recorder::capture($unmatched, jsonResponse(['x' => 1]));

    Recorder::capture(
        matchedRequest('GET', 'api/report'),
        new Response('<html></html>', 200, ['Content-Type' => 'text/html']),
    );

    expect(Recorder::recordings()->isEmpty())->toBeTrue();
});

it('does nothing at all when recording was never started', function (): void {
    Recorder::capture(matchedRequest('GET', 'api/orders/{order}'), jsonResponse(['id' => 91]));

    expect(Recorder::recordings()->isEmpty())->toBeTrue();
});

it('uses a recorded body in the build instead of a generated one', function (): void {
    // A controller whose example is generated from the resource, which is
    // exactly the case a recording improves: the generated body types every
    // field and says nothing about what the API returns.
    Router::get('api/profiles/{profile}', [ProfileController::class, 'show'])->name('profiles.show');

    file_put_contents($this->recordings, Recordings::empty()
        ->with(new Recording('GET', 'api/profiles/{profile}', 200, ['data' => ['id' => 91, 'name' => 'Ada Lovelace']]))
        ->toJson());

    $endpoint = app(SpecBuilder::class)->build()->endpoint('profiles.show');
    $example = $endpoint?->responses[0]->examples[0] ?? null;

    expect($example?->label)->toBe('Recorded')
        ->and($example?->value)->toBe(['data' => ['id' => 91, 'name' => 'Ada Lovelace']]);
});

it('still lets an attribute overrule a recording', function (): void {
    Router::get('api/orders/{order}', [ShapedController::class, 'show'])->name('orders.show');

    file_put_contents($this->recordings, Recordings::empty()
        ->with(new Recording('GET', 'api/orders/{order}', 200, ['id' => 'from the recording']))
        ->toJson());

    // Explicit annotation is the last word, so the extractor that reads it
    // runs after the one that applies recordings.
    $endpoint = app(SpecBuilder::class)->build()->endpoint('orders.show');

    expect($endpoint?->responses[0]->examples[0]->value)->toBe(['id' => 1]);
});

it('deletes the recordings when asked, and says so when there are none', function (): void {
    file_put_contents($this->recordings, Recordings::empty()->with(
        new Recording('GET', 'api/users', 200, ['data' => []]),
    )->toJson());

    $this->artisan('lusen:record', ['--clear' => true])
        ->expectsOutputToContain('Deleted')
        ->assertSuccessful();

    expect(is_file($this->recordings))->toBeFalse();

    $this->artisan('lusen:record', ['--clear' => true])
        ->expectsOutputToContain('nothing recorded')
        ->assertSuccessful();
});

it('captures a real request once the environment switches recording on', function (): void {
    // The wiring is the part most likely to break silently, so it is
    // exercised rather than assumed: the provider is re-registered with the
    // variable set, which is what the command does to its child process.
    Router::get('api/ping', fn () => response()->json(['pong' => true]))->name('ping');

    putenv('LUSEN_RECORD=1');
    putenv('LUSEN_RECORD_PATH='.$this->recordings);

    try {
        $this->app->register(LusenServiceProvider::class, force: true);

        $this->getJson('/api/ping')->assertOk();

        expect(Recorder::recordings()->for('GET', 'api/ping', 200)?->body)->toBe(['pong' => true]);
    } finally {
        putenv('LUSEN_RECORD');
        putenv('LUSEN_RECORD_PATH');
    }
});
