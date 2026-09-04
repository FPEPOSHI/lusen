<?php

declare(strict_types=1);

namespace Lusen\Ir\Enums;

enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';

    /**
     * Whether a request with this method conventionally carries a body.
     */
    public function hasBody(): bool
    {
        return in_array($this, [self::Post, self::Put, self::Patch], true);
    }

    /**
     * Tailwind-friendly token used by the docs UI to colour method badges.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Get, self::Head, self::Options => 'sky',
            self::Post => 'emerald',
            self::Put, self::Patch => 'amber',
            self::Delete => 'rose',
        };
    }
}
