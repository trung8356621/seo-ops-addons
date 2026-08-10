<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Line-oriented AI Markdown import parser.
 *
 * Classification uses normalized copies only; original lines stay intact for body output.
 * Labels match allowlists exactly after prefix stripping — numbered article lists are never bulk-removed.
 */
final class ArticleMarkdownImportParser
{
    /** Workflow wrappers removed from body; content beneath is kept. */
    private const INVISIBLE_STRUCTURE_LABELS = [
        'introduction',
        'intro',
        'opening',
        'opening section',
        'introduction section',
        'main content',
        'article content',
        'body',
        'content',
    ];

    /**
     * Single-word wrappers that often appear as normal prose — only match with a structural signal
     * (# heading, numbering, trailing colon, or full-line emphasis).
     */
    private const AMBIGUOUS_STRUCTURE_LABELS = [
        'intro',
        'opening',
        'body',
        'content',
    ];

    private const META_DESCRIPTION_LABELS = [
        'meta description',
        'meta-description',
        'seo description',
        'description',
    ];

    private const SEO_TITLE_LABELS = [
        'seo title',
    ];

    private const H1_TITLE_LABELS = [
        'h1',
        'h1 title',
        'article title',
        'title',
    ];

    /** Short metadata labels that need colon / heading / numbering when value is absent. */
    private const AMBIGUOUS_METADATA_LABELS = [
        'description',
        'title',
    ];

    /**
     * @return array{
     *     markdown: string,
     *     h1_title: string|null,
     *     seo_title: string|null,
     *     meta_description: string|null,
     *     diagnostics: array{
     *         detected_h1: bool,
     *         detected_seo_title: bool,
     *         detected_meta_description: bool,
     *         removed_structure_labels: list<string>
     *     }
     * }
     */
    public function parse(string $markdown): array
    {
        $markdown = $this->normalizeDocument($markdown);
        if ($markdown === '') {
            return $this->emptyResult();
        }

        if ($this->looksLikeHtmlDocument($markdown)) {
            return [
                'markdown' => $markdown,
                'h1_title' => null,
                'seo_title' => null,
                'meta_description' => null,
                'diagnostics' => [
                    'detected_h1' => false,
                    'detected_seo_title' => false,
                    'detected_meta_description' => false,
                    'removed_structure_labels' => [],
                ],
            ];
        }

        $markdown = $this->unwrapOuterMarkdownFence($markdown);
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $h1Title = null;
        $seoTitle = null;
        $metaDescription = null;
        $removedStructureLabels = [];
        $bodyLines = [];
        $inLeadingMetadata = true;
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '') {
                if (! $inLeadingMetadata) {
                    $bodyLines[] = $line;
                }

                continue;
            }

            if ($inLeadingMetadata && $this->isHorizontalRule($trimmed)) {
                $inLeadingMetadata = false;

                continue;
            }

            $label = $this->parseLabelLine($trimmed);
            if ($label !== null) {
                if ($label['type'] === 'meta_description') {
                    if ($metaDescription === null) {
                        $value = $label['value'];
                        if ($value === null || $value === '') {
                            $value = $this->consumeFollowingMetadataValue($lines, $index);
                        }
                        if ($value !== null && $value !== '') {
                            $metaDescription = $value;
                        }
                    }
                    $index = $this->skipTrailingSeparators($lines, $index + 1) - 1;

                    continue;
                }

                if ($label['type'] === 'seo_title') {
                    if ($seoTitle === null) {
                        $value = $label['value'];
                        if ($value === null || $value === '') {
                            $value = $this->consumeFollowingMetadataValue($lines, $index);
                        }
                        if ($value !== null && $value !== '') {
                            $seoTitle = $this->cleanPlainText($value);
                        }
                    }
                    $index = $this->skipTrailingSeparators($lines, $index + 1) - 1;

                    continue;
                }

                if ($label['type'] === 'h1_title') {
                    if ($h1Title === null) {
                        $value = $label['value'];
                        if ($value === null || $value === '') {
                            $value = $this->consumeFollowingMetadataValue($lines, $index);
                        }
                        if ($value !== null && $value !== '') {
                            $h1Title = $this->cleanPlainText($value);
                        }
                    }
                    $index = $this->skipTrailingSeparators($lines, $index + 1) - 1;

                    continue;
                }

                if ($label['type'] === 'structure') {
                    $removedStructureLabels[] = (string) ($label['normalized'] ?? 'structure');
                    $inLeadingMetadata = false;
                    $inlineStructure = trim((string) ($label['value'] ?? ''));
                    if ($inlineStructure !== '') {
                        $bodyLines[] = $inlineStructure;
                    }

                    continue;
                }
            }

            // Top-level Markdown # H1 (not a metadata/structure label).
            if ($h1Title === null && $this->isTopLevelMarkdownH1($trimmed)) {
                $title = $this->extractTopLevelMarkdownH1($trimmed);
                if ($title !== null) {
                    $h1Title = $title;
                    $inLeadingMetadata = false;

                    continue;
                }
            }

            $inLeadingMetadata = false;
            $bodyLines[] = $line;
        }

        $bodyMarkdown = trim(implode("\n", $bodyLines));

        // Backward compat: SEO Title alone still fills h1_title for callers.
        $resolvedH1 = $h1Title ?? $seoTitle;

        return [
            'markdown' => $bodyMarkdown,
            'h1_title' => $resolvedH1 !== '' ? $resolvedH1 : null,
            'seo_title' => $seoTitle !== '' && $seoTitle !== null ? $seoTitle : null,
            'meta_description' => $metaDescription !== '' && $metaDescription !== null ? $metaDescription : null,
            'diagnostics' => [
                'detected_h1' => $h1Title !== null && $h1Title !== '',
                'detected_seo_title' => $seoTitle !== null && $seoTitle !== '',
                'detected_meta_description' => $metaDescription !== null && $metaDescription !== '',
                'removed_structure_labels' => array_values(array_unique($removedStructureLabels)),
            ],
        ];
    }

    /**
     * Classify a single trimmed line for shared converters / leftover stripping.
     *
     * @return array{type: string, value: string|null, normalized: string|null}|null
     */
    public function parseLabelLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // Case-preserving prefix strip for value extraction; lowercase copy for allowlist.
        $stripped = $this->stripMatchingPrefixes($line);
        $normalized = $this->collapseForMatching($stripped);
        if ($normalized === '') {
            return null;
        }

        foreach ([
            'meta_description' => self::META_DESCRIPTION_LABELS,
            'seo_title' => self::SEO_TITLE_LABELS,
            'h1_title' => self::H1_TITLE_LABELS,
            'structure' => self::INVISIBLE_STRUCTURE_LABELS,
        ] as $type => $labels) {
            foreach ($this->labelsLongestFirst($labels) as $label) {
                $match = $this->matchExactLabel($normalized, $label);
                if ($match === null) {
                    continue;
                }

                if (
                    in_array($label, self::AMBIGUOUS_METADATA_LABELS, true)
                    && in_array($type, ['meta_description', 'h1_title'], true)
                    && ! $match['has_value']
                    && ! $this->hasStructuralSignal($line)
                ) {
                    continue;
                }

                if ($type === 'structure') {
                    if (
                        in_array($label, self::AMBIGUOUS_STRUCTURE_LABELS, true)
                        && ! $this->hasStructuralSignal($line)
                    ) {
                        continue;
                    }

                    $structureValue = null;
                    if ($match['has_value']) {
                        $structureValue = $this->extractInlineValuePreservingCase($stripped, $label);
                    }

                    return [
                        'type' => 'structure',
                        'value' => $structureValue,
                        'normalized' => str_replace(' ', '_', $label),
                    ];
                }

                $value = null;
                if ($match['has_value']) {
                    $value = $this->extractInlineValuePreservingCase($stripped, $label);
                }

                return [
                    'type' => $type,
                    'value' => $value,
                    'normalized' => $label,
                ];
            }
        }

        return null;
    }

    public function isMetadataOrStructureLine(string $line): bool
    {
        return $this->parseLabelLine($line) !== null;
    }

    public function isMetaDescriptionLine(string $line): bool
    {
        $label = $this->parseLabelLine($line);

        return $label !== null && $label['type'] === 'meta_description';
    }

    public function isSeoTitleLine(string $line): bool
    {
        $label = $this->parseLabelLine($line);

        return $label !== null && $label['type'] === 'seo_title';
    }

    public function isInvisibleStructureLine(string $line): bool
    {
        $label = $this->parseLabelLine($line);

        return $label !== null && $label['type'] === 'structure';
    }

    public function isHorizontalRule(string $line): bool
    {
        $line = trim($line);

        return preg_match('/^(?:-{3,}|\*{3,}|_{3,})\s*$/u', $line) === 1;
    }

    /**
     * @return array{
     *     markdown: string,
     *     h1_title: null,
     *     seo_title: null,
     *     meta_description: null,
     *     diagnostics: array{
     *         detected_h1: bool,
     *         detected_seo_title: bool,
     *         detected_meta_description: bool,
     *         removed_structure_labels: list<string>
     *     }
     * }
     */
    private function emptyResult(): array
    {
        return [
            'markdown' => '',
            'h1_title' => null,
            'seo_title' => null,
            'meta_description' => null,
            'diagnostics' => [
                'detected_h1' => false,
                'detected_seo_title' => false,
                'detected_meta_description' => false,
                'removed_structure_labels' => [],
            ],
        ];
    }

    private function normalizeDocument(string $markdown): string
    {
        if (str_starts_with($markdown, "\xEF\xBB\xBF")) {
            $markdown = substr($markdown, 3);
        }

        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = str_replace("\u{00A0}", ' ', $markdown);

        return trim($markdown);
    }

    private function unwrapOuterMarkdownFence(string $markdown): string
    {
        if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n```\s*$/iu', $markdown, $matches) !== 1) {
            return $markdown;
        }

        return trim($matches[1]);
    }

    private function looksLikeHtmlDocument(string $input): bool
    {
        $trimmed = ltrim($input);
        if ($trimmed === '' || ! str_starts_with($trimmed, '<')) {
            return false;
        }

        $probe = substr($trimmed, 0, 800);

        return preg_match(
            '/^<(?:!DOCTYPE\s+html|html|body|article|section|div|p|h[1-6]|ul|ol|table|header|main)\b/i',
            $probe,
        ) === 1
            || preg_match('/<(?:p|h[1-6]|div|article|section)\b[^>]*>/i', $probe) === 1;
    }

    private function hasStructuralSignal(string $originalLine): bool
    {
        $trimmed = trim($originalLine);

        if (preg_match('/^#{1,6}\s+/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\(\d+\)\s+/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\d+\s*[\.\)\-:]\s+/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/:\s*$/u', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^\*\*[^*].*[^*]\*\*$/u', $trimmed) === 1
            || preg_match('/^__[_\w].*__$/u', $trimmed) === 1;
    }

    /**
     * Strip heading / numbering / wrapping emphasis; keep original letter case for values.
     */
    private function stripMatchingPrefixes(string $line): string
    {
        $line = str_replace("\u{00A0}", ' ', $line);
        $line = trim($line);

        $line = preg_replace('/^#{1,6}\s+/u', '', $line) ?? $line;
        $line = preg_replace('/^\(\d+\)\s+/u', '', $line) ?? $line;
        $line = preg_replace('/^\d+\s*[\.\)\-:]\s+/u', '', $line) ?? $line;

        for ($i = 0; $i < 3; $i++) {
            $next = preg_replace('/^\*\*(.+)\*\*$/us', '$1', $line) ?? $line;
            $next = preg_replace('/^__(.+)__$/us', '$1', $next) ?? $next;
            $next = preg_replace('/^\*(.+)\*$/us', '$1', $next) ?? $next;
            $next = preg_replace('/^_(.+)_$/us', '$1', $next) ?? $next;
            if ($next === $line) {
                break;
            }
            $line = trim($next);
        }

        // **Label:** value  /  **Label**: value
        if (preg_match('/^\*\*(.+?)\*\*\s*:?\s*(.*)$/us', $line, $matches) === 1) {
            $labelPart = rtrim(trim($matches[1]), ':');
            $rest = ltrim(trim($matches[2]), ':');
            $rest = trim($rest);
            $line = $rest !== '' ? $labelPart.': '.$rest : $labelPart;
        } elseif (preg_match('/^__(.+?)__\s*:?\s*(.*)$/us', $line, $matches) === 1) {
            $labelPart = rtrim(trim($matches[1]), ':');
            $rest = ltrim(trim($matches[2]), ':');
            $rest = trim($rest);
            $line = $rest !== '' ? $labelPart.': '.$rest : $labelPart;
        }

        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line);
    }

    private function collapseForMatching(string $stripped): string
    {
        $line = mb_strtolower($stripped, 'UTF-8');
        $line = str_replace(['*', '_'], '', $line);
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        // Normalize "label :" → "label:"
        $line = preg_replace('/\s*:\s*/u', ':', $line) ?? $line;

        return trim($line);
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function labelsLongestFirst(array $labels): array
    {
        $sorted = $labels;
        usort($sorted, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $sorted;
    }

    /**
     * Exact allowlist match: label, label:, or label:value.
     * Rejects sentences like "meta description là một yếu tố…" (no colon after label).
     *
     * @return array{has_value: bool}|null
     */
    private function matchExactLabel(string $normalized, string $label): ?array
    {
        if ($normalized === $label || $normalized === $label.':') {
            return ['has_value' => false];
        }

        $prefix = $label.':';
        if (str_starts_with($normalized, $prefix)) {
            return ['has_value' => trim(substr($normalized, strlen($prefix))) !== ''];
        }

        return null;
    }

    private function extractInlineValuePreservingCase(string $stripped, string $label): ?string
    {
        // Match label (case-insensitive) then colon then value on the stripped line.
        $pattern = '/^'.preg_quote($label, '/').'\s*:\s*(.+)$/iu';
        if (preg_match($pattern, $stripped, $matches) !== 1) {
            return null;
        }

        $value = $this->cleanPlainText($matches[1]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function consumeFollowingMetadataValue(array $lines, int &$index): ?string
    {
        $parts = [];
        $lineCount = count($lines);

        for ($cursor = $index + 1; $cursor < $lineCount; $cursor++) {
            $next = trim($lines[$cursor]);
            if ($next === '') {
                if ($parts !== []) {
                    break;
                }

                continue;
            }

            if ($this->isMetadataBoundary($next)) {
                break;
            }

            $parts[] = $next;
            // Conservative: one non-empty value line (or joined until blank/boundary).
            // Stop after first paragraph block (blank already handled).
        }

        if ($parts === []) {
            return null;
        }

        $index = $cursor - 1;

        return trim(implode(' ', $parts));
    }

    private function isMetadataBoundary(string $line): bool
    {
        if ($this->isHorizontalRule($line)) {
            return true;
        }

        if ($this->parseLabelLine($line) !== null) {
            return true;
        }

        if ($this->isTopLevelMarkdownH1($line)) {
            return true;
        }

        // Any Markdown heading starts a new section.
        return preg_match('/^#{1,6}\s+\S/u', trim($line)) === 1;
    }

    /**
     * @param  list<string>  $lines
     */
    private function skipTrailingSeparators(array $lines, int $startIndex): int
    {
        $lineCount = count($lines);

        for ($index = $startIndex; $index < $lineCount; $index++) {
            $trimmed = trim($lines[$index]);
            if ($trimmed === '' || $this->isHorizontalRule($trimmed)) {
                continue;
            }

            break;
        }

        return $index;
    }

    private function isTopLevelMarkdownH1(string $line): bool
    {
        if (preg_match('/^#\s+(?!#)(.+)$/u', trim($line), $matches) !== 1) {
            return false;
        }

        if ($this->isSpuriousHashStarHeadingLine($line)) {
            return false;
        }

        $text = $this->cleanPlainText($matches[1]);
        if ($text === '') {
            return false;
        }

        // "# Meta Description" is a label heading, not article H1.
        $asLabel = $this->parseLabelLine($line);

        return $asLabel === null;
    }

    private function extractTopLevelMarkdownH1(string $line): ?string
    {
        if (preg_match('/^#\s+(?!#)(.+)$/u', trim($line), $matches) !== 1) {
            return null;
        }

        $text = $matches[1];
        if (preg_match('/^\*\s+/u', $text) === 1) {
            $text = preg_replace('/^\*\s+/u', '', $text) ?? $text;
        }

        $title = $this->cleanPlainText($text);

        return $title !== '' ? $title : null;
    }

    private function isSpuriousHashStarHeadingLine(string $line): bool
    {
        $line = trim($line);
        if (preg_match('/^#\s+\*/u', $line) !== 1) {
            return false;
        }

        $after = preg_replace('/^#\s+\*/u', '', $line) ?? '';

        return trim(str_replace('*', '', $after)) === '';
    }

    private function cleanPlainText(string $text): string
    {
        $text = trim(str_replace(['**', '__', '*', '_'], '', $text));
        $text = preg_replace('/^:+\s*/u', '', $text) ?? $text;

        return trim($text);
    }
}
