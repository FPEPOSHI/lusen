<?php

declare(strict_types=1);

namespace Lusen\Ir\Enums;

enum SchemaType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
    case Null = 'null';

    /**
     * Type could not be determined.
     *
     * A resource field read as `$this->whatever` says nothing about its type.
     * Documenting it as `string` would be a guess presented as a fact, and a
     * reader building a client would act on it. JSON Schema expresses "any"
     * by omitting `type` entirely, which is exactly the honest thing here.
     */
    case Any = 'any';

    /**
     * Best-effort mapping from a Laravel validation rule or PHP type name.
     */
    public static function fromHint(string $hint): self
    {
        return match (strtolower($hint)) {
            'int', 'integer', 'numeric' => self::Integer,
            'float', 'double', 'decimal' => self::Number,
            'bool', 'boolean', 'accepted', 'declined' => self::Boolean,
            'array', 'list' => self::Array,
            'object', 'json' => self::Object,
            'null' => self::Null,
            default => self::String,
        };
    }
}
