<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCompositionPreviewService;
use Illuminate\Support\HtmlString;

/**
 * Prompt Editor preview panel.
 * Default: Runtime Rules (Built-in) — no compose.
 * Debug expand: full Effective Prompt via existing composition preview service.
 */
class PromptCompositionSummaryPresenter
{
    public function __construct(
        private readonly PromptRuntimeRulesPresenter $runtimeRules,
        private readonly PromptHookCompositionPreviewService $compositionPreview,
    ) {}

    /**
     * @param  array<string, mixed>  $hookSettings
     */
    public function renderHtml(
        string $hookKey,
        string $hookVersion,
        string $markdownContent,
        array $hookSettings = [],
        bool $expanded = false,
    ): HtmlString {
        if (! $expanded) {
            return $this->runtimeRules->renderHtml($hookKey, $hookVersion);
        }

        $preview = $this->compositionPreview->preview(
            trim($hookKey),
            trim($hookVersion),
            (string) $markdownContent,
            $hookSettings,
        );

        $full = $this->compositionPreview->formatPreviewHtml($preview);
        $hint = '<p class="text-xs text-amber-700 dark:text-amber-300 mb-2">'
            .e($this->t(
                'seo-content-ai::filament.prompt.composed_preview_expanded_hint',
                'Debug: showing full Effective Prompt. Turn off the toggle to return to Runtime Rules.',
            ))
            .'</p>';

        return new HtmlString($hint.$full);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private function t(string $key, string $fallback, array $replace = []): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                $text = $fallback;
                foreach ($replace as $search => $value) {
                    $text = str_replace(':'.$search, (string) $value, $text);
                }

                return $text;
            }
            $translated = __($key, $replace);
            if (is_array($translated)) {
                return $fallback;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
