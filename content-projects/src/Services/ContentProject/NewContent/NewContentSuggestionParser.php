<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Parses keyword.discovery.structured output.
 * Backward compatible: string list or objects with keyword / suggested_title / title / title_idea.
 *
 * @phpstan-type Candidate array{
 *   keyword: string,
 *   title: string,
 *   description: string,
 *   fingerprint: string,
 *   suggestion_reason: string,
 *   source_signal: string
 * }
 */
final class NewContentSuggestionParser
{
    private const SOURCE_SIGNALS = [
        'keyword_gap',
        'cluster_gap',
        'mcp_signal',
        'related_topic',
        'manual_focus',
    ];

    /**
     * @return array{candidates: list<Candidate>, generated: int, invalid: int}
     */
    public function parse(mixed $value, int $requested): array
    {
        $rows = $this->flattenRows($value);
        $candidates = [];
        $invalid = 0;
        $seen = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);
            if ($parsed === null) {
                $invalid++;

                continue;
            }

            $fp = $parsed['fingerprint'];
            if (isset($seen[$fp])) {
                $invalid++;

                continue;
            }
            $seen[$fp] = true;
            $candidates[] = $parsed;
            if (count($candidates) >= $requested) {
                break;
            }
        }

        return [
            'candidates' => $candidates,
            'generated' => count($rows),
            'invalid' => $invalid,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function flattenRows(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return $this->flattenRows($decoded);
                }
            }

            return preg_split('/\R+/u', $trimmed) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        if ($this->isAssoc($value)) {
            foreach (['items', 'keywords', 'suggestions', 'results', 'data'] as $key) {
                if (isset($value[$key]) && is_array($value[$key])) {
                    return array_values($value[$key]);
                }
            }
            if (isset($value['keyword']) || isset($value['suggested_title']) || isset($value['title'])) {
                return [$value];
            }

            return array_values($value);
        }

        return array_values($value);
    }

    /**
     * @return Candidate|null
     */
    private function parseRow(mixed $row): ?array
    {
        $keyword = '';
        $title = '';
        $description = '';
        $suggestionReason = '';
        $sourceSignal = '';

        if (is_string($row)) {
            $keyword = trim($row);
        } elseif (is_array($row)) {
            $keyword = trim((string) ($row['keyword'] ?? $row['phrase'] ?? $row['query'] ?? ''));
            $title = trim((string) ($row['suggested_title'] ?? $row['title_idea'] ?? $row['title'] ?? ''));
            $suggestionReason = trim((string) ($row['suggestion_reason'] ?? $row['why'] ?? ''));
            $sourceSignal = strtolower(trim((string) ($row['source_signal'] ?? '')));
            $description = trim((string) ($row['description'] ?? $row['reason'] ?? $row['topic'] ?? ''));
            if ($suggestionReason === '' && $description !== '') {
                $suggestionReason = $description;
            }
            if ($description === '' && $suggestionReason !== '') {
                $description = $suggestionReason;
            }
            if (! in_array($sourceSignal, self::SOURCE_SIGNALS, true)) {
                $sourceSignal = '';
            }
            if (mb_strlen($suggestionReason) > 160) {
                $suggestionReason = mb_substr($suggestionReason, 0, 157).'…';
            }
        } else {
            return null;
        }

        if ($keyword === '' && $title === '') {
            return null;
        }
        if ($keyword === '') {
            $keyword = $title;
        }
        if ($title === '') {
            $title = $keyword;
        }

        return [
            'keyword' => $keyword,
            'title' => $title,
            'description' => $description,
            'fingerprint' => NewContentSuggestionIdentity::fingerprint($keyword, $title),
            'suggestion_reason' => $suggestionReason,
            'source_signal' => $sourceSignal,
        ];
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }
}
