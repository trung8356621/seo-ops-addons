<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Http\Requests;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'severity' => ['required', 'string', Rule::in([
                KeywordReviewStatus::Warning->value,
                KeywordReviewStatus::Danger->value,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
            'article_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reasonId = $this->input('reason_id');
            $customReason = trim((string) $this->input('custom_reason_text', ''));

            $hasReasonId = is_numeric($reasonId) && (int) $reasonId > 0;
            $hasCustomReason = $customReason !== '';

            if (! $hasReasonId && ! $hasCustomReason) {
                $validator->errors()->add(
                    'reason_id',
                    __('seo-content-ai::filament.keyword_review.reason_required'),
                );
            }
        });
    }
}
