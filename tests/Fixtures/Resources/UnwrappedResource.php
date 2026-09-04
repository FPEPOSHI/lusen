<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class UnwrappedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ['id' => $this->id];
    }
}
