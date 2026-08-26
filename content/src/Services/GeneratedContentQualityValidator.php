<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Omnichannel\Addons\Content\DataTransfer\GeneratedContentQualityResult;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;

/**
 * Deterministic quality gate for AI-generated article bodies.
 * Detect / classify / report — never rewrite content.
 */
final class GeneratedContentQualityValidator
{
    private const SKIP_TAGS = ['code', 'pre', 'script', 'style', 'kbd', 'samp'];

    /** Isolated foreign-script run ≤ this length may be brand/entity → warning only. */
    private const ISOLATED_FOREIGN_MAX_CHARS = 3;

    /** Reject when ≥ this many separate unexpected-script clusters in Vietnamese prose. */
    private const FOREIGN_CLUSTER_REJECT = 2;

    /** Reject when total unexpected-script letters reach this count (even one long run). */
    private const FOREIGN_TOTAL_CHARS_REJECT = 4;

    /** Reject when ≥ this many high-confidence suspicious letter.dot.letter hits. */
    private const SUSPICIOUS_DOT_REJECT = 2;

    private const TECH_SUFFIXES = [
        'js', 'ts', 'tsx', 'jsx', 'php', 'css', 'scss', 'html', 'htm', 'json', 'xml',
        'yml', 'yaml', 'md', 'txt', 'csv', 'sql', 'py', 'rb', 'go', 'rs', 'java',
        'kt', 'swift', 'sh', 'bat', 'exe', 'dll', 'so', 'wasm', 'map', 'lock',
        'com', 'net', 'org', 'io', 'co', 'vn', 'dev', 'app', 'ai', 'cloud',
    ];

    /**
     * @param  array{language?: string|null, hook_key?: string|null, is_html?: bool}  $context
     */
    public function validate(string $content, array $context = []): GeneratedContentQualityResult
    {
        $content = trim($content);
        if ($content === '') {
            return GeneratedContentQualityResult::pass();
        }

        $language = ArticleLanguageCode::normalize((string) ($context['language'] ?? ''));
        $isHtml = (bool) ($context['is_html'] ?? $this->looksLikeHtml($content));
        $prose = $isHtml ? $this->extractProseText($content) : $this->stripProtectedSegments($content);
        $prose = trim($prose);
        if ($prose === '') {
            return GeneratedContentQualityResult::pass();
        }

        /** @var list<array{rule: string, severity: string, sample: string, context?: string}> $issues */
        $issues = [];

        $this->collectControlCorruption($prose, $issues);
        if ($language === 'vi' || $language === '') {
            // Unknown locale: still run generic corruption; script mixing only aggressive for vi.
            if ($language === 'vi') {
                $this->collectUnexpectedScriptIssues($prose, $issues);
            }
        }
        $this->collectSuspiciousDotIssues($prose, $issues);

        return GeneratedContentQualityResult::fromIssues($issues);
    }

    /**
     * @param  list<array{rule: string, severity: string, sample: string, context?: string}>  $issues
     */
    private function collectControlCorruption(string $prose, array &$issues): void
    {
        if (str_contains($prose, "\u{FFFD}") || str_contains($prose, '�')) {
            $issues[] = [
                'rule' => 'replacement_char',
                'severity' => GeneratedContentQualityResult::SEVERITY_REJECT,
                'sample' => $this->sampleAround($prose, '�') ?? $this->sampleAround($prose, "\u{FFFD}") ?? '�',
            ];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $prose, $m, PREG_OFFSET_CAPTURE) === 1) {
            $issues[] = [
                'rule' => 'control_char',
                'severity' => GeneratedContentQualityResult::SEVERITY_REJECT,
                'sample' => $this->sampleAtByte($prose, (int) $m[0][1]),
            ];
        }
    }

    /**
     * @param  list<array{rule: string, severity: string, sample: string, context?: string}>  $issues
     */
    private function collectUnexpectedScriptIssues(string $prose, array &$issues): void
    {
        // Han / Hiragana / Katakana / Hangul / Arabic / Cyrillic / Thai
        $pattern = '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}\x{0600}-\x{06FF}\x{0400}-\x{04FF}\x{0E00}-\x{0E7F}]+/u';
        if (preg_match_all($pattern, $prose, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return;
        }

        $clusters = [];
        $totalChars = 0;
        foreach ($matches[0] as [$token, $byteOffset]) {
            $token = (string) $token;
            $len = mb_strlen($token);
            $totalChars += $len;
            $charOffset = mb_strlen(substr($prose, 0, (int) $byteOffset));
            $before = mb_substr($prose, max(0, $charOffset - 24), min(24, $charOffset));
            $after = mb_substr($prose, $charOffset + $len, 24);
            $looksLikeBrand = $this->looksLikeLatinBrandEntity($before, $token);
            $clusters[] = [
                'token' => $token,
                'len' => $len,
                'brand' => $looksLikeBrand,
                'sample' => trim($before.$token.$after),
            ];
        }

        $nonBrand = array_values(array_filter(
            $clusters,
            static fn (array $c): bool => ! $c['brand'],
        ));

        if ($nonBrand === []) {
            $issues[] = [
                'rule' => 'unexpected_script',
                'severity' => GeneratedContentQualityResult::SEVERITY_WARNING,
                'sample' => $this->truncate((string) ($clusters[0]['sample'] ?? '')),
                'context' => 'isolated_brand_or_entity',
            ];

            return;
        }

        $reject = count($nonBrand) >= self::FOREIGN_CLUSTER_REJECT
            || $totalChars >= self::FOREIGN_TOTAL_CHARS_REJECT
            || (
                count($nonBrand) === 1
                && (int) $nonBrand[0]['len'] >= 2
                && ! $nonBrand[0]['brand']
            );

        $sample = $this->truncate((string) ($nonBrand[0]['sample'] ?? ''));
        $issues[] = [
            'rule' => 'unexpected_script',
            'severity' => $reject
                ? GeneratedContentQualityResult::SEVERITY_REJECT
                : GeneratedContentQualityResult::SEVERITY_WARNING,
            'sample' => $sample,
            'context' => 'clusters='.count($nonBrand).';chars='.$totalChars,
        ];
    }

    /**
     * @param  list<array{rule: string, severity: string, sample: string, context?: string}>  $issues
     */
    private function collectSuspiciousDotIssues(string $prose, array &$issues): void
    {
        // letter.letter with word chars; exclude protected by prior strip + tech heuristics.
        if (preg_match_all('/(\p{L}{2,})\.(\p{L}{2,})/u', $prose, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        $hits = [];
        foreach ($matches as $match) {
            $left = (string) ($match[1] ?? '');
            $right = (string) ($match[2] ?? '');
            $token = $left.'.'.$right;
            if ($this->isLikelyTechnicalDotToken($left, $right, $token)) {
                continue;
            }
            if (! $this->looksLikeProseDotCorruption($left, $right)) {
                continue;
            }
            $hits[] = $token;
        }

        if ($hits === []) {
            return;
        }

        $unique = array_values(array_unique($hits));
        $severity = count($unique) >= self::SUSPICIOUS_DOT_REJECT
            ? GeneratedContentQualityResult::SEVERITY_REJECT
            : GeneratedContentQualityResult::SEVERITY_WARNING;

        $issues[] = [
            'rule' => 'suspicious_dot_glue',
            'severity' => $severity,
            'sample' => $this->truncate($unique[0]),
            'context' => 'count='.count($unique),
        ];
    }

    private function looksLikeLatinBrandEntity(string $before, string $foreignToken): bool
    {
        // Capitalized ASCII brand/product immediately before foreign run: "Xiaomi 小米", "UNIQLO 优衣库".
        return preg_match('/\b[A-Z][A-Za-z0-9+\-]{1,24}\s*$/u', $before) === 1
            && mb_strlen($foreignToken) <= max(self::ISOLATED_FOREIGN_MAX_CHARS, 6);
    }

    private function looksLikeProseDotCorruption(string $left, string $right): bool
    {
        // High confidence: Vietnamese diacritics on either side, or lowercase continuation.
        $hasViet = preg_match('/[À-ỹà-ỹ]/u', $left.$right) === 1;
        $rightLower = mb_strtolower($right) === $right;
        $leftLower = mb_strtolower($left) === $left;

        return $hasViet || ($leftLower && $rightLower && mb_strlen($right) >= 3);
    }

    private function isLikelyTechnicalDotToken(string $left, string $right, string $token): bool
    {
        $rightLower = mb_strtolower($right);
        if (in_array($rightLower, self::TECH_SUFFIXES, true)) {
            return true;
        }

        // Node.js / Vue.js / utm.source style (ASCII identifier dots).
        if (preg_match('/^[A-Za-z][A-Za-z0-9+\-]*\.[A-Za-z][A-Za-z0-9+\-]*$/u', $token) === 1) {
            return true;
        }

        // Decimal / version fragments already unlikely (need letters both sides).
        return false;
    }

    private function extractProseText(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="omi-quality-root">'.$html.'</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded !== true) {
            return $this->stripProtectedSegments(strip_tags($html));
        }

        $root = $doc->getElementById('omi-quality-root');
        if (! $root instanceof DOMElement) {
            return $this->stripProtectedSegments(strip_tags($html));
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('.//text()', $root);
        if ($nodes === false) {
            return '';
        }

        $parts = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMText || $this->isInsideSkippedElement($node)) {
                continue;
            }
            $text = (string) ($node->nodeValue ?? '');
            if ($text === '') {
                continue;
            }
            $parts[] = $this->stripProtectedSegments($text);
        }

        return trim(implode(' ', $parts));
    }

    private function stripProtectedSegments(string $text): string
    {
        $pattern = '/(?:'
            .'https?:\/\/[^\s<>"\']+'
            .'|www\.[^\s<>"\']+'
            .'|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'
            .'|\b(?:\d{1,3}\.){3}\d{1,3}\b'
            .'|\bv?\d+(?:\.\d+){1,4}\b'
            .'|\b\d+[.,]\d+\b'
            .')/u';

        $stripped = preg_replace($pattern, ' ', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    private function isInsideSkippedElement(DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMNode) {
            if ($parent instanceof DOMElement) {
                $tag = strtolower($parent->tagName);
                if (in_array($tag, self::SKIP_TAGS, true)) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function looksLikeHtml(string $content): bool
    {
        return preg_match('/<\/?[a-z][\s\S]*>/i', $content) === 1;
    }

    private function sampleAround(string $haystack, string $needle): ?string
    {
        $pos = mb_strpos($haystack, $needle);
        if ($pos === false) {
            return null;
        }
        $start = max(0, $pos - 24);

        return $this->truncate(mb_substr($haystack, $start, mb_strlen($needle) + 48));
    }

    private function sampleAtByte(string $haystack, int $byteOffset): string
    {
        $charOffset = mb_strlen(substr($haystack, 0, max(0, $byteOffset)));
        $start = max(0, $charOffset - 24);

        return $this->truncate(mb_substr($haystack, $start, 48));
    }

    private function truncate(string $sample): string
    {
        $sample = preg_replace('/\s+/u', ' ', trim($sample)) ?? trim($sample);

        return mb_strlen($sample) > 80 ? mb_substr($sample, 0, 80).'…' : $sample;
    }
}
