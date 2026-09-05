<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SeedingTopicStoreRequest extends FormRequest
{
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
            'site_id' => ['required', 'integer', 'min:1'],
            'full_text' => ['sometimes', 'nullable', 'string'],
            'source_html' => ['sometimes', 'nullable', 'string'],
            'social_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
