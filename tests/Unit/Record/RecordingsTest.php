<?php

declare(strict_types=1);

use Lusen\Record\Recording;
use Lusen\Record\Recordings;

function recording(string $method = 'GET', string $uri = 'api/orders/{order}', int $status = 200, array $body = ['id' => 1]): Recording
{
    return new Recording($method, $uri, $status, $body);
}

it('finds a recording by method, path template and status', function (): void {
    // The template rather than the URL that was called, so a test hitting
    // /api/orders/91 matches whatever ids the fixtures happened to mint.
    $recordings = Recordings::empty()->with(recording());

    expect($recordings->for('GET', 'api/orders/{order}', 200)?->body)->toBe(['id' => 1])
        ->and($recordings->for('GET', 'api/orders/{order}', 404))->toBeNull()
        ->and($recordings->for('POST', 'api/orders/{order}', 200))->toBeNull();
});

it('keeps the first recording of an operation, not the last', function (): void {
    // Fixtures are usually random. Re-recording on every run would rewrite
    // the file with fresh names each time and turn a committed artefact into
    // permanent diff noise.
    $recordings = Recordings::empty()
        ->with(recording(body: ['id' => 1]))
        ->with(recording(body: ['id' => 2]));

    expect($recordings->for('GET', 'api/orders/{order}', 200)?->body)->toBe(['id' => 1])
        ->and($recordings->count())->toBe(1);
});

it('writes a file that diffs, and diffs the same way twice', function (): void {
    $one = Recordings::empty()->with(recording(status: 404, body: ['message' => 'Not found']))->with(recording());
    $two = Recordings::empty()->with(recording())->with(recording(status: 404, body: ['message' => 'Not found']));

    // Sorted, so the order responses happened to be captured in does not
    // show up as a diff on somebody's pull request.
    expect($one->toJson())->toBe($two->toJson())
        ->and($one->toJson())->toContain("\n    {")
        ->and(str_ends_with($one->toJson(), "\n"))->toBeTrue();
});

it('round-trips through the file', function (): void {
    $recordings = Recordings::empty()->with(recording())->with(recording(status: 422, body: ['errors' => []]));

    $restored = Recordings::fromJson($recordings->toJson());

    expect($restored->count())->toBe(2)
        ->and($restored->for('GET', 'api/orders/{order}', 200)?->body)->toBe(['id' => 1]);
});

it('counts operations rather than responses', function (): void {
    // Forty recordings across three endpoints is not coverage.
    $recordings = Recordings::empty()
        ->with(recording(status: 200))
        ->with(recording(status: 404))
        ->with(recording(uri: 'api/orders', status: 200));

    expect($recordings->count())->toBe(3)
        ->and($recordings->operations())->toBe(2);
});

it('survives a file that is not what it expected', function (): void {
    foreach (['', 'not json', '{"nope":1}', '[1,2,3]', '[{"uri":""}]'] as $json) {
        expect(Recordings::fromJson($json)->isEmpty())->toBeTrue();
    }
});

it('reads nothing from a path that is not there', function (): void {
    expect(Recordings::read('/tmp/lusen-does-not-exist-'.bin2hex(random_bytes(4)).'.json')->isEmpty())->toBeTrue();
});
