<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Output\RenderedContentInterface;

/**
 * Thin Markdown → HTML wrapper around league/commonmark.
 *
 * Does not invent Markdown syntax via regex. Import metadata / structure-label
 * extraction stays in {@see ArticleMarkdownImportParser}.
 */
final class SimpleMarkdownHtmlConverter
{
    private ?MarkdownConverter $converter = null;

    /**
     * @return array{html: string, meta_description: string|null}
     */
    public function toHtmlWithMetadata(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return ['html' => '', 'meta_description' => null];
        }

        $extracted = $this->extractMetaDescriptionFromMarkdown($markdown);
        $markdown = $extracted['markdown'];
        $metaDescription = $extracted['meta_description'];

        if ($markdown === '') {
            return ['html' => '', 'meta_description' => $metaDescription];
        }

        return [
            'html' => $this->renderCommonMark($markdown),
            'meta_description' => $metaDescription,
        ];
    }

    public function toHtml(string $markdown): string
    {
        return $this->toHtmlWithMetadata($markdown)['html'];
    }

    /**
     * Markdown Featured Snippet → HTML chèn trong section hiện tại (không tạo H2/H3 outline mới).
     */
    public function toFeaturedSnippetEditorHtml(string $markdown): string
    {
        return $this->downgradeHeadingsForInlineEditorInsert($this->toHtml($markdown));
    }

    /**
     * H1–H6 → <p><strong>…</strong></p> để editor không tách section / outline.
     */
    public function downgradeHeadingsForInlineEditorInsert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="seo-fs-root">'.$html.'</div>';
        $doc->loadHTML('<?xml encoding="UTF-8">'.$wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('seo-fs-root');
        if ($root === null) {
            return $html;
        }

        $headings = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (preg_match('/^h[1-6]$/i', $element->nodeName) === 1) {
                $headings[] = $element;
            }
        }

        foreach ($headings as $heading) {
            $p = $doc->createElement('p');
            $strong = $doc->createElement('strong');
            $strong->textContent = trim(preg_replace('/\s+/u', ' ', $heading->textContent ?? '') ?? '');
            $p->appendChild($strong);
            $heading->parentNode?->replaceChild($p, $heading);
        }

        $parts = [];
        foreach ($root->childNodes as $child) {
            $parts[] = $doc->saveHTML($child);
        }

        return trim(implode('', $parts));
    }

    /**
     * Tách H1 / SEO Title / Meta Description / structural wrappers khỏi markdown AI.
     *
     * @return array{
     *     markdown: string,
     *     h1_title: string|null,
     *     meta_description: string|null,
     *     seo_title?: string|null
     * }
     */
    public function prepareImport(string $markdown): array
    {
        $parsed = (new ArticleMarkdownImportParser)->parse($markdown);

        return [
            'markdown' => $parsed['markdown'],
            'h1_title' => $parsed['h1_title'],
            'meta_description' => $parsed['meta_description'],
            'seo_title' => $parsed['seo_title'],
        ];
    }

    /**
     * Legacy helper — không dùng trong production convert path.
     * Giữ để test / debug thủ công; không suy đoán heading khi render.
     */
    public function promoteOrphanH3HeadingsToH2(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        if (preg_match('/^##(?!#)\s+\S/m', $markdown) === 1) {
            return $markdown;
        }

        if (preg_match('/^###(?!#)\s+\S/m', $markdown) !== 1) {
            return $markdown;
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{3,6})(\s+\S.*)$/u', $line, $matches) === 1) {
                $out[] = substr($matches[1], 1).$matches[2];

                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * @return array{html: string, meta_description: string|null}
     */
    public function stripMetaDescriptionFromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'meta_description' => null];
        }

        $metaDescription = null;
        $patterns = [
            '/<p>\s*(?:<(?:strong|b)>\s*)?Meta\s+Description\s*:\s*(?:<\/(?:strong|b)>\s*)?(.*?)<\/p>/isu',
            '/<p>\s*\*{0,2}\s*Meta\s+Description\s*:\*{0,2}\s*(.*?)<\/p>/isu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) !== 1) {
                continue;
            }

            $metaDescription = trim(html_entity_decode(strip_tags((string) ($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $html = trim((string) preg_replace($pattern, '', $html, 1));
            break;
        }

        return [
            'html' => $html,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
        ];
    }

    /**
     * @return array{markdown: string, meta_description: string|null}
     */
    private function extractMetaDescriptionFromMarkdown(string $markdown): array
    {
        $parser = new ArticleMarkdownImportParser;
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $kept = [];
        $metaDescription = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && $parser->isMetaDescriptionLine($trimmed)) {
                $label = $parser->parseLabelLine($trimmed);
                $value = trim((string) ($label['value'] ?? ''));
                if ($value !== '' && $metaDescription === null) {
                    $metaDescription = $value;
                }

                continue;
            }

            $kept[] = $line;
        }

        return [
            'markdown' => trim(implode("\n", $kept)),
            'meta_description' => $metaDescription,
        ];
    }

    private function renderCommonMark(string $markdown): string
    {
        $rendered = $this->converter()->convert($markdown);
        $html = $rendered instanceof RenderedContentInterface
            ? $rendered->getContent()
            : (string) $rendered;

        return trim($html);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 100,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return $this->converter = new MarkdownConverter($environment);
    }
}
