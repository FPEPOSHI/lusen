<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|in:pending,paid',
        ];
    }
}
