<?php

declare(strict_types=1);

namespace Lusen\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The rules every order request shares, in the base class rather than copied
 * into each subclass - reached through `parent::rules()`.
 */
abstract class BaseOrderRequest extends FormRequest
{
    public const MAX_PARCELS = 12;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Your own key for this order. A retry with the same key replays
             * the first result.
             *
             * @example 7b1f0c2e
             */
            'idempotency_key' => 'required|string|size:8',
        ];
    }
}
