<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Detects unresolved CTA / contact placeholders and empty sentinel values.
 */
final class CtaContactUsability
{
    /**
     * @param  array{type?: mixed, value?: mixed, label?: mixed, is_blank?: mixed, usable?: mixed}  $item
     */
    public static function isUsable(array $item): bool
    {
        if (($item['usable'] ?? null) === false || ($item['is_blank'] ?? null) === true) {
            return false;
        }

        $type = mb_strtolower(trim((string) ($item['type'] ?? '')));
        if ($type === '') {
            return false;
        }

        $value = trim((string) ($item['value'] ?? ''));
        $label = trim((string) ($item['label'] ?? ''));
        $candidate = $value !== '' ? $value : $label;

        if ($candidate === '' || self::isUnresolvedPlaceholder($candidate)) {
            return false;
        }

        if ($label !== '' && self::isUnresolvedPlaceholder($label)) {
            return false;
        }

        if ($value !== '' && self::isUnresolvedPlaceholder($value)) {
            return false;
        }

        return true;
    }

    public static function isUnresolvedPlaceholder(string $value): bool
    {
        $raw = trim($value);
        if ($raw === '') {
            return true;
        }

        if (preg_match('/^\[[^\]]+\]$/u', $raw) === 1) {
            return true;
        }

        if (preg_match('/^\{\{[^{}]+\}\}$/u', $raw) === 1) {
            return true;
        }

        if (preg_match('/^\{[^{}]+\}$/u', $raw) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function filterUsable(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! self::isUsable($item)) {
                continue;
            }
            $out[] = $item;
        }

        return array_values($out);
    }
}
