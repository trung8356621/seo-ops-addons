<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PromptHookExecuteRequest extends FormRequest
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
            'article_id' => ['required', 'integer', 'min:1'],
            'input' => ['nullable', 'array'],
            // Không nhận prompt_id từ client — backend resolve từ settings.
        ];
    }

    public function articleId(): int
    {
        return (int) $this->input('article_id');
    }

    /**
     * Runtime override only — field-level validation thuộc Hook InputResolver.
     *
     * @return array<string, mixed>
     */
    public function runtimeInput(): array
    {
        $input = $this->input('input', []);

        return is_array($input) ? $input : [];
    }
}
