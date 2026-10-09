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
     * @return array{html: string, applied: list<string>, skipped: list<string>, ok: bool}
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
            return ['ok' => false, 'html' => $html, 'applied' => [], 'skipped' => ['unparsed']];
        }

        $applied = [];
        foreach ($operations as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $id = (string) ($operation['id'] ?? $kind);
            $ok = match ($kind) {
                'remove_managed' => $this->removeManaged($root, (string) ($operation['placement_id'] ?? '')),
                'remove' => $this->removeByText($root, $operation),
                'insert' => $this->insert($dom, $root, $operation),
                default => false,
            };
            if (! $ok) {
                return [
                    'ok' => false,
                    'html' => $html,
                    'applied' => [],
                    'skipped' => [$id],
                ];
            }
            $applied[] = $id;
        }

        return [
            'ok' => true,
            'html' => $this->innerHtml($root),
            'applied' => $applied,
            'skipped' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function readStylePresets(string $html): array
    {
        $found = [];
        if (preg_match_all('/data-cta-placement="([^"]+)"[^>]*data-cta-style="(soft|consultation|conversion)"|data-cta-style="(soft|consultation|conversion)"[^>]*data-cta-placement="([^"]+)"/', $html, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $placement = $match[1] !== '' ? $match[1] : ($match[4] ?? '');
                $style = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
                if ($placement !== '' && $style !== '') {
                    $found[$placement] = $style;
                }
            }
        }

        return $found;
    }

    public function blockHtml(string $placementId, string $sectionId, string $intent, ?string $alias, string $origin, string $text, string $style = 'soft', string $runId = ''): string
    {
        $style = $this->style($style);
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $run = $runId !== '' ? ' data-cta-run="'.$this->esc($runId).'"' : '';
        $attrs = sprintf(
            'class="seo-managed-cta" data-cta-placement="%s" data-cta-section="%s" data-cta-intent="%s" data-cta-alias="%s" data-cta-origin="%s" data-cta-style="%s" data-cta-manual="0"%s',
            $this->esc($placementId),
            $this->esc($sectionId),
            $this->esc($intent),
            $this->esc((string) $alias),
            $this->esc($origin),
            $this->esc($style),
            $run,
        );

        return '<div '.$attrs.'>[seo_ops_cta style="'.$this->esc($style).'" placement="'.$this->esc($placementId).'" intent="'.$this->esc($intent).'"]'.$safeText.'[/seo_ops_cta]</div>';
    }

    private function style(string $style): string
    {
        return in_array($style, ['soft', 'consultation', 'conversion'], true) ? $style : 'soft';
    }

    private function removeManaged(DOMElement $root, string $placementId): bool
    {
        if ($placementId === '') {
            return false;
        }
        foreach ($root->getElementsByTagName('*') as $node) {
            if (! $node instanceof DOMElement || ! str_contains((string) $node->getAttribute('class'), 'seo-managed-cta')) {
                continue;
            }
            if ($node->getAttribute('data-cta-manual') === '1') {
                continue;
            }
            if ($node->getAttribute('data-cta-placement') !== $placementId) {
                continue;
            }
            $node->parentNode?->removeChild($node);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function removeByText(DOMElement $root, array $operation): bool
    {
        $needle = $this->normalize((string) ($operation['text'] ?? ''));
        $sectionId = (string) ($operation['section_id'] ?? '');
        if ($needle === '' || $sectionId === '') {
            return false;
        }
        $occurrence = max(1, (int) ($operation['occurrence'] ?? 1));
        $seen = 0;
        foreach (CtaSectionMap::bind($root) as $section) {
            if ($section['section_id'] !== $sectionId) {
                continue;
            }
            foreach ($section['nodes'] as $node) {
                if ($node->getAttribute('data-cta-manual') === '1' || str_contains((string) $node->getAttribute('class'), 'seo-managed-cta')) {
                    continue;
                }
                $tag = strtolower($node->tagName);
                if (! in_array($tag, ['p', 'blockquote'], true)) {
                    continue;
                }
                if ($this->normalize($node->textContent ?? '') !== $needle) {
                    continue;
                }
                $seen++;
                if ($seen !== $occurrence) {
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
        $placementId = (string) ($operation['placement_id'] ?? '');
        if ($sectionId === '' || $fragment === '') {
            return false;
        }
        if ($placementId !== '' && $this->placementExists($root, $placementId)) {
            return true;
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

    private function placementExists(DOMElement $root, string $placementId): bool
    {
        foreach ($root->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && $node->getAttribute('data-cta-placement') === $placementId) {
                return true;
            }
        }

        return false;
    }

    private function sectionEnd(DOMElement $root, string $sectionId): ?DOMNode
    {
        foreach (CtaSectionMap::bind($root) as $section) {
            if ($section['section_id'] !== $sectionId || $section['nodes'] === []) {
                continue;
            }

            return $section['nodes'][array_key_last($section['nodes'])];
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
