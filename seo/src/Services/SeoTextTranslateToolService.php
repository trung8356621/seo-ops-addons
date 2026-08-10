<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use App\Models\Site;

/**
 * Dịch đoạn văn độc lập bằng prompt Workflows (translate_article_prompt_id).
 * Biến: {{input}}, {{language}}.
 */
final class SeoTextTranslateToolService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly SitePolylangService $polylang,
    ) {}

    public function translate(string $input, string $languageSlug, ?Site $site = null): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.tools.translate_empty_input'),
            );
        }

        $languageSlug = trim($languageSlug);
        if ($languageSlug === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.tools.translate_empty_language'),
            );
        }

        $prompt = $this->resolvePrompt();
        $targetLanguage = $this->polylang->languageEnglishName($languageSlug);

        try {
            $result = $this->promptRunner->run($prompt, [
                'input' => $input,
                'language' => $targetLanguage,
                'post_content' => $input,
            ]);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $output = trim((string) ($result->output_text ?? ''));
        if ($output === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_empty_output'),
            );
        }

        return $output;
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getTranslateArticlePromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_no_prompt'),
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_prompt_missing'),
            );
        }

        return $prompt;
    }
}
