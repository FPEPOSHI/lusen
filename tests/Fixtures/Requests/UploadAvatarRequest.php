<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadAvatarRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => 'required|image|mimes:jpg,png|max:2048',
            'caption' => 'nullable|string|max:120',
        ];
    }
}
