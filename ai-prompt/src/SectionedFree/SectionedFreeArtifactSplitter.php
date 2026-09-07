<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Split outline artifact into outline-only + vocabulary without leaking vocab into writer.
 */
final class SectionedFreeArtifactSplitter
{
    /**
     * @return array{
     *   outline_markdown: string,
     *   vocabulary_raw: string,
     *   vocabulary_persisted: bool,
     *   raw_input: string
     * }
     */
    public function split(string $rawInput): array
    {
        $raw = trim($rawInput);
        $outline = $this->extractBetween(
            $raw,
            ArticleGenerationInputResolver::OUTLINE_START,
            ArticleGenerationInputResolver::OUTLINE_END,
        );
        $vocab = $this->extractBetween(
            $raw,
            ArticleGenerationInputResolver::VOCABULARY_START,
            ArticleGenerationInputResolver::VOCABULARY_END,
        );

        if ($outline !== null && trim($outline) !== '') {
            return [
                'outline_markdown' => trim($outline),
                'vocabulary_raw' => trim((string) ($vocab ?? '')),
                'vocabulary_persisted' => trim((string) ($vocab ?? '')) !== '',
                'raw_input' => $raw,
            ];
        }

        return [
            'outline_markdown' => $this->stripVocabularyBlock($raw),
            'vocabulary_raw' => trim((string) ($vocab ?? '')),
            'vocabulary_persisted' => trim((string) ($vocab ?? '')) !== '',
            'raw_input' => $raw,
        ];
    }

    private function extractBetween(string $raw, string $start, string $end): ?string
    {
        $pattern = '/'.preg_quote($start, '/').'(.*?)'.preg_quote($end, '/').'/isu';
        if (preg_match($pattern, $raw, $m) !== 1) {
            return null;
        }

        return trim((string) $m[1]);
    }

    private function stripVocabularyBlock(string $raw): string
    {
        $pattern = '/'.preg_quote(ArticleGenerationInputResolver::VOCABULARY_START, '/')
            .'.*?'.preg_quote(ArticleGenerationInputResolver::VOCABULARY_END, '/').'/isu';

        $stripped = trim((string) preg_replace($pattern, '', $raw));
        $stripped = (string) preg_replace(
            '/'.preg_quote(ArticleGenerationInputResolver::OUTLINE_START, '/').'/u',
            '',
            $stripped,
        );
        $stripped = (string) preg_replace(
            '/'.preg_quote(ArticleGenerationInputResolver::OUTLINE_END, '/').'/u',
            '',
            $stripped,
        );

        return trim($stripped);
    }
}
