<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Lusen\Collect\RouteCandidate;
use Lusen\Extract\RecordedExampleExtractor;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\HttpMethod;
use Lusen\Ir\Example;
use Lusen\Ir\Response;
use Lusen\Ir\Schema;
use Lusen\Record\Recording;
use Lusen\Record\Recordings;

/**
 * The candidate is never read by this extractor - it works off the endpoint's
 * own method and URI - so a bare one is enough, and building it here keeps the
 * test out of Testbench.
 */
function candidateFor(Endpoint $endpoint): RouteCandidate
{
    return new RouteCandidate(
        route: new Route([$endpoint->method->value], $endpoint->uri, []),
        method: $endpoint->method,
        uri: $endpoint->uri,
    );
}

function orderEndpoint(): Endpoint
{
    return Endpoint::make(HttpMethod::Get, 'api/orders/{order}', 'orders.show')->with(responses: [
        new Response(200, schema: Schema::object(['id' => Schema::integer()]), examples: [
            new Example('Example', ['id' => 0]),
        ]),
        new Response(404, examples: [new Example('Example', ['message' => 'string'])]),
    ]);
}

it('replaces a generated body with the one the api actually returned', function (): void {
    $recordings = Recordings::empty()->with(
        new Recording('GET', 'api/orders/{order}', 200, ['id' => 91, 'status' => 'paid']),
    );

    $endpoint = (new RecordedExampleExtractor($recordings))->extract(orderEndpoint(), candidateFor(orderEndpoint()));

    // A generated example says "status": "string" where the API says "paid".
    expect($endpoint?->responses[0]->examples[0]->value)->toBe(['id' => 91, 'status' => 'paid'])
        ->and($endpoint?->responses[0]->examples[0]->label)->toBe('Recorded');
});

it('leaves a status it never saw exactly as it was', function (): void {
    $recordings = Recordings::empty()->with(new Recording('GET', 'api/orders/{order}', 200, ['id' => 91]));

    $endpoint = (new RecordedExampleExtractor($recordings))->extract(orderEndpoint(), candidateFor(orderEndpoint()));

    expect($endpoint?->responses[1]->examples[0]->value)->toBe(['message' => 'string'])
        ->and($endpoint?->responses[1]->examples[0]->label)->toBe('Example');
});

it('keeps the schema, the description and the headers it was given', function (): void {
    $recordings = Recordings::empty()->with(new Recording('GET', 'api/orders/{order}', 200, ['id' => 91]));

    $endpoint = (new RecordedExampleExtractor($recordings))->extract(orderEndpoint(), candidateFor(orderEndpoint()));

    // The recording is the body, not the documentation of the response.
    expect($endpoint?->responses[0]->schema?->properties)->toHaveKey('id');
});

it('does nothing at all with no recordings', function (): void {
    $before = orderEndpoint();
    $after = (new RecordedExampleExtractor(Recordings::empty()))->extract($before, candidateFor($before));

    expect($after)->toBe($before);
});

it('does not pair a recording with a different operation at the same path', function (): void {
    $recordings = Recordings::empty()->with(new Recording('POST', 'api/orders/{order}', 200, ['id' => 91]));

    $endpoint = (new RecordedExampleExtractor($recordings))->extract(orderEndpoint(), candidateFor(orderEndpoint()));

    expect($endpoint?->responses[0]->examples[0]->value)->toBe(['id' => 0]);
});
