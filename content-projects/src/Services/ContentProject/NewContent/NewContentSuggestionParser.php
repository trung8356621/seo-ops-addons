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
 *   product_type: string,
 *   gallery_description: string,
 *   fingerprint: string,
 *   suggestion_reason: string,
 *   source_signal: string
 * }
 */
final class NewContentSuggestionParser
{
    /** Matches seo_project_tasks.keyword / title column width. */
    public const MAX_KEYWORD_CHARS = 500;

    /** Matches seo_project_tasks.source_content / keyword column width (widened from 255). */
    public const MAX_SOURCE_CONTENT_CHARS = 500;

    private const SOURCE_SIGNALS = [
        'keyword_gap',
        'cluster_gap',
        'mcp_signal',
        'gsc_signal',
        'related_topic',
        'manual_note',
        'manual_focus', // legacy alias
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
            $decoded = NewContentSuggestionStructuredResult::decode($value);
            if (! $decoded['ok']) {
                // Do NOT scrape prose for the first [...] — that hides prompt failures.
                return [];
            }

            return $this->flattenRows($decoded['value']);
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
        $productType = '';
        $galleryDescription = '';
        $suggestionReason = '';
        $sourceSignal = '';

        if (is_string($row)) {
            $keyword = trim($row);
        } elseif (is_array($row)) {
            $keyword = trim((string) ($row['keyword'] ?? $row['phrase'] ?? $row['query'] ?? ''));
            $title = trim((string) ($row['suggested_title'] ?? $row['title_idea'] ?? $row['title'] ?? ''));
            $suggestionReason = trim((string) ($row['suggestion_reason'] ?? $row['why'] ?? ''));
            $sourceSignal = strtolower(trim((string) ($row['source_signal'] ?? '')));
            $description = trim((string) ($row['description'] ?? ''));
            // Legacy may use brief as planning description when dedicated field absent.
            if ($description === '') {
                $description = trim((string) ($row['brief'] ?? ''));
            }
            $productType = trim((string) ($row['product_type'] ?? $row['loai_san_pham'] ?? ''));
            $galleryDescription = trim((string) ($row['gallery_description'] ?? ''));
            // Do NOT invent description from suggestion_reason (separate semantics).
            // Do NOT copy keyword/description into product_type/gallery_description.
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

        // Reject AI blobs / planning-context echoes accidentally assigned to keyword/title.
        if ($this->looksLikeNonKeywordDump($keyword) || $this->looksLikeNonKeywordDump($title)) {
            return null;
        }

        if (mb_strlen($keyword) > self::MAX_KEYWORD_CHARS) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD_CHARS);
        }
        if (mb_strlen($title) > self::MAX_KEYWORD_CHARS) {
            $title = mb_substr($title, 0, self::MAX_KEYWORD_CHARS);
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 1997).'…';
        }
        if (mb_strlen($productType) > 500) {
            $productType = mb_substr($productType, 0, 497).'…';
        }
        if (mb_strlen($galleryDescription) > 4000) {
            $galleryDescription = mb_substr($galleryDescription, 0, 3997).'…';
        }

        return [
            'keyword' => $keyword,
            'title' => $title,
            'description' => $description,
            'product_type' => $productType,
            'gallery_description' => $galleryDescription,
            'fingerprint' => NewContentSuggestionIdentity::fingerprint($keyword, $title),
            'suggestion_reason' => $suggestionReason,
            'source_signal' => $sourceSignal,
        ];
    }

    /**
     * True when a string looks like a structured suggestion payload, not a keyword.
     */
    private function looksLikeStructuredDump(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        $len = mb_strlen($trimmed);
        $startsStructured = str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{');
        $hasSuggestionKeys = str_contains($trimmed, '"keyword"')
            && (str_contains($trimmed, '"suggested_title"')
                || str_contains($trimmed, '"suggestion_reason"')
                || str_contains($trimmed, '"source_signal"')
                || str_contains($trimmed, '"gallery_description"')
                || str_contains($trimmed, '"product_type"'));

        if ($startsStructured && ($hasSuggestionKeys || $len > 200)) {
            return true;
        }

        if ($hasSuggestionKeys && $len > self::MAX_SOURCE_CONTENT_CHARS) {
            return true;
        }

        return false;
    }

    /**
     * Reject JSON dumps AND AI echoes of planning context (e.g. "Planned items list includes many: …").
     * Real SEO keywords are short; long multi-quote lists must never become source_content.
     */
    private function looksLikeNonKeywordDump(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        if ($this->looksLikeStructuredDump($trimmed)) {
            return true;
        }

        $lower = mb_strtolower($trimmed);
        if (str_contains($lower, 'planned items list includes')
            || str_contains($lower, 'already planned in this draft')
            || str_contains($lower, 'rejected earlier in this draft')
            || str_contains($lower, 'priority gaps / missing directions')
            || str_contains($lower, 'return json array of objects')
        ) {
            return true;
        }

        // Many quoted phrases in one field = pasted keyword inventory, not one keyword.
        if (substr_count($trimmed, '"') >= 6 && mb_strlen($trimmed) > 120) {
            return true;
        }

        // Hard ceiling for a single planning keyword (well below VARCHAR(500)).
        if (mb_strlen($trimmed) > 200) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }
}
