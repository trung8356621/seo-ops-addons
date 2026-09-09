<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArtifactSplitter;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Canonical semantic handoff for Split Outline → Article Content.
 *
 * Prefers typed clean outline + vocabulary artifacts.
 * Falls back to parsing the legacy marked combined transport blob once.
 * Never leaves transport markers in article_outline / article_vocabulary.
 */
final class SplitOutlineContentSemanticBinder
{
    public function __construct(
        private readonly SectionedFreeArtifactSplitter $splitter = new SectionedFreeArtifactSplitter(),
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function bind(
        array $variables,
        ?string $typedOutlineMarkdown = null,
        ?string $typedVocabularyMarkdown = null,
        ?string $combinedTransportFallback = null,
    ): array {
        $outline = $this->normalizeSemanticMarkdown($typedOutlineMarkdown);
        $vocabulary = $this->normalizeSemanticMarkdown($typedVocabularyMarkdown);

        $needsParse = $outline === '' || $vocabulary === '' || $this->containsTransportMarkers($outline);
        if ($needsParse) {
            $candidates = array_values(array_filter([
                trim((string) ($combinedTransportFallback ?? '')),
                trim((string) ($variables['article_writing_raw_input'] ?? '')),
                trim((string) ($variables['input'] ?? '')),
                $outline,
            ], static fn (string $v): bool => $v !== ''));

            foreach ($candidates as $candidate) {
                if (! $this->containsTransportMarkers($candidate)) {
                    continue;
                }
                $parts = $this->splitter->split($candidate);
                if ($outline === '' || $this->containsTransportMarkers($outline)) {
                    $parsedOutline = $this->normalizeSemanticMarkdown($parts['outline_markdown'] ?? '');
                    if ($parsedOutline !== '') {
                        $outline = $parsedOutline;
                    }
                }
                if ($vocabulary === '') {
                    $parsedVocab = $this->normalizeSemanticMarkdown($parts['vocabulary_raw'] ?? '');
                    if ($parsedVocab !== '') {
                        $vocabulary = $parsedVocab;
                    }
                }
                if ($outline !== '' && $vocabulary !== '' && ! $this->containsTransportMarkers($outline)) {
                    break;
                }
            }
        }

        if ($outline !== '') {
            $variables['article_outline'] = $outline;
            // Sectioned planner prefers article_outline; do not rewrite legacy {{input}}
            // so single_pass can keep the combined transport artifact when required.
        }
        if ($vocabulary !== '') {
            $variables['article_vocabulary'] = $vocabulary;
        }

        return $variables;
    }

    public function containsTransportMarkers(string $markdown): bool
    {
        return str_contains($markdown, ArticleGenerationInputResolver::OUTLINE_START)
            || str_contains($markdown, ArticleGenerationInputResolver::OUTLINE_END)
            || str_contains($markdown, ArticleGenerationInputResolver::VOCABULARY_START)
            || str_contains($markdown, ArticleGenerationInputResolver::VOCABULARY_END);
    }

    private function normalizeSemanticMarkdown(?string $markdown): string
    {
        $text = trim((string) $markdown);
        if ($text === '') {
            return '';
        }

        if (! $this->containsTransportMarkers($text)) {
            return $text;
        }

        $parts = $this->splitter->split($text);
        $outline = trim((string) ($parts['outline_markdown'] ?? ''));
        if ($outline !== '') {
            return $outline;
        }

        $text = (string) preg_replace('/^\s*\[START_TASK_[^\]]+\]\s*/u', '', $text);
        $text = (string) preg_replace('/\s*\[END_TASK_[^\]]+\]\s*$/u', '', $text);

        return trim($text);
    }
}