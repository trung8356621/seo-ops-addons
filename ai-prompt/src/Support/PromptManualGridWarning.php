<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Soft warnings when prompt template text conflicts with Quick Split config.
 * Does not mutate prompt content.
 */
final class PromptManualGridWarning
{
    /**
     * @return list<string>
     */
    public static function detect(string $promptText, bool $splitEnabled, int $gridSize): array
    {
        $text = trim($promptText);
        if ($text === '') {
            return [];
        }

        $warnings = [];
        $hasManualGridLanguage = self::mentionsManualGridLanguage($text);

        if (! $splitEnabled && $hasManualGridLanguage) {
            $warnings[] = 'Prompt text still mentions contact sheet / grid / multi-panel layout, but Quick Split is off. Runtime SINGLE_IMAGE mode will take priority; prompt text is not auto-edited.';
        }

        if ($splitEnabled) {
            foreach (self::extractExplicitGrids($text) as [$rows, $cols]) {
                if ($rows !== $gridSize || $cols !== $gridSize) {
                    $warnings[] = sprintf(
                        'Prompt text mentions “%d×%d”, but Quick Split is configured as %d×%d. The generated layout hook will use %d×%d.',
                        $rows,
                        $cols,
                        $gridSize,
                        $gridSize,
                        $gridSize,
                        $gridSize,
                    );
                }
            }

            $numbered = self::countNumberedPanelLines($text);
            $expected = $gridSize * $gridSize;
            if ($numbered > 0 && $numbered !== $expected) {
                $warnings[] = sprintf(
                    'Prompt lists %d numbered panel scenes, but Quick Split expects %d panels (%d×%d).',
                    $numbered,
                    $expected,
                    $gridSize,
                    $gridSize,
                );
            }
        }

        return array_values(array_unique($warnings));
    }

    public static function mentionsManualGridLanguage(string $text): bool
    {
        return (bool) preg_match(
            '/\b(contact\s*sheet|sprite\s*sheet|collage|multiple\s+panels?|multi[\s-]?panel|grid\s+of|\d+\s*[×x]\s*\d+)\b/iu',
            $text,
        );
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function extractExplicitGrids(string $text): array
    {
        if (! preg_match_all('/(\d+)\s*[×x]\s*(\d+)/u', $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($matches as $match) {
            $rows = (int) $match[1];
            $cols = (int) $match[2];
            if ($rows >= PromptPostProcessing::GRID_SIZE_MIN && $cols >= PromptPostProcessing::GRID_SIZE_MIN) {
                $out[] = [$rows, $cols];
            }
        }

        return $out;
    }

    public static function countNumberedPanelLines(string $text): int
    {
        if (! preg_match_all('/^\s*(\d+)\.\s+\S+/mu', $text, $matches)) {
            return 0;
        }

        $numbers = array_map('intval', $matches[1]);
        if ($numbers === []) {
            return 0;
        }

        sort($numbers);
        $unique = array_values(array_unique($numbers));
        if ($unique === range(1, count($unique))) {
            return count($unique);
        }

        return 0;
    }
}
