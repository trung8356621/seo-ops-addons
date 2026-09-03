<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

/**
 * Conservative token estimator when exact tokenizer is unavailable.
 * Vietnamese-aware: CJK/vi dense text ≈ more tokens per char than English.
 */
final class PromptTokenEstimator
{
    public const FAMILY_DEFAULT = 'char_heuristic_v1';

    public function estimate(string $text, string $family = self::FAMILY_DEFAULT): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $chars = mb_strlen($text);
        $latin = preg_match_all('/[A-Za-z0-9]/', $text) ?: 0;
        $latinRatio = $chars > 0 ? ($latin / $chars) : 1.0;

        // English-ish ≈ 4 chars/token; Vietnamese/CJK denser ≈ 2.2–2.8.
        $charsPerToken = $latinRatio >= 0.7 ? 4.0 : ($latinRatio >= 0.35 ? 3.0 : 2.4);

        // JSON / HTML density inflates tokens vs plain prose.
        if ($this->looksJson($text)) {
            $charsPerToken *= 0.85;
        } elseif ($this->looksHtml($text)) {
            $charsPerToken *= 0.9;
        }

        return max(1, (int) ceil($chars / $charsPerToken));
    }

    private function looksJson(string $text): bool
    {
        $trim = ltrim($text);

        return str_starts_with($trim, '{') || str_starts_with($trim, '[');
    }

    private function looksHtml(string $text): bool
    {
        return preg_match('/<\/?[a-z][\s\S]*>/i', $text) === 1;
    }

    /**
     * @param  list<string>|array<int, string>  $parts
     */
    public function estimateParts(array $parts, string $family = self::FAMILY_DEFAULT): int
    {
        $total = 0;
        foreach ($parts as $part) {
            if (! is_string($part) || $part === '') {
                continue;
            }
            $total += $this->estimate($part, $family);
        }

        return $total;
    }
}
