<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordCanonicalizer
{
    /**
     * Display canonical keeps Vietnamese accents. Folded text is matching only.
     *
     * @param  list<array{normalized_text: string, folded_text: string, raw_text?: string}>  $members
     */
    public function pickDisplay(array $members): string
    {
        $best = '';
        $bestScore = -1;
        foreach ($members as $row) {
            $raw = trim((string) ($row['raw_text'] ?? ''));
            $normalized = trim((string) ($row['normalized_text'] ?? ''));
            $candidate = $raw !== '' ? $raw : $normalized;
            if ($candidate === '') {
                continue;
            }
            $score = $this->displayScore($candidate, $normalized);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function exactKey(string $folded): string
    {
        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    public function isNearDuplicate(string $aFolded, string $bFolded): bool
    {
        $a = $this->exactKey($aFolded);
        $b = $this->exactKey($bFolded);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $aTokens = $this->tokens($a);
        $bTokens = $this->tokens($b);
        if ($aTokens === [] || $bTokens === []) {
            return false;
        }
        $jaccard = $this->jaccard($aTokens, $bTokens);
        if ($jaccard >= 0.86) {
            $ratio = min(mb_strlen($a), mb_strlen($b)) / max(mb_strlen($a), mb_strlen($b));

            return $ratio >= 0.78;
        }
        if (abs(mb_strlen($a) - mb_strlen($b)) > 8) {
            return false;
        }
        if (max(mb_strlen($a), mb_strlen($b)) > 80) {
            return false;
        }

        return levenshtein($a, $b) <= 2;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $folded): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $folded) ?: []));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = array_intersect($sa, $sb);
        $union = array_unique(array_merge($sa, $sb));
        if ($union === []) {
            return 0.0;
        }

        return count($inter) / count($union);
    }

    public function displayScore(string $text, string $normalized = ''): int
    {
        $text = trim($text);
        if ($text === '') {
            return -1;
        }
        $lower = mb_strtolower($text, 'UTF-8');
        $upper = mb_strtoupper($text, 'UTF-8');
        $score = 10;
        if ($text === $upper && $text !== $lower) {
            $score -= 6;
        } elseif ($text === $lower) {
            $score -= 2;
        } else {
            $score += 4;
        }
        $first = mb_substr($text, 0, 1, 'UTF-8');
        if ($first !== '' && $first === mb_strtoupper($first, 'UTF-8') && $first !== mb_strtolower($first, 'UTF-8')) {
            $score += 3;
        }
        if ($normalized !== '' && $this->stripForCompare($text) === $this->stripForCompare($normalized)) {
            $score += 1;
        }
        $score -= min(8, mb_strlen($text));

        return $score;
    }

    public function prettyLabel(string $phrase): string
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }
        $lower = mb_strtolower($phrase, 'UTF-8');
        $upper = mb_strtoupper($phrase, 'UTF-8');
        if ($phrase === $upper && $phrase !== $lower) {
            return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
        }

        return $phrase;
    }

    private function stripForCompare(string $text): string
    {
        return (new KeywordNormalizer())->fold($text);
    }
}
