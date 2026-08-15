<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Http\Requests;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KeywordReviewRequest extends FormRequest
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
            'reason_id' => ['nullable', 'integer', 'min:1'],
            'custom_reason_text' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', Rule::in([
                KeywordReviewStatus::Warning->value,
                KeywordReviewStatus::Danger->value,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
            'article_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:32'],
        ];
    }
}
