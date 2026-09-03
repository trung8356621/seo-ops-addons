<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

/**
 * Registry of hook → split/budget strategy. Unknown hooks = direct-fit (no semantic split).
 */
final class PromptSplitStrategyRegistry
{
    /** @var array<string, PromptSplitStrategy> */
    private array $byHook = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(PromptSplitStrategy $strategy): void
    {
        $this->byHook[$strategy->hookKey()] = $strategy;
    }

    public function forHook(?string $hookKey): PromptSplitStrategy
    {
        $hook = trim((string) $hookKey);
        if ($hook !== '' && isset($this->byHook[$hook])) {
            return $this->byHook[$hook];
        }

        return new DirectFitStrategy($hook !== '' ? $hook : 'unknown', PromptSplitClass::DirectFit);
    }

    /**
     * @return array<string, string>
     */
    public function classificationMap(): array
    {
        $out = [];
        foreach ($this->byHook as $hook => $strategy) {
            $out[$hook] = $strategy->splitClass()->value;
        }

        return $out;
    }

    private function registerDefaults(): void
    {
        foreach ([
            'article.title_suggestion' => 256,
            'article.meta_description_suggestion' => 320,
            'article.faq.generate' => 800,
            'article.featured_snippet.generate' => 512,
            'article.comment.generate' => 400,
        ] as $hook => $reserve) {
            $this->register(new DirectFitStrategy($hook, PromptSplitClass::DirectFit, $reserve));
        }

        // Business-split already exists (outline ↔ vocabulary). Still needs budget preflight per call.
        foreach ([
            'article.outline.generate',
            'article.outline.structure.generate',
            'article.vocabulary.generate',
        ] as $hook) {
            $this->register(new DirectFitStrategy($hook, PromptSplitClass::BusinessSplit, 2048));
        }

        // Long-form generate — real splitter/merger (supportsSplit=true).
        $this->register(new LongFormArticleSplitStrategy('article.content.generate'));

        // Rewrite / improve / translate — HTML-safe blocks + merger.
        foreach ([
            'article.content.rewrite',
            'article.content.translate',
            'article.content.improve',
        ] as $hook) {
            $this->register(new HtmlSafeRewriteSplitStrategy($hook));
        }

        $this->register(new KeywordDiscoveryBudgetStrategy());
    }
}
