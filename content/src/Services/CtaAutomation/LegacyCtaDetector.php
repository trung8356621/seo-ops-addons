<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Structural legacy CTA detection. Semantic class is applied later by the planner.
 */
final class LegacyCtaDetector
{
    /**
     * @param  list<array{section_id: string, heading: string}>  $sections
     * @return list<array{candidate_id: string, section_id: string, heading: string, text: string, structural_signal: string, confidence: string}>
     */
    public function detect(string $html, array $sections = []): array
    {
        $root = $this->root($html);
        if (! $root instanceof DOMElement) {
            return [];
        }

        $found = [];
        $ordinal = 0;
        foreach (CtaSectionMap::bind($root) as $section) {
            $occurrence = 0;
            foreach ($section['nodes'] as $child) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['h2', 'h3'], true) || $this->isManual($child) || $this->isManaged($child)) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', $child->textContent ?? '') ?? '');
                if ($text === '' || ! $this->looksPromotional($text)) {
                    continue;
                }
                $words = $this->words($text);
                $signal = 'standalone_paragraph';
                $confidence = 'uncertain';
                if ($tag === 'blockquote') {
                    $signal = 'standalone_blockquote';
                    $confidence = $words <= 80 ? 'high' : 'uncertain';
                } elseif ($words <= 18 && $this->channelCount($text) >= 1) {
                    $signal = 'standalone_paragraph';
                    $confidence = 'high';
                } else {
                    $signal = 'mixed_paragraph';
                    $confidence = 'uncertain';
                }
                $ordinal++;
                $occurrence++;
                $found[] = [
                    'candidate_id' => 'legacy_'.$ordinal,
                    'section_id' => $section['section_id'],
                    'heading' => $section['heading'],
                    'text' => $text,
                    'occurrence' => $occurrence,
                    'structural_signal' => $signal,
                    'confidence' => $confidence,
                ];
            }
        }

        return $found;
    }

    private function looksPromotional(string $text): bool
    {
        $lower = mb_strtolower($text);
        $channel = $this->channelCount($text) > 0;
        $verb = preg_match('/liên hệ|contact us|gọi ngay|inbox|đặt hàng|mua ngay|tham quan|showroom|send an email|browse our website|visit our/iu', $lower) === 1;

        return $channel && $verb;
    }

    private function channelCount(string $text): int
    {
        $lower = mb_strtolower($text);
        $hits = 0;
        foreach (['zalo', 'facebook', 'mailto:', 'email', '@', 'hotline', 'điện thoại', 'phone', 'địa chỉ', 'address', 'website', 'http'] as $needle) {
            if (str_contains($lower, $needle)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function words(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return count(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function isManaged(DOMElement $node): bool
    {
        return str_contains((string) $node->getAttribute('class'), 'seo-managed-cta');
    }

    private function isManual(DOMElement $node): bool
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
