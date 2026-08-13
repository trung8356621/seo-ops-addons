<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Services\InlineLinkNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Gỡ markup tạm của editor (highlight gợi ý, đánh dấu link sidebar, TipTap mark) trước lưu / đồng bộ WP.
 */
final class ArticleEditorHtmlSanitizeService
{
    public const LINK_MARK_CLASS = 'seo-editor-link-mark';

    public const LINK_SCROLL_LEGACY_CLASS = 'seo-link-scroll-highlight';

    public const EDITOR_LINK_CLASS = 'seo-editor-link';

    /** @var list<string> */
    private const TRANSIENT_CLASSES = [
        self::LINK_MARK_CLASS,
        self::LINK_SCROLL_LEGACY_CLASS,
    ];

    public function stripTransientEditorMarkup(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        $html = app(ArticleCtaPlaceholderService::class)->stripBlankPlaceholderMarkup($html);

        $root = $this->loadHtmlRoot($html);
        if ($root === null) {
            return $html;
        }

        $doc = $root->ownerDocument;
        $this->unwrapTransientMarks($root);
        $this->stripTransientClasses($root);

        $cleaned = $this->serializeRoot($doc, $root);

        return app(InlineLinkNormalizer::class)->normalize($cleaned);
    }

    /**
     * Gỡ class Tailwind / Claude (HTML từ prompt thủ công) trước khi đẩy lên WordPress.
     */
    public function stripAiUtilityClasses(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        $root = $this->loadHtmlRoot($html);
        if ($root === null) {
            return $html;
        }

        $doc = $root->ownerDocument;
        $this->stripJunkUtilityClasses($root);

        return $this->serializeRoot($doc, $root);
    }

    /**
     * Chuẩn bị HTML editor trước khi đồng bộ WordPress.
     */
    public function prepareHtmlForWordPressSync(string $html): string
    {
        $cleaned = $this->stripAiUtilityClasses($this->stripTransientEditorMarkup($html));

        // TipTap hydrate can glue word↔mark boundaries; repair before WP payload.
        return (new \Omnichannel\Addons\Content\Services\ArticleEditor\Document\InlineMarkBoundaryWhitespace)
            ->repair($cleaned);
    }

    private function loadHtmlRoot(string $html): ?DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="seo-sanitize-root">' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('seo-sanitize-root');

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

    private function stripJunkUtilityClasses(DOMElement $root): void
    {
        $walker = $root->getElementsByTagName('*');

        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($walker as $el) {
            if ($el instanceof DOMElement) {
                $elements[] = $el;
            }
        }

        foreach ($elements as $el) {
            $this->filterJunkClassesOnElement($el);
        }
    }

    private function filterJunkClassesOnElement(DOMElement $el): void
    {
        $class = trim((string) $el->getAttribute('class'));
        if ($class === '') {
            return;
        }

        $tokens = preg_split('/\s+/', $class) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || $this->isJunkUtilityClass($token)) {
                continue;
            }
            $kept[] = $token;
        }

        if ($kept === []) {
            $el->removeAttribute('class');
        } else {
            $el->setAttribute('class', implode(' ', $kept));
        }
    }

    private function isJunkUtilityClass(string $token): bool
    {
        if (preg_match('/^font-claude/i', $token) === 1) {
            return true;
        }

        if ($this->isPreservedWordPressClass($token)) {
            return false;
        }

        if (str_contains($token, '[') || str_contains($token, ']') || str_contains($token, '&')) {
            return true;
        }

        if (preg_match('/\/\d/', $token) === 1) {
            return true;
        }

        return preg_match(
            '/^(?:'
            . 'text-|bg-|border(?:-[trblxy])?-|'
            . 'p[trblxy]?-|m[trblxy]?-|gap-|space-[xy]-|'
            . 'flex(?:-|$)|inline-flex|grid(?:-|$)|inline-grid|'
            . 'block|inline-block|inline|hidden|contents|'
            . 'w-|h-|min-w-|min-h-|max-w-|max-h-|size-|'
            . 'leading-|tracking-|font-(?!claude)|'
            . 'list-|whitespace-|break-|'
            . 'rounded|shadow|opacity-|z-|'
            . 'top-|bottom-|left-|right-|inset-|'
            . 'items-|justify-|self-|place-|order-|col-|row-|'
            . 'underline|line-through|no-underline|italic|not-italic|'
            . 'uppercase|lowercase|capitalize|normal-case|truncate|'
            . 'overflow-|object-|divide-|ring-|outline-|'
            . 'sr-only|not-sr-only|align-|float-|clear-|'
            . 'aspect-|shrink-|grow|basis-|'
            . 'decoration-|underline-offset-|'
            . 'transition|duration-|ease-|'
            . 'cursor-|select-|pointer-events-|'
            . 'visible|invisible|collapse'
            . ')/',
            $token,
        ) === 1;
    }

    private function isPreservedWordPressClass(string $token): bool
    {
        if (preg_match('/^(?:wp-|align|has-text-|has-background-|is-style-|size-|omi-|seo-editor-link$)/i', $token) === 1) {
            return true;
        }

        return in_array($token, [
            'alignleft',
            'aligncenter',
            'alignright',
            'alignnone',
            'alignwide',
            'alignfull',
            'wp-caption',
            'wp-block-image',
            'wp-block-video',
            'wp-block-gallery',
            'wp-element-caption',
            'screen-reader-text',
        ], true);
    }

    private function unwrapTransientMarks(DOMElement $root): void
    {
        $marks = [];
        foreach ($root->getElementsByTagName('mark') as $mark) {
            if ($mark instanceof DOMElement) {
                $marks[] = $mark;
            }
        }

        foreach ($marks as $mark) {
            $this->unwrapNode($mark);
        }
    }

    private function stripTransientClasses(DOMElement $root): void
    {
        $walker = $root->getElementsByTagName('*');

        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($walker as $el) {
            if ($el instanceof DOMElement) {
                $elements[] = $el;
            }
        }

        foreach ($elements as $el) {
            if (! $this->elementHasTransientClass($el)) {
                continue;
            }

            if (strtolower($el->tagName) === 'a') {
                $this->removeTransientClassesFromElement($el);

                continue;
            }

            if (in_array(strtolower($el->tagName), ['mark', 'span'], true)) {
                $this->unwrapNode($el);

                continue;
            }

            $this->removeTransientClassesFromElement($el);
        }
    }

    private function elementHasTransientClass(DOMElement $el): bool
    {
        $class = (string) $el->getAttribute('class');
        if ($class === '') {
            return false;
        }

        foreach (self::TRANSIENT_CLASSES as $transient) {
            if (preg_match('/\b' . preg_quote($transient, '/') . '\b/', $class) === 1) {
                return true;
            }
        }

        return false;
    }

    private function removeTransientClassesFromElement(DOMElement $el): void
    {
        $classes = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter(
            $classes,
            static fn (string $c): bool => $c !== '' && ! in_array($c, self::TRANSIENT_CLASSES, true),
        ));

        if ($classes === []) {
            $el->removeAttribute('class');
        } else {
            $el->setAttribute('class', implode(' ', $classes));
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
}
