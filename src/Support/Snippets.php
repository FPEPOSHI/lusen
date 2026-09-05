<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;

/**
 * Runnable request examples.
 *
 * Every endpoint page carries one of these because it is the single most
 * useful thing on the page for both audiences: a reader copies it into a
 * terminal, and an agent reads it to learn the exact shape of a call -
 * headers, auth and body included - without inferring anything.
 *
 * This renders `RequestModel` and does not assemble anything itself. The same
 * model is what the page hands the browser when the playground is on, so the
 * request a reader copies and the request a reader sends cannot drift apart.
 */
final class Snippets
{
    /**
     * The languages this class can actually produce, filtered to what the
     * caller asked for and in their order.
     *
     * A configured language with no implementation is dropped rather than
     * rendered empty - the config should not be able to promise a snippet
     * that does not exist.
     *
     * @param  list<string>|mixed  $configured
     * @return array<string, string> language => label
     */
    public static function languages(mixed $configured): array
    {
        $supported = ['curl' => 'cURL', 'javascript' => 'JavaScript'];

        if (! is_array($configured) || $configured === []) {
            return ['curl' => 'cURL'];
        }

        $languages = [];

        foreach ($configured as $language) {
            if (is_string($language) && isset($supported[$language])) {
                $languages[$language] = $supported[$language];
            }
        }

        return $languages === [] ? ['curl' => 'cURL'] : $languages;
    }

    public static function render(string $language, Endpoint $endpoint, ?string $baseUrl = null): string
    {
        return match ($language) {
            'javascript' => self::javascript($endpoint, $baseUrl),
            default => self::curl($endpoint, $baseUrl),
        };
    }

    public static function curl(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);

        $lines = ["curl -X {$request['method']} '{$request['url']}'"];

        foreach ($request['headers'] as $name => $value) {
            $lines[] = "  -H '{$name}: {$value}'";
        }

        $body = self::bodyJson($request['body']);

        if ($body !== null) {
            $lines[] = "  -d '{$body}'";
        }

        return implode(" \\\n", $lines);
    }

    public static function javascript(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);

        $options = ["  method: '{$request['method']}'"];

        $headers = [];

        foreach ($request['headers'] as $name => $value) {
            $headers[] = "    '{$name}': '{$value}'";
        }

        $options[] = "  headers: {\n".implode(",\n", $headers)."\n  }";

        $body = self::bodyJson($request['body']);

        if ($body !== null) {
            $options[] = '  body: JSON.stringify('.self::indent($body, '  ').')';
        }

        return "const response = await fetch('{$request['url']}', {\n"
            .implode(",\n", $options)
            ."\n});\n\nconst data = await response.json();";
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function bodyJson(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return json_encode(
            $body,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: null;
    }

    private static function indent(string $value, string $prefix): string
    {
        return implode("\n".$prefix, explode("\n", $value));
    }
}
