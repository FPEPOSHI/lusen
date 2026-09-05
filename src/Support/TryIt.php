<?php

declare(strict_types=1);

namespace Lusen\Support;

use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;

/**
 * Whether one endpoint gets the request form.
 *
 * Three things decide it, and they only ever subtract: the site has to have
 * turned the playground on, the method has to be one the site allows, and the
 * endpoint must not have withdrawn from it with `#[ApiDoc(tryIt: false)]`.
 * There is deliberately no way for an endpoint to opt *into* a playground the
 * site has turned off - a package that shipped a Send button someone did not
 * ask for would deserve everything that followed.
 */
final class TryIt
{
    /**
     * @param  array<string, mixed>|mixed  $config  the `lusen.try_it` section
     */
    public static function enabled(Endpoint $endpoint, mixed $config): bool
    {
        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return false;
        }

        if ($endpoint->tryIt === false) {
            return false;
        }

        return in_array($endpoint->method->value, self::methods($config), true);
    }

    /**
     * Whether the site has the playground on at all - the layout asks before
     * putting its settings in the page.
     *
     * @param  array<string, mixed>|mixed  $config
     */
    public static function configured(mixed $config): bool
    {
        return is_array($config) && ($config['enabled'] ?? false) === true;
    }

    /**
     * What the script needs and nothing else. `methods` and the per-endpoint
     * opt-out are settled here, in PHP, so a page that should not offer to
     * send a request does not ship the machinery to do it.
     *
     * @param  array<string, mixed>|mixed  $config
     * @return array{credentials: bool, persist: string, baseUrl: string}
     */
    public static function options(mixed $config, ?string $baseUrl = null): array
    {
        $config = is_array($config) ? $config : [];
        $persist = $config['persist_token'] ?? 'session';

        return [
            'credentials' => ($config['credentials'] ?? false) === true,
            'persist' => in_array($persist, ['session', 'local', 'none'], true) ? $persist : 'session',
            // Credentials are stored against the host they are for, so the
            // token for a sandbox is never offered up to production.
            'baseUrl' => rtrim($baseUrl ?? '', '/'),
        ];
    }

    /**
     * The credentials this API asks for, taken from the first endpoint that
     * asks for any.
     *
     * It is one field in the sidebar rather than one per endpoint page: an
     * API that wants a bearer token wants the same bearer token everywhere,
     * and retyping it on each page is the kind of friction that stops people
     * using the playground by the third endpoint. An API that genuinely
     * differs per operation still gets the fields inside each dialog.
     *
     * @return array{scheme: string, headers: list<string>}|null
     */
    public static function auth(ApiSpec $spec): ?array
    {
        foreach ($spec->endpoints() as $endpoint) {
            $scheme = $endpoint->securityScheme();

            if ($scheme !== null) {
                return ['scheme' => $scheme->type, 'headers' => array_keys($scheme->exampleHeaders())];
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function methods(array $config): array
    {
        $configured = $config['methods'] ?? ['GET'];

        if (! is_array($configured)) {
            return ['GET'];
        }

        $methods = [];

        foreach ($configured as $method) {
            if (is_string($method)) {
                $methods[] = strtoupper($method);
            }
        }

        return $methods;
    }
}
