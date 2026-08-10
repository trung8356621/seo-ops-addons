<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SeoArticleRevisionRestoreRequest extends FormRequest
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
            'revision_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
