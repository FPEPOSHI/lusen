<?php

declare(strict_types=1);

namespace Lusen\Ir\Enums;

enum ParameterLocation: string
{
    case Path = 'path';
    case Query = 'query';
    case Header = 'header';
    case Body = 'body';

    /**
     * OpenAPI treats body parameters as a request body rather than a parameter
     * object, so emitters need to split on this.
     */
    public function isOpenApiParameter(): bool
    {
        return $this !== self::Body;
    }
}
