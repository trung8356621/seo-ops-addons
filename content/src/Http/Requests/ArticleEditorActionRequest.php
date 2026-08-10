<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArticleEditorActionRequest extends FormRequest
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
            'html' => ['required', 'string'],
            'client_rendered_html' => ['nullable', 'string'],
            'editor_document' => ['nullable', 'array'],
            'expected_editor_document_hash' => ['nullable', 'string', 'max:128'],
            'seo_analysis' => ['nullable', 'array'],
            'publish_box' => ['nullable', 'array'],
            'publish_box.post_type' => ['nullable', 'string'],
            'publish_box.status' => ['nullable', 'string'],
            'publish_box.visibility' => ['nullable', 'string'],
            'publish_box.publish_day' => ['nullable', 'string'],
            'publish_box.publish_month' => ['nullable', 'string'],
            'publish_box.publish_year' => ['nullable', 'string'],
            'publish_box.publish_hour' => ['nullable', 'string'],
            'publish_box.publish_minute' => ['nullable', 'string'],
            'article_meta' => ['nullable', 'array'],
            'article_meta.title' => ['nullable', 'string'],
            'article_meta.slug' => ['nullable', 'string'],
            'article_meta.seo_meta_description' => ['nullable', 'string'],
            'article_meta.focus_keyword' => ['nullable', 'string'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'expected_updated_at' => ['nullable', 'string'],
            'expected_content_hash' => ['nullable', 'string'],
            'expected_document_version' => ['nullable', 'integer', 'min:1'],
            'editor_session_id' => ['nullable', 'uuid'],
            'save_mode' => ['nullable', 'string', 'in:autosave,explicit'],
            'close_reason' => ['nullable', 'string', 'max:64'],
            'document' => ['nullable'],
            'featured_image' => ['nullable', 'array'],
            'product_album' => ['nullable', 'array'],
            'faqs' => ['nullable', 'array'],
            'faqs_source' => ['nullable', 'string', 'max:32'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('html')) {
            return;
        }

        $document = $this->input('document');
        if (is_string($document)) {
            $this->merge(['html' => $document]);

            return;
        }

        if (is_array($document) && isset($document['html']) && is_string($document['html'])) {
            $this->merge(['html' => $document['html']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function editorBundle(): array
    {
        $bundle = $this->validated();

        // TipTap JSON dual-write + hash fields (must survive validated()).
        foreach (['editor_document', 'expected_editor_document_hash', 'client_rendered_html', 'faqs_source'] as $key) {
            if ($this->exists($key) && ! array_key_exists($key, $bundle)) {
                $bundle[$key] = $this->input($key);
            }
        }

        // Persist path historically reads meta; client sends article_meta.
        if (! isset($bundle['meta']) && isset($bundle['article_meta']) && is_array($bundle['article_meta'])) {
            $bundle['meta'] = $bundle['article_meta'];
        }

        return $bundle;
    }
}
