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
        $supported = [
            'curl' => 'cURL',
            'javascript' => 'JavaScript',
            'laravel' => 'PHP (Laravel)',
            'guzzle' => 'PHP (Guzzle)',
            'python' => 'Python',
            'go' => 'Go',
        ];

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

    /**
     * What the highlighter should call this snippet's language.
     *
     * Here rather than in the Blade that renders the tabs: a view deciding
     * that `guzzle` is highlighted as PHP is a language table living in
     * markup, and the next language added would be coloured as JavaScript
     * until somebody noticed.
     */
    public static function syntax(string $language): string
    {
        return match ($language) {
            'javascript' => 'javascript',
            'laravel', 'guzzle' => 'php',
            'python' => 'python',
            'go' => 'go',
            default => 'bash',
        };
    }

    public static function render(string $language, Endpoint $endpoint, ?string $baseUrl = null): string
    {
        return match ($language) {
            'javascript' => self::javascript($endpoint, $baseUrl),
            'laravel' => self::laravel($endpoint, $baseUrl),
            'guzzle' => self::guzzle($endpoint, $baseUrl),
            'python' => self::python($endpoint, $baseUrl),
            'go' => self::go($endpoint, $baseUrl),
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
     * Laravel's HTTP client.
     *
     * First of the two PHP snippets because this package documents Laravel
     * applications, and the people integrating with one are usually holding
     * another. `withHeaders()` rather than `withToken()` even when the scheme
     * is a bearer token: the header list is whatever the endpoint actually
     * requires, and a snippet that quietly drops an API-key header would be a
     * request that 401s.
     */
    public static function laravel(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);

        $headers = [];

        foreach ($request['headers'] as $name => $value) {
            $headers[] = "    '".self::quote((string) $name)."' => '".self::quote((string) $value)."',";
        }

        $call = strtolower($request['method']);
        $url = "'".self::quote($request['url'])."'";
        $body = $request['body'];

        $arguments = $body === null || $body === []
            ? $url
            : $url.', '.self::phpValue($body, '');

        $code = "use Illuminate\\Support\\Facades\\Http;\n\n";

        $code .= $headers === []
            ? '$response = Http::'.$call.'('.$arguments.');'
            : '$response = Http::withHeaders(['."\n".implode("\n", $headers)."\n"
                .'])->'.$call.'('.$arguments.');';

        return $code."\n\n".'$data = $response->json();';
    }

    /**
     * Guzzle, for the reader whose application is not a Laravel one.
     */
    public static function guzzle(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);

        $options = [];
        $headers = [];

        foreach ($request['headers'] as $name => $value) {
            $headers[] = "        '".self::quote((string) $name)."' => '".self::quote((string) $value)."',";
        }

        if ($headers !== []) {
            $options[] = "    'headers' => [\n".implode("\n", $headers)."\n    ],";
        }

        $body = $request['body'];

        if ($body !== null && $body !== []) {
            $options[] = "    'json' => ".self::phpValue($body, '    ').',';
        }

        $arguments = "'".$request['method']."', '".self::quote($request['url'])."'";

        if ($options !== []) {
            $arguments .= ", [\n".implode("\n", $options)."\n]";
        }

        return "use GuzzleHttp\\Client;\n\n"
            .'$client = new Client();'."\n\n"
            .'$response = $client->request('.$arguments.');'."\n\n"
            .'$data = json_decode((string) $response->getBody(), true);';
    }

    /**
     * Python, through `requests`.
     *
     * `json=` rather than `data=json.dumps(...)`: it sets the content type,
     * serialises the body, and is what anybody writing Python against a JSON
     * API actually types.
     */
    public static function python(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);

        $arguments = ['    "'.self::escapeDouble($request['url']).'",'];
        $headers = [];

        foreach ($request['headers'] as $name => $value) {
            $headers[] = '        "'.self::escapeDouble((string) $name).'": "'.self::escapeDouble((string) $value).'",';
        }

        if ($headers !== []) {
            $arguments[] = "    headers={\n".implode("\n", $headers)."\n    },";
        }

        $body = $request['body'];

        if ($body !== null && $body !== []) {
            $arguments[] = '    json='.self::pythonValue($body, '    ').',';
        }

        return "import requests\n\n"
            .'response = requests.'.strtolower($request['method'])."(\n"
            .implode("\n", $arguments)
            ."\n)\n\ndata = response.json()";
    }

    /**
     * Go, through `net/http`.
     *
     * No `package main` and no `func main`, which would be four lines of
     * ceremony before the first line that says anything about this endpoint.
     * The body is a raw string literal so the JSON reads as JSON rather than
     * as a wall of escaped quotes - unless it contains a backtick, which a raw
     * literal cannot hold, and then it is quoted the long way instead.
     */
    public static function go(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $request = RequestModel::for($endpoint, $baseUrl);
        $body = self::bodyJson($request['body']);
        $url = '"'.self::escapeDouble($request['url']).'"';

        $lines = [];

        if ($body !== null) {
            $lines[] = 'body := []byte('.self::goString($body).')';
            $lines[] = '';
        }

        $lines[] = 'req, _ := http.NewRequest("'.$request['method'].'", '.$url.', '
            .($body === null ? 'nil' : 'bytes.NewBuffer(body)').')';

        foreach ($request['headers'] as $name => $value) {
            $lines[] = 'req.Header.Set("'.self::escapeDouble((string) $name).'", "'.self::escapeDouble((string) $value).'")';
        }

        return implode("\n", [
            ...$lines,
            '',
            'res, err := http.DefaultClient.Do(req)',
            'if err != nil {',
            "\tlog.Fatal(err)",
            '}',
            'defer res.Body.Close()',
            '',
            'var data map[string]any',
            'json.NewDecoder(res.Body).Decode(&data)',
        ]);
    }

    /**
     * A Python literal for one value.
     */
    private static function pythonValue(mixed $value, string $indent): string
    {
        if (is_array($value)) {
            // `[]` for both, because PHP cannot tell them apart: an empty
            // JSON object decodes to exactly the same value as an empty
            // array, and guessing `{}` would be wrong just as often.
            if ($value === []) {
                return '[]';
            }

            $isList = array_is_list($value);
            $inner = $indent.'    ';
            $lines = [];

            foreach ($value as $key => $item) {
                $lines[] = $isList
                    ? $inner.self::pythonValue($item, $inner).','
                    : $inner.'"'.self::escapeDouble((string) $key).'": '.self::pythonValue($item, $inner).',';
            }

            return ($isList ? "[\n" : "{\n").implode("\n", $lines)."\n".$indent.($isList ? ']' : '}');
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if ($value === null) {
            return 'None';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"'.self::escapeDouble(is_scalar($value) ? (string) $value : '').'"';
    }

    /**
     * A Go string holding a JSON body: raw where it can be, quoted where a
     * backtick makes a raw literal impossible.
     */
    private static function goString(string $json): string
    {
        if (! str_contains($json, '`')) {
            return '`'.$json.'`';
        }

        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $json).'"';
    }

    /**
     * For a double-quoted string in Python, Go or JSON.
     */
    private static function escapeDouble(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }

    /**
     * A PHP literal for one value, short-array syntax throughout.
     *
     * Written out rather than run through `var_export()`, which spells an
     * array `array (` on its own line, indents with two spaces and prints
     * `NULL` in capitals - none of which is what anyone writes by hand, and
     * all of which a reader would have to tidy after pasting.
     */
    private static function phpValue(mixed $value, string $indent): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $isList = array_is_list($value);
            $inner = $indent.'    ';
            $lines = [];

            foreach ($value as $key => $item) {
                $lines[] = $isList
                    ? $inner.self::phpValue($item, $inner).','
                    : $inner."'".self::quote((string) $key)."' => ".self::phpValue($item, $inner).',';
            }

            return "[\n".implode("\n", $lines)."\n".$indent.']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".self::quote(is_scalar($value) ? (string) $value : '')."'";
    }

    /**
     * For a single-quoted PHP string: only the quote and the backslash mean
     * anything inside one.
     */
    private static function quote(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
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
