<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;

/**
 * Reconstruct a natural FINAL anchor from source HTML after destination discovery.
 *
 * SEARCH PROBE (sliding window) ≠ FINAL ANCHOR.
 * Deterministic, no AI/NLP — structure + verbatim occurrence only.
 */
final class InternalLinkFinalAnchorReconstructor
{
    public const REASON_DESTINATION_FOCUS = 'destination_focus_keyword';

    public const REASON_INVENTORY_KEYWORD = 'inventory_keyword';

    public const REASON_STRONG_SPAN = 'strong_span';

    public const REASON_HEADING = 'heading_span';

    public const REASON_NATURAL_EXPANSION = 'natural_expansion';

    public const REASON_PROBE_CLEAN = 'probe_already_clean';

    /**
     * Connector / function words that must not sit on final-anchor boundaries.
     *
     * @var list<string>
     */
    private const CONNECTORS = [
        'và', 'hoặc', 'của', 'cho', 'với', 'trong', 'theo', 'từ', 'đến', 'là',
        'mà', 'thì', 'bị', 'được', 'các', 'những', 'một', 'như', 'để', 'khi',
        'nếu', 'về', 'tại', 'hay', 'and', 'or', 'of', 'for', 'to', 'in', 'on',
        'the', 'a', 'an',
        // ascii-folded mirrors
        'va', 'hoac', 'cua', 'voi', 'tu', 'den', 'la', 'ma', 'thi', 'bi', 'duoc',
        'cac', 'nhung', 'mot', 'nhu', 'de', 'neu', 've', 'tai',
    ];

    /**
     * @param  array{
     *     destination_focus_keyword?: string,
     *     inventory_keyword?: string,
     *     destination_title?: string
     * }  $options
     * @return array{anchor: string, reason: string, source_context: string}|null
     */
    public static function reconstruct(string $html, string $probe, array $options = []): ?array
    {
        $probe = self::normalizeDisplay($probe);
        if ($probe === '' || trim($html) === '') {
            return null;
        }

        $htmlWithoutLinks = self::stripAnchorTagsKeepTextAsSpace($html);
        $plain = self::plainTextFromHtml($htmlWithoutLinks);
        if ($plain === '' || ! KeywordPhraseMatcher::contains($plain, $probe)) {
            return null;
        }

        $block = self::blockContainingProbe($htmlWithoutLinks, $probe) ?? $plain;
        $context = self::clipContext($block, $probe);

        $candidates = [];

        $destFocus = self::normalizeDisplay((string) ($options['destination_focus_keyword'] ?? ''));
        if ($destFocus !== '' && self::occursIn($plain, $destFocus)) {
            $candidates[] = [$destFocus, self::REASON_DESTINATION_FOCUS];
        }

        $inventory = self::normalizeDisplay((string) ($options['inventory_keyword'] ?? ''));
        if ($inventory !== '' && self::occursIn($plain, $inventory)) {
            $candidates[] = [$inventory, self::REASON_INVENTORY_KEYWORD];
        }

        foreach (self::taggedSpansContainingProbe($htmlWithoutLinks, $probe, ['strong', 'b', 'mark']) as $span) {
            $candidates[] = [$span, self::REASON_STRONG_SPAN];
        }

        foreach (self::headingSpansContainingProbe($htmlWithoutLinks, $probe) as $span) {
            $candidates[] = [$span, self::REASON_HEADING];
        }

        // Prefer a clean probe itself before expanding into a longer noun phrase.
        $candidates[] = [$probe, self::REASON_PROBE_CLEAN];

        foreach (self::naturalExpansions($block, $probe) as $span) {
            $candidates[] = [$span, self::REASON_NATURAL_EXPANSION];
        }

        $seen = [];
        foreach ($candidates as [$candidate, $reason]) {
            $candidate = self::normalizeDisplay($candidate);
            if ($candidate === '') {
                continue;
            }
            $key = KeywordPhraseMatcher::normalize($candidate);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (! self::isValidFinalAnchor($candidate, $plain, $block)) {
                continue;
            }

            return [
                'anchor' => $candidate,
                'reason' => $reason,
                'source_context' => $context,
            ];
        }

        return null;
    }

    /**
     * Structural + quality gate for a FINAL anchor (not a search probe).
     */
    public static function isValidFinalAnchor(string $anchor, string $plainSource, string $blockSource = ''): bool
    {
        $anchor = self::normalizeDisplay($anchor);
        if ($anchor === '') {
            return false;
        }

        if (! self::occursIn($plainSource, $anchor)) {
            return false;
        }

        // Prefer staying inside the probe's block when known.
        if ($blockSource !== '' && ! self::occursIn($blockSource, $anchor)) {
            // Destination focus / inventory may appear elsewhere in the article — still OK
            // only when the phrase is a clean multi-word entity (checked below).
        }

        if (self::looksLikeTimeOrMeasurement($anchor)) {
            return false;
        }

        if (self::hasConnectorBoundary($anchor)) {
            return false;
        }

        if (self::looksLikeBrokenTokenWindow($anchor)) {
            return false;
        }

        if (self::hasIncompleteEdgeToken($anchor)) {
            return false;
        }

        $quality = InternalLinkAnchorQuality::evaluate($anchor);
        if (! $quality['accepted']) {
            return false;
        }

        return true;
    }

    public static function looksLikeTimeOrMeasurement(string $phrase): bool
    {
        $p = self::normalizeDisplay($phrase);
        if ($p === '') {
            return false;
        }

        // 8h - 17h15 / 8h–17h15 / 8:00 - 17:15
        if (preg_match('/\b\d{1,2}\s*h\s*[-–—]\s*\d{1,2}(h?\d{0,2})?\b/iu', $p) === 1) {
            return true;
        }
        if (preg_match('/\b\d{1,2}:\d{2}\s*[-–—]\s*\d{1,2}:\d{2}\b/u', $p) === 1) {
            return true;
        }

        // 45 x 30 x 15 cm
        if (preg_match('/\b\d+(\s*[x×]\s*\d+){1,}\s*(cm|mm|m|kg|g)?\b/iu', $p) === 1) {
            return true;
        }
        if (preg_match('/\b\d+\s*(cm|mm|kg|g|inch|in|ml|l)\b/iu', $p) === 1) {
            return true;
        }

        // Pure numeric / clock-ish noise
        if (preg_match('/^[\d\s.:h\-–—]+$/iu', $p) === 1) {
            return true;
        }

        return false;
    }

    public static function hasConnectorBoundary(string $phrase): bool
    {
        $tokens = preg_split('/\s+/u', KeywordPhraseMatcher::normalize($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }

        $first = $tokens[0];
        $last = $tokens[count($tokens) - 1];

        return self::isConnector($first) || self::isConnector($last);
    }

    /**
     * Reject spans that start/end on an incomplete compound fragment.
     */
    public static function hasIncompleteEdgeToken(string $phrase): bool
    {
        $tokens = preg_split('/\s+/u', KeywordPhraseMatcher::normalize($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }

        // Starts that almost always mean a mid-window fragment.
        $fragileStarts = [
            'năng', 'nổi', 'ứng', 'chứa', 'dụng', 'kiếm', 'sự',
            'nang', 'noi', 'ung', 'chua', 'dung', 'kiem', 'su',
        ];
        // Ends that mean the compound was cut off.
        $fragileEnds = [
            'năng', 'nổi', 'ngân', 'ứng', 'thông', 'chứa', 'dụng', 'kiếm',
            'nang', 'noi', 'ngan', 'ung', 'thong', 'chua', 'dung', 'kiem',
        ];

        $first = $tokens[0];
        $last = $tokens[count($tokens) - 1];
        $firstAscii = self::toAscii($first);
        $lastAscii = self::toAscii($last);

        if (in_array($first, $fragileStarts, true) || in_array($firstAscii, $fragileStarts, true)) {
            return true;
        }
        if (in_array($last, $fragileEnds, true) || in_array($lastAscii, $fragileEnds, true)) {
            return true;
        }

        return false;
    }

    /**
     * Heuristic: 2-token mid-compound fragments like "dụng linh", "chứa thông", "năng ứng".
     */
    public static function looksLikeBrokenTokenWindow(string $phrase): bool
    {
        $tokens = preg_split('/\s+/u', KeywordPhraseMatcher::normalize($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) === 2) {
            [$a, $b] = $tokens;

            $fragilePrefixes = [
                'dung', 'dụng', 'chua', 'chứa', 'nang', 'năng', 'tinh', 'tính', 'ung', 'ứng',
                'noi', 'nổi', 'thong', 'thông', 'kha', 'khả', 'he', 'hệ', 'kiem', 'kiếm',
                'dau', 'đầu', 'su', 'sự',
            ];
            $fragileSeconds = [
                'linh', 'thong', 'thông', 'ung', 'ứng', 'noi', 'nổi', 'minh', 'nang', 'năng',
                'ket', 'kết', 'nghia', 'nghĩa', 'ngan', 'ngân',
            ];

            $aAscii = self::toAscii($a);
            $bAscii = self::toAscii($b);

            if (in_array($a, $fragilePrefixes, true) || in_array($aAscii, $fragilePrefixes, true)) {
                if (mb_strlen($b) <= 5 || in_array($b, $fragileSeconds, true) || in_array($bAscii, $fragileSeconds, true)) {
                    return true;
                }
            }

            if (mb_strlen($a) <= 4 && mb_strlen($b) <= 4 && ! preg_match('/\d/u', $a.$b)) {
                if (! self::looksLikeStandaloneEntityBigram($a, $b)) {
                    return true;
                }
            }

            return false;
        }

        // 3+ tokens still starting on a fragile prefix are usually mid-phrase windows.
        if (count($tokens) >= 3) {
            $first = $tokens[0];
            $firstAscii = self::toAscii($first);
            $fragilePrefixes = [
                'dung', 'dụng', 'chua', 'chứa', 'nang', 'năng', 'ung', 'ứng',
                'noi', 'nổi', 'kiem', 'kiếm', 'su', 'sự',
            ];
            if (in_array($first, $fragilePrefixes, true) || in_array($firstAscii, $fragilePrefixes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeStandaloneEntityBigram(string $a, string $b): bool
    {
        // Túi xách, quà tặng, chất liệu, tuổi thọ, …
        $known = [
            'tui xach', 'qua tang', 'chat lieu', 'tuoi tho', 'tinh nang', 'linh hoat',
            'thong minh', 'thiet ke', 'he thong', 'ngan chua', 'polyester',
        ];
        $key = self::toAscii($a).' '.self::toAscii($b);

        return in_array($key, $known, true)
            || (mb_strlen($a) >= 4 && mb_strlen($b) >= 4);
    }

    /**
     * @return list<string>
     */
    private static function naturalExpansions(string $block, string $probe): array
    {
        $displayTokens = preg_split('/\s+/u', self::normalizeDisplay($block), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $probeTokens = preg_split('/\s+/u', self::normalizeDisplay($probe), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($displayTokens === [] || $probeTokens === []) {
            return [];
        }

        $probeNorm = array_map(
            static fn (string $t): string => KeywordPhraseMatcher::normalize($t),
            $probeTokens,
        );
        $n = count($displayTokens);
        $pLen = count($probeNorm);
        $start = null;
        for ($i = 0; $i <= $n - $pLen; $i++) {
            $ok = true;
            for ($j = 0; $j < $pLen; $j++) {
                if (KeywordPhraseMatcher::normalize($displayTokens[$i + $j]) !== $probeNorm[$j]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return [];
        }
        $end = $start + $pLen - 1; // inclusive

        // Expand only over noun-like tokens; stop at connectors / verbs / clause glue.
        $left = $start;
        while ($left > 0) {
            $prevTok = $displayTokens[$left - 1];
            $prev = KeywordPhraseMatcher::normalize($prevTok);
            if (self::isConnector($prev) || self::isExpansionStopToken($prevTok)) {
                break;
            }
            $left--;
            if (($end - $left + 1) >= 5) {
                break;
            }
        }
        $right = $end;
        while ($right < $n - 1) {
            $nextTok = $displayTokens[$right + 1];
            $next = KeywordPhraseMatcher::normalize($nextTok);
            if (self::isConnector($next) || self::isExpansionStopToken($nextTok)) {
                break;
            }
            $right++;
            if (($right - $left + 1) >= 5) {
                break;
            }
        }

        $out = [];
        $probeBroken = self::looksLikeBrokenTokenWindow($probe) || self::hasConnectorBoundary($probe);

        // Tight pads around the probe. Broken probes prefer balanced healing (±1 each side).
        $padPlans = [];
        if ($probeBroken) {
            $padPlans = [
                [1, 1],
                [2, 1],
                [1, 2],
                [2, 2],
                [0, 1],
                [1, 0],
                [0, 2],
                [2, 0],
            ];
        } else {
            for ($total = 0; $total <= 3; $total++) {
                for ($padL = 0; $padL <= $total; $padL++) {
                    $padPlans[] = [$padL, $total - $padL];
                }
            }
        }

        foreach ($padPlans as [$padL, $padR]) {
            $l = max($left, $start - $padL);
            $r = min($right, $end + $padR);
            if ($l > $start || $r < $end) {
                continue;
            }
            $span = implode(' ', array_slice($displayTokens, $l, $r - $l + 1));
            $words = KeywordPhraseMatcher::countWords($span);
            if ($words < 2 || $words > 5) {
                continue;
            }
            if (self::looksLikeBrokenTokenWindow($span) || self::hasConnectorBoundary($span)) {
                continue;
            }
            $out[] = $span;
        }

        return array_values(array_unique($out));
    }

    /**
     * Verbs / clause glue that must not be absorbed into a final anchor span.
     */
    private static function isExpansionStopToken(string $token): bool
    {
        if (self::isSentenceBoundaryToken($token)) {
            return true;
        }

        $norm = KeywordPhraseMatcher::normalize($token);
        $ascii = self::toAscii($norm);
        static $stops = null;
        if ($stops === null) {
            $raw = [
                'giúp', 'tăng', 'mang', 'theo', 'khi', 'nếu', 'để', 'vì', 'do',
                'là', 'bền', 'chắc', 'tốt', 'cao', 'thấp', 'nhiều', 'ít',
                'làm', 'cho', 'với', 'trong', 'được', 'bị', 'sẽ', 'đã', 'đang',
                'của', 'và', 'hoặc', 'hay', 'nhưng', 'mà', 'thì',
                'giup', 'tang', 'mang', 'theo', 'khi', 'neu', 'de', 'vi',
                'la', 'ben', 'chac', 'tot', 'cao', 'thap', 'nhieu', 'it',
                'lam', 'duoc', 'bi', 'se', 'da', 'dang', 'cua', 'va', 'hoac',
            ];
            $stops = [];
            foreach ($raw as $item) {
                $n = KeywordPhraseMatcher::normalize($item);
                if ($n !== '') {
                    $stops[$n] = true;
                    $stops[self::toAscii($n)] = true;
                }
            }
        }

        return isset($stops[$norm]) || isset($stops[$ascii]);
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private static function taggedSpansContainingProbe(string $html, string $probe, array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $q = preg_quote(strtolower($tag), '/');
            if (preg_match_all('/<'.$q.'\b[^>]*>(.*?)<\/'.$q.'>/is', $html, $matches) === false) {
                continue;
            }
            foreach ($matches[1] ?? [] as $inner) {
                $text = self::normalizeDisplay(self::plainTextFromHtml((string) $inner));
                if ($text === '' || ! KeywordPhraseMatcher::contains($text, $probe)) {
                    continue;
                }
                // Prefer the whole tagged span if short enough; else expansions inside it.
                if (KeywordPhraseMatcher::countWords($text) <= 6) {
                    $out[] = $text;
                }
                foreach (self::naturalExpansions($text, $probe) as $span) {
                    $out[] = $span;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function headingSpansContainingProbe(string $html, string $probe): array
    {
        $out = [];
        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches) === false) {
            return [];
        }
        foreach ($matches[2] ?? [] as $inner) {
            $text = self::normalizeDisplay(self::plainTextFromHtml((string) $inner));
            if ($text === '' || ! KeywordPhraseMatcher::contains($text, $probe)) {
                continue;
            }
            if (KeywordPhraseMatcher::countWords($text) <= 8) {
                $out[] = $text;
            }
            foreach (self::naturalExpansions($text, $probe) as $span) {
                $out[] = $span;
            }
        }

        return $out;
    }

    private static function blockContainingProbe(string $html, string $probe): ?string
    {
        // Split into block-ish chunks.
        $chunks = preg_split(
            '/<\/(?:p|div|li|h[1-6]|tr|section|article)>|<br\s*\/?>/i',
            $html,
        ) ?: [];
        foreach ($chunks as $chunk) {
            $text = self::normalizeDisplay(self::plainTextFromHtml((string) $chunk));
            if ($text !== '' && KeywordPhraseMatcher::contains($text, $probe)) {
                // Further split sentences.
                $sentences = preg_split('/(?<=[.!?…;])\s+/u', $text) ?: [$text];
                foreach ($sentences as $sentence) {
                    $sentence = self::normalizeDisplay((string) $sentence);
                    if ($sentence !== '' && KeywordPhraseMatcher::contains($sentence, $probe)) {
                        return $sentence;
                    }
                }

                return $text;
            }
        }

        $plain = self::normalizeDisplay(self::plainTextFromHtml($html));
        if ($plain !== '' && KeywordPhraseMatcher::contains($plain, $probe)) {
            return $plain;
        }

        return null;
    }

    private static function occursIn(string $haystack, string $needle): bool
    {
        return KeywordPhraseMatcher::contains($haystack, $needle);
    }

    private static function clipContext(string $block, string $probe): string
    {
        $block = self::normalizeDisplay($block);
        if ($block === '') {
            return '';
        }
        $pos = mb_stripos($block, $probe);
        if ($pos === false) {
            return mb_substr($block, 0, 120);
        }
        $start = max(0, $pos - 40);
        $len = mb_strlen($probe) + 80;

        return mb_substr($block, $start, $len);
    }

    private static function isConnector(string $token): bool
    {
        $norm = KeywordPhraseMatcher::normalize($token);
        if ($norm === '') {
            return true;
        }
        static $set = null;
        if ($set === null) {
            $set = [];
            foreach (self::CONNECTORS as $c) {
                $n = KeywordPhraseMatcher::normalize($c);
                if ($n !== '') {
                    $set[$n] = true;
                }
            }
        }

        return isset($set[$norm]);
    }

    private static function isSentenceBoundaryToken(string $token): bool
    {
        return preg_match('/^[.!?…;:]+$/u', trim($token)) === 1;
    }

    private static function normalizeDisplay(string $phrase): string
    {
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = strip_tags($phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';
        $phrase = trim($phrase);
        // Strip list/label punctuation that leaked from heading probes.
        $phrase = trim($phrase, " \t\n\r\0\x0B:;,.-–—•");

        return trim($phrase);
    }

    private static function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private static function stripAnchorTagsKeepTextAsSpace(string $html): string
    {
        return preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $html) ?? $html;
    }

    private static function toAscii(string $text): string
    {
        $map = [
            'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
            'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
            'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
            'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
            'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
            'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
            'đ'=>'d',
        ];
        $lower = mb_strtolower($text);

        return strtr($lower, $map);
    }
}
