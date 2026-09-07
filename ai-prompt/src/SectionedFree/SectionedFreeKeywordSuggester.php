<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Deterministic short keyword list from vocabulary — never injects raw vocabulary.
 */
final class SectionedFreeKeywordSuggester
{
    public const MAX_KEYWORDS = 5;

    /**
     * @return list<string>
     */
    public function suggestForSection(SectionedFreeSectionUnit $unit, string $vocabularyRaw): array
    {
        $candidates = $this->extractKeywordCandidates($vocabularyRaw);
        if ($candidates === []) {
            return [];
        }

        $haystack = mb_strtolower($unit->label.' '.$unit->scopeMarkdown());
        $scored = [];
        foreach ($candidates as $keyword) {
            $score = $this->score($keyword, $haystack);
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['keyword' => $keyword, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $out = [];
        foreach (array_slice($scored, 0, self::MAX_KEYWORDS) as $row) {
            $out[] = $row['keyword'];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractKeywordCandidates(string $vocabularyRaw): array
    {
        $text = trim($vocabularyRaw);
        if ($text === '') {
            return [];
        }

        $candidates = [];
        if (preg_match_all('/^\s*[-*•]\s+(.+)$/mu', $text, $m) > 0) {
            foreach ($m[1] as $line) {
                $line = trim((string) $line);
                $line = preg_replace('/\s*[—|:].*$/u', '', $line) ?? $line;
                $line = trim($line, " \t\"'`");
                if ($this->looksLikeKeyword($line)) {
                    $candidates[$line] = true;
                }
            }
        }

        // Also harvest short quoted / bold fragments.
        if (preg_match_all('/\*\*([^*]{3,80})\*\*/u', $text, $bold) > 0) {
            foreach ($bold[1] as $line) {
                $line = trim((string) $line);
                if ($this->looksLikeKeyword($line)) {
                    $candidates[$line] = true;
                }
            }
        }

        return array_keys($candidates);
    }

    private function looksLikeKeyword(string $line): bool
    {
        if ($line === '' || mb_strlen($line) > 80) {
            return false;
        }
        // Reject long analysis sentences.
        if (substr_count($line, ' ') > 10) {
            return false;
        }
        if (str_contains(mb_strtolower($line), 'unique_vocabulary_secret_marker')) {
            return false;
        }

        return true;
    }

    private function score(string $keyword, string $haystack): int
    {
        $kw = mb_strtolower($keyword);
        if ($kw === '' || $haystack === '') {
            return 0;
        }
        $score = 0;
        foreach (preg_split('/\s+/u', $kw) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 3) {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $score += 2;
            }
        }
        if (str_contains($haystack, $kw)) {
            $score += 5;
        }

        return $score;
    }
}
