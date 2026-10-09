<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use DOMElement;
use DOMText;

/**
 * Fingerprint and section-target checks shared by preview and apply.
 */
final class CtaHtmlFingerprint
{
    public static function hash(string $html): string
    {
        $root = CtaSectionMap::root($html);
        if (! $root instanceof DOMElement) {
            return hash('sha256', '');
        }
        $serialized = '';
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent ?? '') === '') {
                continue;
            }
            $serialized .= $root->ownerDocument?->saveHTML($child) ?? '';
        }

        return hash('sha256', $serialized);
    }

    /**
     * @param  array<string, mixed>|null  $plan
     * @param  list<array<string, mixed>>|null  $changes
     */
    public static function targetsMatch(string $html, ?array $plan, ?array $changes): bool
    {
        $known = [];
        foreach (is_array($plan['sections'] ?? null) ? $plan['sections'] : [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $known[(string) ($section['section_id'] ?? '')] = self::heading($section['heading'] ?? '');
        }
        $root = CtaSectionMap::root($html);
        if (! $root instanceof DOMElement || $known === []) {
            return false;
        }
        $current = [];
        foreach (CtaSectionMap::bind($root) as $section) {
            $current[$section['section_id']] = self::heading($section['heading']);
        }
        $checked = false;
        foreach (is_array($changes) ? $changes : [] as $change) {
            if (! is_array($change)) {
                continue;
            }
            $sectionId = (string) ($change['section_id'] ?? '');
            if ($sectionId === '' || ! array_key_exists($sectionId, $known)) {
                continue;
            }
            $checked = true;
            if (! array_key_exists($sectionId, $current) || $current[$sectionId] !== $known[$sectionId]) {
                return false;
            }
        }

        return $checked;
    }

    private static function heading(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }
}
