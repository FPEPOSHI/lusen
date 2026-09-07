<?php

declare(strict_types=1);

namespace Lusen\Extract;

use Lusen\Collect\RouteCandidate;
use Lusen\Extract\Contracts\Extractor;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Example;
use Lusen\Ir\Response;
use Lusen\Record\Recordings;

/**
 * Puts real captured responses on the endpoints they came from.
 *
 * A generated example satisfies the schema and nothing else - it says
 * `"status": "string"` where the API says `"paid"` - and a reader copying one
 * into a client learns the shape without ever seeing the thing. This replaces
 * the generated body with the one the application actually returned.
 *
 * Reads a committed JSON file and nothing else, so extraction stays what it
 * was: no application booted, no database, no request dispatched.
 *
 * Runs after the extractors that infer, and before the ones that read
 * annotations. A recording beats a guess, because it is what happened; an
 * `#[ApiResponse(example: ...)]` beats a recording, because somebody wrote it
 * down on purpose and invariant 5 says explicit annotation is the last word.
 */
final readonly class RecordedExampleExtractor implements Extractor
{
    public function __construct(private Recordings $recordings) {}

    public function extract(Endpoint $endpoint, RouteCandidate $candidate): Endpoint
    {
        if ($this->recordings->isEmpty() || $endpoint->responses === []) {
            return $endpoint;
        }

        $responses = [];
        $changed = false;

        foreach ($endpoint->responses as $response) {
            $recording = $this->recordings->for(
                $endpoint->method->value,
                $endpoint->uri,
                $response->status,
            );

            if ($recording === null) {
                $responses[] = $response;

                continue;
            }

            $changed = true;

            $responses[] = new Response(
                status: $response->status,
                description: $response->description,
                schema: $response->schema,
                // Labelled so a reader can tell which bodies on the page are
                // the API's own words and which the documentation made up.
                examples: [new Example('Recorded', $recording->body, $recording->contentType)],
                contentType: $response->contentType,
                headers: $response->headers,
            );
        }

        return $changed ? $endpoint->withResponses($responses) : $endpoint;
    }
}
