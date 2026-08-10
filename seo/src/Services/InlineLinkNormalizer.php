<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\InlineLinkAnalysisResult;
use Omnichannel\Addons\Seo\Support\InlineLinkNormalizationResult;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Chuẩn hóa thẻ &lt;a&gt; liền kề cùng thuộc tính (không regex cấu trúc HTML).
 *
 * Gốc lỗi TipTap/ProseMirror: link là mark; khi text node liền kề chung link nhưng khác
 * mark khác (bold/italic) mà attrs link không eq → serializer đóng/mở &lt;a&gt; nhiều lần.
 */
final class InlineLinkNormalizer
{
    /** @var list<string> */
    private const BLOCK_TAGS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'li', 'td', 'th', 'blockquote', 'pre', 'figure', 'section', 'article',
        'header', 'footer', 'aside', 'nav', 'main', 'table', 'thead', 'tbody', 'tfoot', 'tr',
        'ul', 'ol', 'dl', 'dt', 'dd',
    ];

    /** @var list<string> */
    private const INLINE_WRAPPER_TAGS = [
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'span', 'sub', 'sup',
        'mark', 'small', 'code', 'kbd', 'samp', 'var', 'abbr', 'cite', 'dfn', 'time',
    ];

    public function normalize(string $html): string
    {
        return $this->normalizeWithReport($html)->html;
    }

    public function analyze(string $html): InlineLinkAnalysisResult
    {
        $html = trim($html);
        if ($html === '') {
            return new InlineLinkAnalysisResult;
        }

        $root = $this->loadRoot($html);
        if ($root === null) {
            return new InlineLinkAnalysisResult;
        }

        return $this->analyzeRoot($root);
    }

    public function normalizeWithReport(string $html): InlineLinkNormalizationResult
    {
        $html = trim($html);
        if ($html === '') {
            $empty = new InlineLinkAnalysisResult;

            return new InlineLinkNormalizationResult($html, $empty, $empty, [], false);
        }

        $root = $this->loadRoot($html);
        if ($root === null) {
            $empty = new InlineLinkAnalysisResult;

            return new InlineLinkNormalizationResult($html, $empty, $empty, [], false);
        }

        $before = $this->analyzeRoot($root);
        $changes = [];
        $guard = 0;

        while ($guard < 50) {
            $guard++;
            $stepChanges = $this->mergePass($root);
            if ($stepChanges === []) {
                break;
            }
            foreach ($stepChanges as $change) {
                $changes[] = $change;
            }
        }

        $nestedChanges = $this->unwrapNestedAnchors($root);
        foreach ($nestedChanges as $change) {
            $changes[] = $change;
        }

        $after = $this->analyzeRoot($root);
        $doc = $root->ownerDocument;
        $normalized = $doc instanceof DOMDocument ? $this->serializeRoot($doc, $root) : $html;
        $changed = $normalized !== $html || $changes !== [];

        return new InlineLinkNormalizationResult(
            html: $normalized,
            before: $before,
            after: $after,
            changes: $changes,
            changed: $changed,
        );
    }

    /**
     * @return list<array{type: string, href?: string, detail?: string}>
     */
    private function mergePass(DOMElement $root): array
    {
        $changes = [];

        foreach ($this->collectInlineWrappers($root) as $wrapper) {
            if (! $wrapper->parentNode instanceof DOMNode) {
                continue;
            }

            $merged = $this->tryMergeFromLeadingWrapper($wrapper);
            if ($merged !== null) {
                $changes[] = $merged;
            }
        }

        foreach ($this->collectAnchors($root) as $anchor) {
            if (! $anchor->parentNode instanceof DOMNode) {
                continue;
            }

            $merged = $this->tryMergeForward($anchor);
            if ($merged !== null) {
                $changes[] = $merged;
            }
        }

        return $changes;
    }

    /**
     * `<strong><a href=U>A</a></strong><a href=U>B</a>` → `<a href=U><strong>A</strong>B</a>`
     *
     * @return array{type: string, href?: string, detail?: string}|null
     */
    private function tryMergeFromLeadingWrapper(DOMElement $wrapper): ?array
    {
        if (! $this->isInlineWrapper($wrapper)) {
            return null;
        }

        $innerAnchor = $this->soleDescendantAnchor($wrapper);
        if ($innerAnchor === null) {
            return null;
        }

        $signature = $this->linkSignature($innerAnchor);
        if ($signature === null) {
            return null;
        }

        $cursor = $wrapper->nextSibling;
        $whitespaceNodes = [];
        while ($cursor instanceof DOMText && trim($cursor->textContent ?? '') === '') {
            $whitespaceNodes[] = $cursor;
            $cursor = $cursor->nextSibling;
        }

        $trailing = $this->resolveEquivalentAnchorNode($cursor, $signature);
        if ($trailing === null) {
            return null;
        }

        $doc = $wrapper->ownerDocument;
        if (! $doc instanceof DOMDocument) {
            return null;
        }

        $newAnchor = $this->cloneAnchorShell($doc, $innerAnchor);
        $parent = $wrapper->parentNode;
        if ($parent === null) {
            return null;
        }

        $parent->insertBefore($newAnchor, $wrapper);
        $this->unwrapNode($innerAnchor);
        $newAnchor->appendChild($wrapper);

        foreach ($whitespaceNodes as $ws) {
            $newAnchor->appendChild($ws);
        }

        if ($trailing['kind'] === 'anchor') {
            $this->appendChildren($newAnchor, $trailing['node']);
            $trailing['node']->parentNode?->removeChild($trailing['node']);
        } else {
            $this->unwrapNode($trailing['inner']);
            $newAnchor->appendChild($trailing['node']);
        }

        return [
            'type' => 'merge_leading_wrapper_anchor',
            'href' => $signature['href'],
            'detail' => 'Merged leading inline-wrapped anchor with following equivalent anchor.',
        ];
    }

    /**
     * @return array{type: string, href?: string, detail?: string}|null
     */
    private function tryMergeForward(DOMElement $anchor): ?array
    {
        $signature = $this->linkSignature($anchor);
        if ($signature === null) {
            return null;
        }

        // Nếu anchor đang nằm trong wrapper chỉ chứa nó, merge từ wrapper (tránh double-handle).
        $parent = $anchor->parentNode;
        if ($parent instanceof DOMElement && $this->isInlineWrapper($parent) && $this->soleDescendantAnchor($parent) === $anchor) {
            return null;
        }

        $cursor = $anchor->nextSibling;
        $whitespaceNodes = [];
        while ($cursor instanceof DOMText && trim($cursor->textContent ?? '') === '') {
            $whitespaceNodes[] = $cursor;
            $cursor = $cursor->nextSibling;
        }

        $trailing = $this->resolveEquivalentAnchorNode($cursor, $signature);
        if ($trailing === null) {
            return null;
        }

        foreach ($whitespaceNodes as $ws) {
            $anchor->appendChild($ws);
        }

        if ($trailing['kind'] === 'anchor') {
            $this->appendChildren($anchor, $trailing['node']);
            $trailing['node']->parentNode?->removeChild($trailing['node']);

            return [
                'type' => 'merge_sibling_anchors',
                'href' => $signature['href'],
                'detail' => 'Merged adjacent sibling anchors with equivalent attributes.',
            ];
        }

        $this->unwrapNode($trailing['inner']);
        $anchor->appendChild($trailing['node']);

        return [
            'type' => 'merge_wrapped_anchor',
            'href' => $signature['href'],
            'detail' => 'Merged anchor inside inline wrapper into preceding equivalent anchor.',
        ];
    }

    /**
     * @param  array{key: string, href: string}  $signature
     * @return array{kind: 'anchor', node: DOMElement}|array{kind: 'wrapper', node: DOMElement, inner: DOMElement}|null
     */
    private function resolveEquivalentAnchorNode(?DOMNode $cursor, array $signature): ?array
    {
        if (! $cursor instanceof DOMElement) {
            return null;
        }

        if (strtolower($cursor->tagName) === 'a') {
            if ($this->linkSignature($cursor) !== $signature) {
                return null;
            }

            return ['kind' => 'anchor', 'node' => $cursor];
        }

        if (! $this->isInlineWrapper($cursor)) {
            return null;
        }

        $innerAnchor = $this->soleDescendantAnchor($cursor);
        if ($innerAnchor === null || $this->linkSignature($innerAnchor) !== $signature) {
            return null;
        }

        return ['kind' => 'wrapper', 'node' => $cursor, 'inner' => $innerAnchor];
    }

    private function cloneAnchorShell(DOMDocument $doc, DOMElement $source): DOMElement
    {
        $anchor = $doc->createElement('a');
        foreach ($source->attributes ?? [] as $attribute) {
            $anchor->setAttribute((string) $attribute->name, (string) $attribute->value);
        }

        return $anchor;
    }

    /**
     * @return list<DOMElement>
     */
    private function collectInlineWrappers(DOMElement $root): array
    {
        $list = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof DOMElement && $this->isInlineWrapper($el)) {
                $list[] = $el;
            }
        }

        return $list;
    }

    /**
     * @return list<array{type: string, href?: string, detail?: string}>
     */
    private function unwrapNestedAnchors(DOMElement $root): array
    {
        $changes = [];
        $anchors = $this->collectAnchors($root);

        foreach ($anchors as $anchor) {
            $parentAnchor = $this->closestAncestorAnchor($anchor);
            if ($parentAnchor === null) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            $this->unwrapNode($anchor);
            $changes[] = [
                'type' => 'unwrap_nested_anchor',
                'href' => $href,
                'detail' => 'Removed nested anchor inside another anchor.',
            ];
        }

        return $changes;
    }

    private function analyzeRoot(DOMElement $root): InlineLinkAnalysisResult
    {
        $anchors = $this->collectAnchors($root);
        $anchorCount = count($anchors);
        $nested = 0;
        $invalidHrefs = [];
        $duplicateAdjacent = 0;
        $splitGroups = [];
        $warnings = [];

        /** @var array<string, array{href: string, count: int, sample: string}> $groupMap */
        $groupMap = [];

        foreach ($anchors as $anchor) {
            if ($this->closestAncestorAnchor($anchor) !== null) {
                $nested++;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            if (! $this->isValidHref($href)) {
                $invalidHrefs[] = $href;
            }

            $signature = $this->linkSignature($anchor);
            if ($signature === null) {
                continue;
            }

            $forward = $this->countForwardEquivalent($anchor, $signature);
            if ($forward > 0) {
                $duplicateAdjacent += $forward;
                $key = $signature['key'];
                if (! isset($groupMap[$key])) {
                    $groupMap[$key] = [
                        'href' => $signature['href'],
                        'count' => 1 + $forward,
                        'sample' => $this->truncate(trim(preg_replace('/\s+/u', ' ', $anchor->textContent ?? '') ?? ''), 80),
                    ];
                } else {
                    $groupMap[$key]['count'] = max($groupMap[$key]['count'], 1 + $forward);
                }
            }
        }

        foreach ($groupMap as $group) {
            $splitGroups[] = $group;
            $warnings[] = sprintf(
                'Adjacent duplicate anchors for href=%s (segments≈%d): «%s»',
                $group['href'],
                $group['count'],
                $group['sample'],
            );
        }

        if ($nested > 0) {
            $warnings[] = sprintf('Found %d nested anchor(s).', $nested);
        }

        $invalidHrefs = array_values(array_unique($invalidHrefs));

        return new InlineLinkAnalysisResult(
            anchorCount: $anchorCount,
            duplicateAdjacentCount: $duplicateAdjacent,
            nestedAnchorCount: $nested,
            invalidHrefCount: count($invalidHrefs),
            splitGroups: array_values($splitGroups),
            invalidHrefs: $invalidHrefs,
            warnings: $warnings,
        );
    }

    /**
     * @param  array{key: string, href: string}  $signature
     */
    private function countForwardEquivalent(DOMElement $anchor, array $signature): int
    {
        $count = 0;
        $cursor = $anchor->nextSibling;

        while ($cursor !== null) {
            if ($cursor instanceof DOMText) {
                if (trim($cursor->textContent ?? '') === '') {
                    $cursor = $cursor->nextSibling;
                    continue;
                }

                break;
            }

            if (! $cursor instanceof DOMElement) {
                break;
            }

            $tag = strtolower($cursor->tagName);
            if ($tag === 'a') {
                if ($this->linkSignature($cursor) === $signature) {
                    $count++;
                    $cursor = $cursor->nextSibling;
                    continue;
                }

                break;
            }

            if ($this->isInlineWrapper($cursor)) {
                $inner = $this->soleDescendantAnchor($cursor);
                if ($inner !== null && $this->linkSignature($inner) === $signature) {
                    $count++;
                    $cursor = $cursor->nextSibling;
                    continue;
                }
            }

            break;
        }

        return $count;
    }

    /**
     * @return list<DOMElement>
     */
    private function collectAnchors(DOMElement $root): array
    {
        $list = [];
        foreach ($root->getElementsByTagName('a') as $el) {
            if ($el instanceof DOMElement) {
                $list[] = $el;
            }
        }

        return $list;
    }

    private function soleDescendantAnchor(DOMElement $wrapper): ?DOMElement
    {
        $anchors = [];
        foreach ($wrapper->getElementsByTagName('a') as $el) {
            if ($el instanceof DOMElement) {
                $anchors[] = $el;
            }
        }

        if (count($anchors) !== 1) {
            return null;
        }

        $anchor = $anchors[0];

        // Wrapper (và tổ tiên inline tới anchor) chỉ được chứa whitespace + cấu trúc dẫn tới anchor.
        if (! $this->wrapperOnlyContainsAnchorPath($wrapper, $anchor)) {
            return null;
        }

        return $anchor;
    }

    private function wrapperOnlyContainsAnchorPath(DOMElement $wrapper, DOMElement $anchor): bool
    {
        $node = $anchor;
        while ($node !== $wrapper) {
            $parent = $node->parentNode;
            if (! $parent instanceof DOMElement) {
                return false;
            }

            foreach ($parent->childNodes as $child) {
                if ($child === $node) {
                    continue;
                }
                if ($child instanceof DOMText && trim($child->textContent ?? '') === '') {
                    continue;
                }

                return false;
            }

            if ($parent === $wrapper) {
                break;
            }

            if (! $this->isInlineWrapper($parent) && $parent !== $wrapper) {
                return false;
            }

            $node = $parent;
        }

        foreach ($wrapper->childNodes as $child) {
            if ($this->nodeContains($child, $anchor) || $child === $anchor) {
                continue;
            }
            if ($child instanceof DOMText && trim($child->textContent ?? '') === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function nodeContains(DOMNode $haystack, DOMNode $needle): bool
    {
        $node = $needle;
        while ($node instanceof DOMNode) {
            if ($node === $haystack) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    private function closestAncestorAnchor(DOMElement $el): ?DOMElement
    {
        $parent = $el->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === 'a') {
                return $parent;
            }
            if (in_array(strtolower($parent->tagName), self::BLOCK_TAGS, true)) {
                return null;
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private function isInlineWrapper(DOMElement $el): bool
    {
        return in_array(strtolower($el->tagName), self::INLINE_WRAPPER_TAGS, true);
    }

    /**
     * @return array{key: string, href: string}|null
     */
    private function linkSignature(DOMElement $anchor): ?array
    {
        if (strtolower($anchor->tagName) !== 'a') {
            return null;
        }

        $href = trim((string) $anchor->getAttribute('href'));
        if ($href === '' || ! $this->isValidHref($href)) {
            return null;
        }

        $parts = ['href='.mb_strtolower($href)];

        foreach (['target', 'rel', 'title', 'class'] as $attr) {
            $value = trim((string) $anchor->getAttribute($attr));
            if ($attr === 'class') {
                $tokens = preg_split('/\s+/', $value) ?: [];
                $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
                sort($tokens);
                $value = implode(' ', $tokens);
            } elseif ($attr === 'rel') {
                $tokens = preg_split('/\s+/', mb_strtolower($value)) ?: [];
                $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
                sort($tokens);
                $value = implode(' ', $tokens);
            } else {
                $value = mb_strtolower($value);
            }
            $parts[] = $attr.'='.$value;
        }

        foreach ($anchor->attributes ?? [] as $attribute) {
            $name = strtolower((string) $attribute->name);
            if (! str_starts_with($name, 'data-')) {
                continue;
            }
            $parts[] = $name.'='.mb_strtolower(trim((string) $attribute->value));
        }

        sort($parts);

        return [
            'key' => implode('|', $parts),
            'href' => $href,
        ];
    }

    private function isValidHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return false;
        }

        if (str_starts_with($href, '#')) {
            return true;
        }

        if (preg_match('#^(https?|mailto|tel|sms):#i', $href) === 1) {
            return true;
        }

        if (str_starts_with($href, '/') || str_starts_with($href, './') || str_starts_with($href, '../')) {
            return true;
        }

        // Relative path without scheme
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return true;
        }

        return false;
    }

    private function appendChildren(DOMElement $target, DOMElement $source): void
    {
        while ($source->firstChild !== null) {
            $target->appendChild($source->firstChild);
        }
    }

    private function unwrapNode(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }

        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }

    private function loadRoot(string $html): ?DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="seo-inline-link-root">'.$html.'</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('seo-inline-link-root');

        return $root instanceof DOMElement ? $root : null;
    }

    private function serializeRoot(DOMDocument $doc, DOMElement $root): string
    {
        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
