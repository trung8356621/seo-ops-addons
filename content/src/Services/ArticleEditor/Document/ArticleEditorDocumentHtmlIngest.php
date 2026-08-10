<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Legacy HTML → article_document envelope (PHP DOM ingest).
 */
final class ArticleEditorDocumentHtmlIngest
{
    /**
     * @return array<string, mixed>
     */
    public function ingestHtmlToEnvelope(string $html, ?string $blockId = null): array
    {
        $id = $blockId ?: 'block-legacy-1';
        $doc = $this->htmlToTipTapDoc($html);

        return [
            'schema_version' => ArticleEditorDocumentSchema::CURRENT_VERSION,
            'type' => ArticleEditorDocumentSchema::TYPE,
            'blocks' => [
                [
                    'id' => $id,
                    'type' => 'text',
                    'document' => $doc,
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public function htmlToTipTapDoc(string $html): array
    {
        $source = trim($html);
        if ($source === '') {
            return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => []]]];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div id="seo-ingest-root">'.$source.'</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('seo-ingest-root');
        $content = [];
        if ($root instanceof DOMElement) {
            foreach ($root->childNodes as $child) {
                $converted = $this->convertNode($child);
                if ($converted === null) {
                    continue;
                }
                if (array_is_list($converted) && $this->isListOfNodes($converted)) {
                    foreach ($converted as $item) {
                        if (is_array($item)) {
                            $content[] = $item;
                        }
                    }
                } else {
                    $content[] = $converted;
                }
            }
        }

        if ($content === []) {
            $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => strip_tags($source)]]];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function convertNode(DOMNode $node): array|null
    {
        if ($node instanceof DOMText) {
            $text = (string) $node->textContent;
            // Keep whitespace-only text when non-empty — do not trim() (inline boundaries).
            if ($text === '') {
                return null;
            }

            return [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }

        if (! $node instanceof DOMElement) {
            return null;
        }

        $tag = strtolower($node->tagName);

        if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
            return [
                'type' => 'heading',
                'attrs' => ['level' => (int) $m[1]],
                'content' => $this->convertInlineChildren($node),
            ];
        }

        if ($tag === 'p') {
            $attrs = [];
            $class = trim((string) $node->getAttribute('class'));
            if ($class !== '') {
                $attrs['class'] = $class;
            }
            if ($node->hasAttribute('data-omi-faq')) {
                $attrs['data-omi-faq'] = (string) $node->getAttribute('data-omi-faq');
            }

            return [
                'type' => 'paragraph',
                'attrs' => $attrs === [] ? null : $attrs,
                'content' => $this->convertInlineChildren($node),
            ];
        }

        if ($tag === 'blockquote') {
            return ['type' => 'blockquote', 'content' => $this->convertBlockChildren($node)];
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $items = [];
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                    $items[] = [
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => $this->convertInlineChildren($child),
                        ]],
                    ];
                }
            }

            return [
                'type' => $tag === 'ul' ? 'bulletList' : 'orderedList',
                'content' => $items,
            ];
        }

        if ($tag === 'img') {
            return [
                'type' => 'articleImage',
                'attrs' => [
                    'src' => (string) $node->getAttribute('src'),
                    'alt' => (string) $node->getAttribute('alt'),
                    'title' => (string) $node->getAttribute('title'),
                ],
            ];
        }

        if ($tag === 'figure') {
            foreach ($node->getElementsByTagName('img') as $img) {
                return $this->convertNode($img);
            }

            return $this->convertBlockChildren($node);
        }

        if ($tag === 'br') {
            return ['type' => 'paragraph', 'content' => [['type' => 'hardBreak']]];
        }

        if ($tag === 'hr') {
            return ['type' => 'horizontalRule'];
        }

        if ($tag === 'table') {
            return $this->convertTable($node);
        }

        if (in_array($tag, ['div', 'section', 'article'], true)) {
            return $this->convertBlockChildren($node);
        }

        return [
            'type' => 'paragraph',
            'content' => $this->convertInlineChildren($node),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function convertBlockChildren(DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $child) {
            $converted = $this->convertNode($child);
            if ($converted === null) {
                continue;
            }
            if ($this->isListOfNodes($converted)) {
                foreach ($converted as $item) {
                    if (is_array($item)) {
                        $out[] = $item;
                    }
                }
            } else {
                $out[] = $converted;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function convertInlineChildren(DOMElement $el): array
    {
        $content = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = (string) $child->textContent;
                if ($text !== '') {
                    $content[] = ['type' => 'text', 'text' => $text];
                }
                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $content[] = ['type' => 'hardBreak'];
                continue;
            }
            if ($tag === 'a') {
                $href = (string) $child->getAttribute('href');
                foreach ($this->convertInlineChildren($child) as $piece) {
                    if (($piece['type'] ?? '') === 'text') {
                        $marks = is_array($piece['marks'] ?? null) ? $piece['marks'] : [];
                        if ($href !== '') {
                            $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                        }
                        $piece['marks'] = $marks;
                    }
                    $content[] = $piece;
                }
                continue;
            }
            if (in_array($tag, ['strong', 'b'], true)) {
                foreach ($this->convertInlineChildren($child) as $piece) {
                    if (($piece['type'] ?? '') === 'text') {
                        $marks = is_array($piece['marks'] ?? null) ? $piece['marks'] : [];
                        $marks[] = ['type' => 'bold'];
                        $piece['marks'] = $marks;
                    }
                    $content[] = $piece;
                }
                continue;
            }
            if (in_array($tag, ['em', 'i'], true)) {
                foreach ($this->convertInlineChildren($child) as $piece) {
                    if (($piece['type'] ?? '') === 'text') {
                        $marks = is_array($piece['marks'] ?? null) ? $piece['marks'] : [];
                        $marks[] = ['type' => 'italic'];
                        $piece['marks'] = $marks;
                    }
                    $content[] = $piece;
                }
                continue;
            }
            foreach ($this->convertInlineChildren($child) as $piece) {
                $content[] = $piece;
            }
        }

        return $content;
    }

    /**
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    private function convertTable(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            // Skip nested tables' rows belonging to descendant tables.
            $parentTable = $tr->parentNode;
            while ($parentTable instanceof DOMNode && ! $parentTable instanceof DOMElement) {
                $parentTable = $parentTable->parentNode;
            }
            if ($parentTable instanceof DOMElement) {
                $parentTag = strtolower($parentTable->tagName);
                if (in_array($parentTag, ['thead', 'tbody', 'tfoot'], true)) {
                    $parentTable = $parentTable->parentNode;
                }
            }
            if ($parentTable !== $table) {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if (! in_array($cellTag, ['td', 'th'], true)) {
                    continue;
                }
                $attrs = [];
                $colspan = (int) $cell->getAttribute('colspan');
                $rowspan = (int) $cell->getAttribute('rowspan');
                if ($colspan > 1) {
                    $attrs['colspan'] = $colspan;
                }
                if ($rowspan > 1) {
                    $attrs['rowspan'] = $rowspan;
                }
                $inline = $this->convertInlineChildren($cell);
                $cells[] = [
                    'type' => $cellTag === 'th' ? 'tableHeader' : 'tableCell',
                    'attrs' => $attrs === [] ? null : $attrs,
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => $inline === []
                            ? [['type' => 'text', 'text' => '']]
                            : $inline,
                    ]],
                ];
            }
            if ($cells !== []) {
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return [
            'type' => 'table',
            'content' => $rows,
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function isListOfNodes(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        if (! array_is_list($value)) {
            return false;
        }
        $first = $value[0] ?? null;

        return is_array($first) && isset($first['type']);
    }
}
