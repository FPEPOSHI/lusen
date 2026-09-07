<?php

declare(strict_types=1);

namespace Lusen\Record;

use Lusen\Support\Data;

/**
 * One real response, captured while the host application's own tests ran.
 *
 * Keyed by the route's URI template rather than the URL that was called, so a
 * test hitting `/api/orders/91` records against `api/orders/{order}` and
 * matches the endpoint whatever ids the fixtures happened to mint.
 */
final readonly class Recording
{
    /**
     * @param  array<mixed>  $body  a decoded JSON body, object or list
     */
    public function __construct(
        public string $method,
        public string $uri,
        public int $status,
        public array $body,
        public string $contentType = 'application/json',
    ) {}

    /**
     * The identity two halves of this feature have to agree on: the recorder
     * writes it, the extractor looks it up.
     */
    public function key(): string
    {
        return self::keyFor($this->method, $this->uri, $this->status);
    }

    public static function keyFor(string $method, string $uri, int $status): string
    {
        return strtoupper($method).' '.trim($uri, '/').' '.$status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];

        return new self(
            method: Data::string($data, 'method', 'GET'),
            uri: Data::string($data, 'uri'),
            status: Data::int($data, 'status', 200),
            body: $body,
            contentType: Data::string($data, 'contentType', 'application/json'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'status' => $this->status,
            'contentType' => $this->contentType,
            'body' => $this->body,
        ];
    }
}
