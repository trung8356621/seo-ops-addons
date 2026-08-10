<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

final class WorkflowTagExtractorService
{
    /**
     * @return array{content: string, matched: bool}
     */
    public function extractSegment(string $rawText, string $tagKey): array
    {
        $text = trim($rawText);
        $tagKey = trim($tagKey);

        if ($text === '' || $tagKey === '') {
            return ['content' => '', 'matched' => false];
        }

        $tagTokens = $this->splitTokens($tagKey);
        if ($tagTokens === []) {
            return ['content' => '', 'matched' => false];
        }

        $normalizedTag = implode('_', $tagTokens);
        $pattern = $this->buildExtractPattern($normalizedTag);
        $matched = $this->extractByPattern($text, $pattern);
        if ($matched !== null) {
            return ['content' => $matched, 'matched' => true];
        }

        // Fallback: try less strict tag by stripping separators from key.
        $compact = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $tagKey) ?? '');
        if ($compact !== '') {
            $compactPattern = $this->buildExtractPattern($compact);
            $compactMatched = $this->extractByPattern($text, $compactPattern);
            if ($compactMatched !== null) {
                return ['content' => $compactMatched, 'matched' => true];
            }
        }

        return ['content' => '', 'matched' => false];
    }

    /**
     * @return list<array{id: string, key: string, label: string}>
     */
    public function detectTagsFromPromptTemplate(string $template): array
    {
        $template = trim($template);
        if ($template === '') {
            return [];
        }

        preg_match_all('/\[\s*START\s+([^\]]+)\]/iu', $template, $matches, PREG_SET_ORDER);

        $results = [];
        $seen = [];
        foreach ($matches as $match) {
            $rawTag = trim((string) ($match[1] ?? ''));
            if ($rawTag === '') {
                continue;
            }

            $tokens = $this->splitTokens($rawTag);
            if ($tokens === []) {
                continue;
            }

            $key = implode('_', $tokens);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $results[] = [
                'id' => strtolower($key),
                'key' => $key,
                'label' => str_replace('_', ' ', $key),
            ];
        }

        return $results;
    }

    private function buildExtractPattern(string $normalizedTag): string
    {
        $tokens = $this->splitTokens($normalizedTag);
        $flexTag = implode('[\\s_:-]*', array_map(static fn (string $token): string => preg_quote($token, '#'), $tokens));

        return '#\[\s*START[\s_:-]*' . $flexTag . '\s*\](.*?)\[\s*END[\s_:-]*' . $flexTag . '\s*\]#is';
    }

    private function extractByPattern(string $text, string $pattern): ?string
    {
        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function splitTokens(string $tag): array
    {
        $parts = preg_split('/[\s_:\-]+/u', strtoupper(trim($tag))) ?: [];

        return array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts), static fn (string $part): bool => $part !== ''));
    }
}

