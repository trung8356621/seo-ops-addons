<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Parse H2 sections from post HTML for Content Project image automation.
 */
final class ContentProjectPostSectionAnalyzer
{
    public const MIN_SECTION_TEXT_LENGTH = 120;

    public const MAX_IMAGES = 3;

    /** @var list<string> */
    private const SKIP_HEADING_PATTERNS = [
        '/\bfaq\b/iu',
        '/câu hỏi thường gặp/iu',
        '/kết luận/iu',
        '/conclusion/iu',
        '/tổng kết/iu',
        '/lời kết/iu',
    ];

    /**
     * @return list<array{
     *     key: string,
     *     heading: string,
     *     heading_html: string,
     *     content_html: string,
     *     text_length: int,
     *     has_image: bool
     * }>
     */
    public function eligibleSections(string $html, int $maxImages = self::MAX_IMAGES): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $sections = $this->parseSections($html);
        $candidates = [];

        foreach ($sections as $section) {
            if ($this->shouldSkipSection($section)) {
                continue;
            }

            $candidates[] = $section;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['text_length'] <=> $a['text_length']);

        return array_slice($candidates, 0, max(0, $maxImages));
    }

    /**
     * @return list<array{key: string, heading: string, heading_html: string, content_html: string, text_length: int, has_image: bool}>
     */
    private function parseSections(string $html): array
    {
        $parts = preg_split('/(<h2(?:\s[^>]*)?>.*?<\/h2>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        if (count($parts) <= 1) {
            return [];
        }

        $sections = [];
        $index = 0;

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $headingHtml = (string) ($parts[$i] ?? '');
            $contentHtml = (string) ($parts[$i + 1] ?? '');
            $headingText = trim(strip_tags($headingHtml));
            $plainText = trim(strip_tags($contentHtml));

            if ($headingText === '') {
                continue;
            }

            $sections[] = [
                'key' => 's'.$index,
                'heading' => $headingText,
                'heading_html' => $headingHtml,
                'content_html' => $contentHtml,
                'text_length' => mb_strlen($plainText),
                'has_image' => $this->sectionHasImage($contentHtml),
            ];
            $index++;
        }

        return $sections;
    }

    /**
     * @param  array{key: string, heading: string, heading_html: string, content_html: string, text_length: int, has_image: bool}  $section
     */
    private function shouldSkipSection(array $section): bool
    {
        if ($section['has_image']) {
            return true;
        }

        if ($section['text_length'] < self::MIN_SECTION_TEXT_LENGTH) {
            return true;
        }

        if ($this->sectionHasFaq($section['content_html'])) {
            return true;
        }

        foreach (self::SKIP_HEADING_PATTERNS as $pattern) {
            if (preg_match($pattern, $section['heading']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function sectionHasImage(string $html): bool
    {
        return (bool) preg_match('/<img[\s>]/iu', $html);
    }

    private function sectionHasFaq(string $html): bool
    {
        return str_contains($html, '[omi_faq]')
            || str_contains($html, 'omi-faq')
            || (bool) preg_match('/\[faq[^\]]*\]/iu', $html);
    }
}
