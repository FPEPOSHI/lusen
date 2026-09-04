<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures;

/**
 * The base controller pattern: every success goes out through one helper that
 * wraps it, so the action itself never names the envelope.
 */
abstract class BaseEnvelopeController
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function sendResponse(array $result): mixed
    {
        $response = [
            'status' => true,
            'data' => $result,
        ];

        return response()->json($response, 200);
    }

    public function sendNothing(): mixed
    {
        return response()->noContent();
    }

    public function sendError(string $message): mixed
    {
        $response = [
            'status' => false,
            'message' => $message,
        ];

        return response()->json($response, 422);
    }
}

final class EnvelopeController extends BaseEnvelopeController
{
    /**
     * Ping
     */
    public function pong(): mixed
    {
        return $this->sendResponse([
            'pong' => 1,
            'ip' => '127.0.0.1',
        ]);
    }

    /**
     * Report
     *
     * The payload comes from a service, so only the envelope is knowable.
     */
    public function report(): mixed
    {
        return $this->sendResponse(app('reports')->generate());
    }

    /**
     * Nothing
     */
    public function nothing(): mixed
    {
        return $this->sendNothing();
    }

    /**
     * Guarded
     *
     * The error path returns first, as guard clauses do.
     */
    public function guarded(): mixed
    {
        if (request()->missing('id')) {
            return $this->sendError('nope');
        }

        return $this->sendResponse(['ok' => true]);
    }
}
