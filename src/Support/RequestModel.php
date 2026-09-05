<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\Endpoint;
use Lusen\Ir\Enums\ParameterLocation;
use Lusen\Ir\Parameter;

/**
 * One request to an endpoint, as data rather than as a string.
 *
 * `Snippets` used to assemble a request and flatten it to cURL in the same
 * breath, which was fine while printing was all anyone did with it. The page
 * now also hands the request to the browser, and two descriptions of the same
 * call - one printed, one sent - would disagree the first time either changed.
 * So the assembly lives here, and the snippets render what this returns.
 *
 * Everything is derived from the IR and from `Examples`, so a request is
 * schema-valid: a reader who presses send gets the same call they would have
 * got by copying the example.
 */
final class RequestModel
{
    /**
     * @return array{
     *     method: string,
     *     title: string,
     *     baseUrl: string,
     *     path: string,
     *     url: string,
     *     headers: array<string, string>,
     *     fields: list<array{name: string, in: string, type: string, required: bool, description: string|null, value: string, enum: list<string>}>,
     *     body: array<string, mixed>|null,
     *     auth: array{scheme: string, headers: list<string>}|null,
     *     rateLimit: string|null,
     *     deprecated: bool,
     * }
     */
    public static function for(Endpoint $endpoint, ?string $baseUrl = null): array
    {
        $scheme = $endpoint->securityScheme();

        return [
            'method' => $endpoint->method->value,
            'title' => $endpoint->title(),
            'baseUrl' => rtrim($baseUrl ?? '', '/'),
            // The template, placeholders and all: the browser re-resolves it
            // as the reader edits, and it is the only form that survives that.
            'path' => $endpoint->path(),
            'url' => self::url($endpoint, $baseUrl),
            'headers' => self::headers($endpoint),
            'fields' => self::fields($endpoint),
            'body' => self::body($endpoint),
            'auth' => $scheme === null ? null : [
                'scheme' => $scheme->type,
                'headers' => array_keys($scheme->exampleHeaders()),
            ],
            // Everything a reader should know before they press send. The
            // dialog covers the page it came from, so anything it does not
            // repeat is something they have to close it to go and read.
            'rateLimit' => $endpoint->rateLimit?->label(),
            'deprecated' => $endpoint->deprecated,
        ];
    }

    /**
     * Path placeholders replaced with example values, query parameters
     * appended - i.e. a URL that can actually be requested.
     */
    public static function url(Endpoint $endpoint, ?string $baseUrl = null): string
    {
        $path = $endpoint->path();

        foreach ($endpoint->parametersIn(ParameterLocation::Path) as $parameter) {
            $path = str_replace(
                ['{'.$parameter->name.'?}', '{'.$parameter->name.'}'],
                rawurlencode(self::stringify(Examples::forParameter($parameter))),
                $path,
            );
        }

        $query = self::query($endpoint);

        return rtrim($baseUrl ?? '', '/').$path.($query === '' ? '' : '?'.$query);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(Endpoint $endpoint): array
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

    /**
     * @return array<string, mixed>|null
     */
    public static function body(Endpoint $endpoint): ?array
    {
        $parameters = $endpoint->parametersIn(ParameterLocation::Body);

        return $parameters === [] ? null : Examples::body($parameters);
    }

    /**
     * Every parameter a reader can fill in, flat and in the order the page
     * shows them. Body parameters are not here: they are edited as the JSON
     * document they will be sent as, because a nested rule set
     * (`items.*.product_id`) has no honest single input.
     *
     * @return list<array{name: string, in: string, type: string, required: bool, description: string|null, value: string, enum: list<string>}>
     */
    public static function fields(Endpoint $endpoint): array
    {
        $fields = [];

        foreach ([ParameterLocation::Path, ParameterLocation::Query, ParameterLocation::Header] as $location) {
            foreach ($endpoint->parametersIn($location) as $parameter) {
                $fields[] = [
                    'name' => $parameter->name,
                    'in' => $location->value,
                    'type' => $parameter->schema->type->value,
                    'required' => $parameter->required,
                    'description' => $parameter->description,
                    'value' => self::prefill($parameter, $location),
                    'enum' => array_map(self::stringify(...), $parameter->schema->enum),
                ];
            }
        }

        return $fields;
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
     * An optional filter starts empty, exactly as it does in the printed
     * example - a form that arrives with every filter already set sends a
     * narrower request than the reader asked for.
     */
    private static function prefill(Parameter $parameter, ParameterLocation $location): string
    {
        if ($location === ParameterLocation::Query && ! $parameter->required) {
            return '';
        }

        return self::stringify(Examples::forParameter($parameter));
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
