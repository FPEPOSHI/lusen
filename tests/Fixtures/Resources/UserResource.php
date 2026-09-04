<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exercises the shapes the reader has to survive: name-typed fields, explicit
 * casts, a literal, a nested resource, a nested collection, a conditional
 * field and a nested array literal.
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'orders_count' => $this->orders_count,
            'is_active' => $this->is_active,
            'reputation' => (int) $this->reputation,
            'balance' => (float) $this->balance,
            'verified' => (bool) $this->verified,
            'nickname' => (string) $this->nickname,
            'kind' => 'user',
            'created_at' => $this->created_at,
            'address' => [
                'city' => $this->city,
                'postcode' => $this->postcode,
            ],
            'team' => new TeamResource($this->team),
            'posts' => PostResource::collection($this->posts),
            'settings' => $this->whenLoaded('settings', (array) $this->settings),
            'mystery' => $this->mystery,
        ];
    }
}
