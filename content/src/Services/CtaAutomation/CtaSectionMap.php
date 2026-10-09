<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use DOMDocument;
use DOMElement;

/**
 * One section walk for planning, legacy detection, navigation and insertion.
 *
 * @phpstan-type SectionRow array{section_id: string, heading: string, position_ratio: float, start_word: int, word_count: int, content: string, nodes: list<DOMElement>}
 */
final class CtaSectionMap
{
    /**
     * @return list<SectionRow>
     */
    public static function bind(DOMElement $root): array
    {
        $sections = [];
        $nodes = [];
        $heading = '';
        $content = '';
        $index = 1;

        $push = function () use (&$sections, &$nodes, &$heading, &$content, &$index): void {
            if ($nodes === [] && trim($content) === '' && $heading === '') {
                return;
            }
            $sections[] = [
                'section_id' => 'section_'.$index,
                'heading' => $heading,
                'content' => trim($content),
                'nodes' => $nodes,
                'position_ratio' => 0.0,
                'start_word' => 0,
                'word_count' => 0,
            ];
        };

        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['h2', 'h3'], true) && trim($content) !== '') {
                $push();
                $index++;
                $nodes = [$child];
                $heading = trim($child->textContent ?? '');
                $content = '';
                continue;
            }
            if (in_array($tag, ['h2', 'h3'], true)) {
                $nodes[] = $child;
                $heading = trim($child->textContent ?? '');
                continue;
            }
            $nodes[] = $child;
            if (self::isManaged($child) || self::isManual($child)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $child->textContent ?? '') ?? '');
            if ($text === '') {
                continue;
            }
            $content = trim($content.' '.$text);
        }

        $push();

        $wordsBefore = 0;
        foreach ($sections as $i => $section) {
            $count = self::words($section['content']);
            $sections[$i]['word_count'] = $count;
            $sections[$i]['start_word'] = $wordsBefore;
            $wordsBefore += $count;
        }
        $total = max(1, $wordsBefore);
        foreach ($sections as $i => $section) {
            $sections[$i]['position_ratio'] = round(min(1, $section['start_word'] / $total), 4);
        }

        return $sections;
    }

    public static function root(string $html): ?DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="cta-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('cta-root');

        return $root instanceof DOMElement ? $root : null;
    }

    private static function words(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function isManaged(DOMElement $node): bool
    {
        return str_contains((string) $node->getAttribute('class'), 'seo-managed-cta');
    }

    private static function isManual(DOMElement $node): bool
    {
        return $node->getAttribute('data-cta-manual') === '1';
    }
}
