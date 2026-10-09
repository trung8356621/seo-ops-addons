<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Splits article HTML into stable section ids from document order.
 */
final class CtaArticleSections
{
    /**
     * @return list<array{section_id: string, heading: string, position_ratio: float, start_word: int, word_count: int, content: string}>
     */
    public function extract(string $html): array
    {
        $root = $this->root($html);
        if (! $root instanceof DOMElement) {
            return [];
        }

        $sections = [];
        $current = $this->blankSection(1, '');
        $index = 1;
        $wordsBefore = 0;

        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['h2', 'h3'], true) && trim($current['content']) !== '') {
                $current['word_count'] = $this->words($current['content']);
                $current['start_word'] = $wordsBefore;
                $sections[] = $current;
                $wordsBefore += $current['word_count'];
                $index++;
                $current = $this->blankSection($index, trim($child->textContent ?? ''));
                continue;
            }
            if (in_array($tag, ['h2', 'h3'], true) && trim($current['content']) === '') {
                $current['heading'] = trim($child->textContent ?? '');
                continue;
            }
            if ($this->isManagedCta($child) || $this->isManualCta($child)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $child->textContent ?? '') ?? '');
            if ($text === '') {
                continue;
            }
            $current['content'] = trim($current['content'].' '.$text);
        }

        if (trim($current['content']) !== '' || $current['heading'] !== '') {
            $current['word_count'] = $this->words($current['content']);
            $current['start_word'] = $wordsBefore;
            $sections[] = $current;
            $wordsBefore += $current['word_count'];
        }

        $total = max(1, $wordsBefore);
        foreach ($sections as $i => $section) {
            $sections[$i]['position_ratio'] = round(min(1, $section['start_word'] / $total), 4);
        }

        return $sections;
    }

    /**
     * @return array{section_id: string, heading: string, position_ratio: float, start_word: int, word_count: int, content: string}
     */
    private function blankSection(int $index, string $heading): array
    {
        return [
            'section_id' => 'section_'.$index,
            'heading' => $heading,
            'position_ratio' => 0.0,
            'start_word' => 0,
            'word_count' => 0,
            'content' => '',
        ];
    }

    private function words(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function isManagedCta(DOMElement $node): bool
    {
        return str_contains((string) $node->getAttribute('class'), 'seo-managed-cta');
    }

    private function isManualCta(DOMElement $node): bool
    {
        return $node->getAttribute('data-cta-manual') === '1';
    }

    private function root(string $html): ?DOMNode
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="cta-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom->getElementById('cta-root');
    }
}
