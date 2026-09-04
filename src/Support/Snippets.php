<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;

/**
 * Runnable request examples.
 *
 * Every endpoint page carries one of these because it is the single most
 * useful thing on the page for both audiences: a reader copies it into a
 * terminal, and an agent reads it to learn the exact shape of a call -
 * headers, auth and body included - without inferring anything.
 *
 * The values come from Examples, so a snippet is schema-valid rather than
 * full of the word "string".
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
        $lines = ["curl -X {$endpoint->method->value} '".self::url($endpoint, $baseUrl)."'"];

        foreach (self::headers($endpoint) as $name => $value) {
            $lines[] = "  -H '{$name}: {$value}'";
        }

        $body = self::bodyJson($endpoint);

        if ($body !== null) {
            $lines[] = "  -d '{$body}'";
        }

        return implode(" \\\n", $lines);
    }

    public static function javascript(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $options = ["  method: '{$endpoint->method->value}'"];

        $headers = [];

        foreach (self::headers($endpoint) as $name => $value) {
            $headers[] = "    '{$name}': '{$value}'";
        }

        $options[] = "  headers: {\n".implode(",\n", $headers)."\n  }";

        $body = self::bodyJson($endpoint);

        if ($body !== null) {
            $options[] = '  body: JSON.stringify('.self::indent($body, '  ').')';
        }

        return "const response = await fetch('".self::url($endpoint, $baseUrl)."', {\n"
            .implode(",\n", $options)
            ."\n});\n\nconst data = await response.json();";
    }

    /**
     * Path placeholders replaced with example values, query parameters
     * appended - i.e. a URL that can actually be requested.
     */
    public static function url(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $path = $endpoint->path();

        foreach ($endpoint->parametersIn(ParameterLocation::Path) as $parameter) {
            $value = Examples::forParameter($parameter);

            $path = str_replace(
                ['{'.$parameter->name.'?}', '{'.$parameter->name.'}'],
                rawurlencode(self::stringify($value)),
                $path,
            );
        }

        $query = self::query($endpoint);

        return rtrim($baseUrl ?? '', '/').$path.($query === '' ? '' : '?'.$query);
    }

    /**
     * Only required query parameters go in the URL. Padding an example with
     * every optional filter makes the common call look more complicated than
     * it is.
     */
    private static function query(Endpoint $endpoint): string
    {
        $pairs = [];

        foreach ($endpoint->parametersIn(ParameterLocation::Query) as $parameter) {
            if (! $parameter->required) {
                continue;
            }

            $pairs[$parameter->name] = self::stringify(Examples::forParameter($parameter));
        }

        return http_build_query($pairs);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Endpoint $endpoint): array
    {
        $headers = [];

        $scheme = $endpoint->securityScheme();

        if ($scheme !== null) {
            foreach ($scheme->exampleHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        $headers['Accept'] = 'application/json';

        if ($endpoint->hasBody()) {
            $headers['Content-Type'] = 'application/json';
        }

        foreach ($endpoint->parametersIn(ParameterLocation::Header) as $parameter) {
            $headers[$parameter->name] = self::stringify(Examples::forParameter($parameter));
        }

        return $headers;
    }

    private static function bodyJson(Endpoint $endpoint): ?string
    {
        $parameters = $endpoint->parametersIn(ParameterLocation::Body);

        if ($parameters === []) {
            return null;
        }

        return json_encode(
            Examples::body($parameters),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: null;
    }

    private static function indent(string $value, string $prefix): string
    {
        return implode("\n".$prefix, explode("\n", $value));
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
