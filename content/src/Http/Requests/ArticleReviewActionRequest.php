<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Requests;

use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ArticleReviewActionRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in([
                ArticleReviewActionType::SubmitReview->value,
                ArticleReviewActionType::Approve->value,
                ArticleReviewActionType::Archive->value,
                ArticleReviewActionType::Reopen->value,
                ArticleReviewActionType::Unapprove->value,
            ])],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function actionType(): ArticleReviewActionType
    {
        return ArticleReviewActionType::from((string) $this->validated('action'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
