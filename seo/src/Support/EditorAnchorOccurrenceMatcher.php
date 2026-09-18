<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;

/**
 * Editor-boundary filter: destination catalog → actionable body occurrences.
 *
 * Does not select destinations (SiteLinkPolicy stays upstream). Only expands
 * known anchors per destination, exact/normalized-matches against current HTML
 * body, applies occurrence-based longest-match-wins, and returns the article
 * phrase (not destination title) as suggestion text.
 */
final class EditorAnchorOccurrenceMatcher
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public static function filterActionable(array $candidates, string $contentHtml): array
    {
        if ($candidates === []) {
            return [];
        }

        $plain = self::plainTextExcludingAnchors($contentHtml);
        if ($plain === '') {
            return [];
        }

        $tokens = self::tokenizeWithOffsets($plain);
        if ($tokens === []) {
            return [];
        }

        /** @var array<string, array<string, array{phrase: string, item: array<string, mixed>}>> $anchorsByHref */
        $anchorsByHref = [];
        foreach ($candidates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            $anchor = trim((string) ($row['text'] ?? ''));
            if ($href === '' || $anchor === '') {
                continue;
            }
            $hrefKey = SeoSuggestionUrlNormalizer::normalize($href);
            if ($hrefKey === '') {
                $hrefKey = mb_strtolower($href, 'UTF-8');
            }
            $anchorKey = KeywordPhraseMatcher::normalize($anchor);
            if ($anchorKey === '') {
                continue;
            }

            if (! isset($anchorsByHref[$hrefKey][$anchorKey])) {
                $anchorsByHref[$hrefKey][$anchorKey] = [
                    'phrase' => $anchor,
                    'item' => $row,
                ];
            }
        }

        if ($anchorsByHref === []) {
            return [];
        }

        /** @var list<array{start: int, end: int, length: int, matched: string, href_key: string, item: array<string, mixed>}> $matches */
        $matches = [];
        foreach ($anchorsByHref as $hrefKey => $anchors) {
            foreach ($anchors as $anchorKey => $meta) {
                foreach (self::findExactOccurrences($tokens, $anchorKey) as $hit) {
                    $matches[] = [
                        'start' => $hit['start'],
                        'end' => $hit['end'],
                        'length' => $hit['end'] - $hit['start'],
                        'matched' => $hit['matched'],
                        'href_key' => $hrefKey,
                        'item' => $meta['item'],
                    ];
                }
            }
        }

        if ($matches === []) {
            return [];
        }

        usort(
            $matches,
            static function (array $a, array $b): int {
                if ($a['start'] !== $b['start']) {
                    return $a['start'] <=> $b['start'];
                }

                return $b['length'] <=> $a['length'];
            },
        );

        /** @var list<array{start: int, end: int, length: int, matched: string, href_key: string, item: array<string, mixed>}> $selected */
        $selected = [];
        foreach ($matches as $match) {
            foreach ($selected as $kept) {
                if (! ($match['end'] <= $kept['start'] || $match['start'] >= $kept['end'])) {
                    continue 2;
                }
            }
            $selected[] = $match;
        }

        $out = [];
        $seen = [];
        foreach ($selected as $match) {
            $matched = trim((string) $match['matched']);
            $matchedKey = KeywordPhraseMatcher::normalize($matched);
            $dedupeKey = $match['href_key'].'|'.$matchedKey;
            if ($matchedKey === '' || isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $item = $match['item'];
            $href = trim((string) ($item['href'] ?? $item['target_url'] ?? ''));
            $item['text'] = $matched;
            $item['href'] = $href;
            $item['target_url'] = trim((string) ($item['target_url'] ?? $href)) ?: $href;
            $item['matched_phrase'] = $matched;
            $out[] = $item;
        }

        return $out;
    }

    public static function plainTextExcludingAnchors(string $html): string
    {
        $withoutLinks = preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($withoutLinks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * @return list<array{raw: string, norm: string, start: int, end: int}>
     */
    public static function tokenizeWithOffsets(string $plain): array
    {
        $text = $plain;
        if ($text === '') {
            return [];
        }

        if (! preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as [$raw, $byteOffset]) {
            $raw = (string) $raw;
            $norm = KeywordPhraseMatcher::normalize($raw);
            if ($norm === '') {
                continue;
            }
            $start = self::byteOffsetToCharOffset($text, (int) $byteOffset);
            $tokens[] = [
                'raw' => $raw,
                'norm' => $norm,
                'start' => $start,
                'end' => $start + mb_strlen($raw, 'UTF-8'),
            ];
        }

        return $tokens;
    }

    /**
     * @param  list<array{raw: string, norm: string, start: int, end: int}>  $tokens
     * @return list<array{start: int, end: int, matched: string}>
     */
    public static function findExactOccurrences(array $tokens, string $normalizedPhrase): array
    {
        $needle = KeywordPhraseMatcher::normalize($normalizedPhrase);
        if ($needle === '' || $tokens === []) {
            return [];
        }

        $needleTokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($needleTokens === []) {
            return [];
        }

        $needleCount = count($needleTokens);
        $haystackNorm = array_map(static fn (array $t): string => $t['norm'], $tokens);
        $out = [];

        for ($i = 0; $i <= count($haystackNorm) - $needleCount; $i++) {
            $slice = array_slice($haystackNorm, $i, $needleCount);
            if ($slice !== $needleTokens) {
                continue;
            }
            $from = $tokens[$i]['start'];
            $to = $tokens[$i + $needleCount - 1]['end'];
            $matchedParts = [];
            for ($j = 0; $j < $needleCount; $j++) {
                $matchedParts[] = $tokens[$i + $j]['raw'];
            }
            $out[] = [
                'start' => $from,
                'end' => $to,
                'matched' => implode(' ', $matchedParts),
            ];
        }

        return $out;
    }

    private static function byteOffsetToCharOffset(string $text, int $byteOffset): int
    {
        if ($byteOffset <= 0) {
            return 0;
        }
        $prefix = substr($text, 0, $byteOffset);

        return mb_strlen($prefix === false ? '' : $prefix, 'UTF-8');
    }
}
