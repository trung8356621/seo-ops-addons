<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Strip Livewire/Blade morph markers that leak into Agent message text.
 * Does not strip arbitrary HTML comments from legitimate user content.
 */
final class AgentMessageOutputSanitizer
{
    private const MARKER_PATTERN = '/<!--\s*\[if\s+(?:END)?BLOCK\]><!\[endif\]-->/iu';

    public function containsMarkers(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        return preg_match(self::MARKER_PATTERN, $text) === 1;
    }

    public function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if ($text === '' || ! $this->containsMarkers($text)) {
            return $text;
        }

        $clean = preg_replace(self::MARKER_PATTERN, '', $text) ?? $text;
        // Collapse blank runs introduced by marker removal — keep single newlines.
        $clean = preg_replace("/[ \t]+\n/", "\n", $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * @param  array<string, mixed>|null  $structured
     * @return array<string, mixed>|null
     */
    public function sanitizeStructured(?array $structured): ?array
    {
        if ($structured === null) {
            return null;
        }

        return $this->walk($structured);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function walk(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->sanitize($value);
            } elseif (is_array($value)) {
                $out[$key] = $this->walk($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
