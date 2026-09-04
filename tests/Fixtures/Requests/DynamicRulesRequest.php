<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rules built at runtime. Nothing here is statically readable, and the reader
 * must degrade to no parameters rather than failing.
 */
final class DynamicRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (['a', 'b'] as $field) {
            $rules[$field] = 'required|string';
        }

        return $rules;
    }
}
