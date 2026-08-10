<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArticleEditorSeoMetaRequest extends FormRequest
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
            'focus_keyword' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function focusKeyword(): string
    {
        return trim((string) $this->input('focus_keyword', ''));
    }

    public function metaDescription(): string
    {
        return trim((string) $this->input('meta_description', ''));
    }

    public function slug(): string
    {
        return trim((string) $this->input('slug', ''));
    }
}
