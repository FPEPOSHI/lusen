<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Lusen\Tests\Fixtures\Models\Widget;

/**
 * Nothing here says what any field is. Every type in the generated schema has
 * to come from the model behind it.
 *
 * @mixin Widget
 */
final class WidgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'stock' => $this->stock,
            'price' => $this->price,
            'weight' => $this->weight,
            'featured' => $this->featured,
            'settings' => $this->settings,
            'condition' => $this->condition,
            'status' => $this->status,
            'reference' => $this->reference,
            'released_at' => $this->released_at,
            'undocumented' => $this->undocumented,
        ];
    }
}
