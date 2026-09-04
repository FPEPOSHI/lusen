<?php

declare(strict_types=1);

namespace Lusen\Extract\Resources;

use Lusen\Ir\Schema;

/**
 * What a controller action was found to return.
 */
final readonly class ResourceReturn
{
    /**
     * @param  class-string|null  $resource  the JsonResource involved, if any
     * @param  Schema|null  $literal  a shape read straight from response()->json([...])
     */
    public function __construct(
        public ?string $resource = null,
        public bool $collection = false,
        public bool $paginated = false,
        public ?Schema $literal = null,
        public ?int $status = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->resource === null && $this->literal === null && $this->status === null;
    }
}
