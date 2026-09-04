<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => new UserResource($this->author),
        ];
    }
}
