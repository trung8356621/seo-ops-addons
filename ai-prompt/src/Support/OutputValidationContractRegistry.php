<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Prompt-type keyed output validation contracts.
 * Prevents article min-word validators from leaking into Outline/Vocabulary/Meta/FAQ.
 */
final class OutputValidationContractRegistry
{
    public const CONTRACT_ARTICLE_CONTENT = 'article.content.generate';

    public const CONTRACT_ARTICLE_REWRITE = 'article.content.rewrite';

    public const CONTRACT_ARTICLE_OUTLINE = 'article.outline.generate';

    public const CONTRACT_ARTICLE_OUTLINE_STRUCTURE = 'article.outline.structure.generate';

    public const CONTRACT_VOCABULARY = 'article.vocabulary.generate';

    public const CONTRACT_META_DESCRIPTION = 'article.meta_description_suggestion';

    public const CONTRACT_FAQ = 'article.faq.generate';

    /**
     * @return array{
     *     contract: string,
     *     validators: list<string>,
     *     allows_article_min_words: bool,
     *     length_unit: ?string
     * }
     */
    public function resolve(?string $canonicalPromptKey, ?string $hookKey = null): array
    {
        $key = $this->canonicalize($canonicalPromptKey ?? $hookKey ?? '');

        return match (true) {
            $this->isArticleContent($key) => [
                'contract' => self::CONTRACT_ARTICLE_CONTENT,
                'validators' => ['non_empty', 'article_min_words', 'article_target_words'],
                'allows_article_min_words' => true,
                'length_unit' => 'words',
            ],
            $this->isArticleRewrite($key) => [
                'contract' => self::CONTRACT_ARTICLE_REWRITE,
                'validators' => ['non_empty', 'article_min_words', 'article_target_words'],
                'allows_article_min_words' => true,
                'length_unit' => 'words',
            ],
            $this->isOutline($key) => [
                'contract' => str_contains($key, 'structure')
                    ? self::CONTRACT_ARTICLE_OUTLINE_STRUCTURE
                    : self::CONTRACT_ARTICLE_OUTLINE,
                'validators' => ['non_empty', 'outline_structure'],
                'allows_article_min_words' => false,
                'length_unit' => 'chars',
            ],
            $this->isVocabulary($key) => [
                'contract' => self::CONTRACT_VOCABULARY,
                'validators' => ['non_empty', 'vocabulary_list'],
                'allows_article_min_words' => false,
                'length_unit' => 'chars',
            ],
            $this->isMeta($key) => [
                'contract' => self::CONTRACT_META_DESCRIPTION,
                'validators' => ['non_empty', 'meta_char_range'],
                'allows_article_min_words' => false,
                'length_unit' => 'chars',
            ],
            $this->isFaq($key) => [
                'contract' => self::CONTRACT_FAQ,
                'validators' => ['non_empty', 'faq_structure'],
                'allows_article_min_words' => false,
                'length_unit' => null,
            ],
            default => [
                'contract' => $key !== '' ? $key : 'unknown',
                'validators' => ['non_empty'],
                'allows_article_min_words' => false,
                'length_unit' => null,
            ],
        };
    }

    public function allowsArticleMinWords(?string $canonicalPromptKey, ?string $hookKey = null): bool
    {
        return (bool) $this->resolve($canonicalPromptKey, $hookKey)['allows_article_min_words'];
    }

    private function canonicalize(string $key): string
    {
        return strtolower(trim($key));
    }

    private function isArticleContent(string $key): bool
    {
        return $key === self::CONTRACT_ARTICLE_CONTENT
            || $key === 'article_content'
            || str_ends_with($key, '.content.generate')
            || $key === 'article.content';
    }

    private function isArticleRewrite(string $key): bool
    {
        return $key === self::CONTRACT_ARTICLE_REWRITE
            || str_contains($key, 'content.rewrite');
    }

    private function isOutline(string $key): bool
    {
        return str_contains($key, 'outline')
            || $key === 'article_outline'
            || $key === 'outline';
    }

    private function isVocabulary(string $key): bool
    {
        return str_contains($key, 'vocabulary')
            || $key === 'article_vocabulary';
    }

    private function isMeta(string $key): bool
    {
        return str_contains($key, 'meta_description')
            || str_contains($key, 'meta-description')
            || $key === 'meta';
    }

    private function isFaq(string $key): bool
    {
        return str_contains($key, 'faq');
    }
}
