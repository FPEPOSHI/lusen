<?php

declare(strict_types=1);

namespace Lusen\Ir;

use Lusen\Support\Data;

/**
 * How an endpoint expects to be authenticated.
 *
 * A boolean was enough while every documented API used a bearer token. It is
 * wrong for an app using basic auth or an API key header, and it throws away
 * the most useful thing Passport and Sanctum state outright: the scopes or
 * abilities a token has to carry. "Requires authentication" sends an
 * integrator to support; "requires the orders:write scope" does not.
 */
final readonly class SecurityScheme
{
    public const BEARER = 'bearer';

    public const BASIC = 'basic';

    public const API_KEY = 'apiKey';

    public const OAUTH2 = 'oauth2';

    /**
     * @param  list<string>  $scopes  scopes or Sanctum abilities the token must carry
     * @param  list<string>  $headers  header names, for API-key schemes. More than
     *                                 one means all of them are required together,
     *                                 which is how client id and client secret pairs
     *                                 actually work.
     */
    public function __construct(
        public string $type = self::BEARER,
        public array $scopes = [],
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: Data::string($data, 'type', self::BEARER),
            scopes: Data::strings($data, 'scopes'),
            headers: Data::strings($data, 'headers'),
        );
    }

    /**
     * The name this scheme gets in an OpenAPI components block. Schemes with
     * different shapes must not collide.
     */
    public function name(): string
    {
        return match ($this->type) {
            self::BASIC => 'basicAuth',
            self::API_KEY => 'apiKeyAuth',
            self::OAUTH2 => 'oauth2',
            default => 'bearerAuth',
        };
    }

    /**
     * The header names this scheme sends. Bearer and basic both travel in
     * Authorization; an API-key scheme names its own.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        if ($this->type === self::API_KEY) {
            return $this->headers === [] ? ['X-API-Key'] : $this->headers;
        }

        return ['Authorization'];
    }

    /**
     * One OpenAPI scheme per header, since two headers required together are
     * two schemes ANDed, not one scheme with two names.
     *
     * @return array<string, string> scheme key => header name
     */
    public function schemes(): array
    {
        if ($this->type !== self::API_KEY) {
            return [$this->name() => ''];
        }

        $schemes = [];

        foreach ($this->headerNames() as $header) {
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $header))));
            $schemes[$key] = $header;
        }

        return $schemes;
    }

    /**
     * A placeholder value for each header, for examples and snippets.
     *
     * @return array<string, string>
     */
    public function exampleHeaders(): array
    {
        if ($this->type === self::BASIC) {
            return ['Authorization' => 'Basic '.base64_encode('username:password')];
        }

        if ($this->type !== self::API_KEY) {
            return ['Authorization' => 'Bearer YOUR_TOKEN'];
        }

        $headers = [];

        foreach ($this->headerNames() as $header) {
            $headers[$header] = 'YOUR_'.strtoupper(str_replace(['-', ' '], '_', preg_replace('/^X-/i', '', $header) ?? $header));
        }

        return $headers;
    }

    public function label(): string
    {
        $base = match ($this->type) {
            self::BASIC => 'HTTP basic authentication',
            self::API_KEY => count($this->headerNames()) === 1
                ? 'An API key in the `'.$this->headerNames()[0].'` header'
                : 'The '.implode(' and ', array_map(
                    static fn (string $h): string => "`{$h}`",
                    $this->headerNames(),
                )).' headers',
            self::OAUTH2 => 'An OAuth2 access token',
            default => 'A bearer token',
        };

        if ($this->scopes === []) {
            return $base;
        }

        return $base.' with the '.implode(', ', array_map(
            static fn (string $scope): string => "`{$scope}`",
            $this->scopes,
        )).' '.(count($this->scopes) === 1 ? 'scope' : 'scopes');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'scopes' => $this->scopes ?: null,
            'headers' => $this->headers ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
