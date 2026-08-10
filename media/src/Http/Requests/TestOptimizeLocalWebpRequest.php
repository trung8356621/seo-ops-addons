<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TestOptimizeLocalWebpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'min:1'],
            'seo_media_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
