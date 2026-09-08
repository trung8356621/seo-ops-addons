<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Provider-boundary isolation: sectioned_free must never send whole-article prompts.
 */
final class SectionedFreePromptIsolationGuard
{
    public const FAILURE_CODE = 'SECTIONED_FREE_PROMPT_ISOLATION_VIOLATION';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_MARKERS = [
        '[START_TASK_2_VOCABULARY]',
        '[END_TASK_2_VOCABULARY]',
        ArticleGenerationInputResolver::VOCABULARY_START,
        ArticleGenerationInputResolver::VOCABULARY_END,
        'DYNAMIC WORD ALLOCATION',
        'STRICT LENGTH REQUIREMENT',
        'ARTICLE BODY ONLY',
        'ROLE & GOAL',
        '1900–2100',
        '1900-2100',
        'target = 2000',
        'Target word count = 2000',
        'target word count = 2000',
    ];

    /**
     * @param  array{section_id?: string|null, run_id?: string|null}  $context
     */
    public function assertSectionPromptIsIsolated(string $prompt, array $context = []): void
    {
        foreach (self::FORBIDDEN_MARKERS as $marker) {
            if ($marker !== '' && str_contains($prompt, $marker)) {
                throw new PromptRunException(
                    self::FAILURE_CODE.': section provider prompt contains forbidden whole-article marker ['.$marker.'].',
                    0,
                    null,
                    [
                        'failure_code' => self::FAILURE_CODE,
                        'forbidden_marker' => $marker,
                        'section_id' => $context['section_id'] ?? null,
                        'run_id' => $context['run_id'] ?? null,
                        'retryable' => false,
                    ],
                );
            }
        }

        // Article-target leakage: "2000 words" / "2000 từ" style whole-article budgets.
        if (preg_match('/\b(1900|2000|2100)\b/u', $prompt) === 1
            && preg_match('/\b(words?|từ)\b/iu', $prompt) === 1
            && ! preg_match('/\b(250|300|350)\b/u', $prompt)
        ) {
            throw new PromptRunException(
                self::FAILURE_CODE.': section provider prompt appears to contain whole-article word target.',
                0,
                null,
                [
                    'failure_code' => self::FAILURE_CODE,
                    'forbidden_marker' => 'whole_article_word_target',
                    'section_id' => $context['section_id'] ?? null,
                    'run_id' => $context['run_id'] ?? null,
                    'retryable' => false,
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function forbiddenMarkers(): array
    {
        return self::FORBIDDEN_MARKERS;
    }
}
