<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArticleSeoScorePreviewRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:2000'],
            'focus_keyword' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:2000000'],
            'featured_image' => ['nullable', 'array'],
            'featured_image.url' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array'],
        ];
    }

    public function title(): ?string
    {
        $value = trim((string) $this->input('title', ''));

        return $value !== '' ? $value : null;
    }

    public function slug(): ?string
    {
        $value = trim((string) $this->input('slug', ''));

        return $value !== '' ? $value : null;
    }

    public function metaDescription(): ?string
    {
        $value = trim((string) $this->input('meta_description', ''));

        return $value !== '' ? $value : null;
    }

    public function focusKeyword(): ?string
    {
        $value = trim((string) $this->input('focus_keyword', ''));

        return $value !== '' ? $value : null;
    }

    public function content(): string
    {
        return (string) $this->input('content', '');
    }
}
