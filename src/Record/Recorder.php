<?php

declare(strict_types=1);

namespace Lusen\Record;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures responses while the host application's tests run.
 *
 * Static, deliberately. A test suite rebuilds the container between tests, so
 * anything held in it is gone by the second example; the buffer has to outlive
 * the application to collect a whole run. `ResourceReader` keeps model state
 * the same way and for the same reason.
 *
 * Nothing here is reachable from a build. It is wired up only when
 * `record.enabled` is on, which is a testing concern, and the build reads the
 * file this eventually writes rather than anything in memory.
 */
final class Recorder
{
    /**
     * Bodies past this are not examples, they are payloads. A response that
     * takes four screens teaches a reader less than one that fits on one, and
     * it would be committed to the repository at that size forever.
     */
    private const MAX_BYTES = 16384;

    private static ?Recordings $buffer = null;

    public static function start(Recordings $existing): void
    {
        self::$buffer = $existing;
    }

    public static function started(): bool
    {
        return self::$buffer !== null;
    }

    public static function flush(): void
    {
        self::$buffer = null;
    }

    public static function recordings(): Recordings
    {
        return self::$buffer ?? Recordings::empty();
    }

    /**
     * @param  list<string>  $redact  field names whose values are replaced
     */
    public static function capture(Request $request, Response $response, array $redact = []): void
    {
        if (self::$buffer === null) {
            return;
        }

        $uri = $request->route()?->uri();

        // No matched route means no endpoint to attach it to. A 404 from the
        // router is the test suite asking for something that is not there,
        // which documents nothing.
        if (! is_string($uri) || $uri === '') {
            return;
        }

        $body = self::body($response);

        if ($body === null) {
            return;
        }

        self::$buffer = self::$buffer->with(new Recording(
            method: $request->getMethod(),
            uri: ltrim($uri, '/'),
            status: $response->getStatusCode(),
            body: self::redact($body, $redact),
        ));
    }

    /**
     * @return array<mixed>|null
     */
    private static function body(Response $response): ?array
    {
        $type = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($type, 'json')) {
            return null;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Replaces the value of any field named in the redact list, at any depth.
     *
     * A recorded response is committed to somebody's repository, and a test
     * suite that mints a real-looking token mints it into this file. The
     * placeholder keeps the field, its name and its position, because the
     * shape is the part worth documenting - it is only the value nobody
     * should be reading in a pull request.
     *
     * @param  array<mixed>  $body
     * @param  list<string>  $redact
     * @return array<mixed>
     */
    private static function redact(array $body, array $redact): array
    {
        if ($redact === []) {
            return $body;
        }

        $lowered = array_map(strtolower(...), $redact);

        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = self::redact($value, $redact);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), $lowered, true) && is_scalar($value)) {
                $body[$key] = is_string($value) ? 'REDACTED' : $value;
            }
        }

        return $body;
    }
}
