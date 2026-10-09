<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Inserts and replaces managed CTA blocks without rebuilding unrelated HTML.
 */
final class CtaBlockRenderer
{
    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{html: string, applied: list<string>, skipped: list<string>}
     */
    public function apply(string $html, array $operations): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="cta-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('cta-root');
        if (! $root instanceof DOMElement) {
            return ['html' => $html, 'applied' => [], 'skipped' => ['unparsed']];
        }

        $applied = [];
        $skipped = [];
        foreach ($operations as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $id = (string) ($operation['id'] ?? $kind);
            $ok = match ($kind) {
                'remove_managed' => $this->removeManaged($root, (string) ($operation['placement_id'] ?? '')),
                'remove' => $this->removeByText($root, (string) ($operation['text'] ?? '')),
                'insert' => $this->insert($dom, $root, $operation),
                default => false,
            };
            if ($ok) {
                $applied[] = $id;
            } else {
                $skipped[] = $id;
            }
        }

        return [
            'html' => $this->innerHtml($root),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    public function blockHtml(string $placementId, string $sectionId, string $intent, ?string $alias, string $origin, string $text, ?string $linkHtml): string
    {
        $safeText = $this->interpolate($text, $alias, $linkHtml);
        $attrs = sprintf(
            'class="seo-managed-cta" data-cta-placement="%s" data-cta-section="%s" data-cta-intent="%s" data-cta-alias="%s" data-cta-origin="%s" data-cta-manual="0"',
            $this->esc($placementId),
            $this->esc($sectionId),
            $this->esc($intent),
            $this->esc((string) $alias),
            $this->esc($origin),
        );

        return '<blockquote '.$attrs.'><p>'.$safeText.'</p></blockquote>';
    }

    private function interpolate(string $text, ?string $alias, ?string $linkHtml): string
    {
        if ($alias === null || $alias === '' || $linkHtml === null) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $token = '['.$alias.']';
        $parts = explode($token, $text, 2);
        if (count($parts) === 1) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return htmlspecialchars($parts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            .$linkHtml
            .htmlspecialchars($parts[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function removeManaged(DOMElement $root, string $placementId): bool
    {
        $removed = false;
        $nodes = [];
        foreach ($root->getElementsByTagName('blockquote') as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }
        foreach ($nodes as $node) {
            if (! str_contains((string) $node->getAttribute('class'), 'seo-managed-cta')) {
                continue;
            }
            if ($node->getAttribute('data-cta-manual') === '1') {
                continue;
            }
            if ($placementId !== '' && $node->getAttribute('data-cta-placement') !== $placementId) {
                continue;
            }
            $node->parentNode?->removeChild($node);
            $removed = true;
        }

        return $removed;
    }

    private function removeByText(DOMElement $root, string $text): bool
    {
        $needle = $this->normalize($text);
        if ($needle === '') {
            return false;
        }
        foreach (['blockquote', 'p'] as $tag) {
            $nodes = [];
            foreach ($root->getElementsByTagName($tag) as $node) {
                if ($node instanceof DOMElement) {
                    $nodes[] = $node;
                }
            }
            foreach ($nodes as $node) {
                if ($node->getAttribute('data-cta-manual') === '1') {
                    continue;
                }
                if (str_contains((string) $node->getAttribute('class'), 'seo-managed-cta')) {
                    continue;
                }
                if ($this->normalize($node->textContent ?? '') !== $needle) {
                    continue;
                }
                $node->parentNode?->removeChild($node);

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function insert(DOMDocument $dom, DOMElement $root, array $operation): bool
    {
        $sectionId = (string) ($operation['section_id'] ?? '');
        $fragment = (string) ($operation['html'] ?? '');
        if ($sectionId === '' || $fragment === '') {
            return false;
        }
        $anchor = $this->sectionEnd($root, $sectionId);
        if (! $anchor instanceof DOMNode) {
            return false;
        }
        $wrapper = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapper->loadHTML('<?xml encoding="utf-8" ?><div id="cta-insert">'.$fragment.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $source = $wrapper->getElementById('cta-insert');
        if (! $source instanceof DOMElement) {
            return false;
        }
        $imported = [];
        foreach ($source->childNodes as $child) {
            $imported[] = $dom->importNode($child, true);
        }
        $parent = $anchor->parentNode;
        if (! $parent instanceof DOMNode) {
            return false;
        }
        $next = $anchor->nextSibling;
        foreach ($imported as $node) {
            if ($next instanceof DOMNode) {
                $parent->insertBefore($node, $next);
            } else {
                $parent->appendChild($node);
            }
        }

        return $imported !== [];
    }

    private function sectionEnd(DOMElement $root, string $sectionId): ?DOMNode
    {
        $index = 1;
        $last = null;
        $started = false;
        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $current = 'section_'.$index;
            if (in_array($tag, ['h2', 'h3'], true)) {
                if ($started && $last instanceof DOMNode) {
                    $index++;
                    if ($current === $sectionId) {
                        return $last;
                    }
                }
                $last = $child;
                $started = true;
                continue;
            }
            $started = true;
            $last = $child;
        }
        if (('section_'.$index) === $sectionId) {
            return $last;
        }

        return null;
    }

    private function innerHtml(DOMElement $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $root->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
