<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Keyword Grouping Eligibility Gate — structural validity only.
 *
 * Future semantic reasons (Match & Research, consumer policy) can be added
 * here without changing keyword loading. Industry Group evidence is not a whitelist.
 */
final class KeywordGroupingEligibilityGate
{
    /** @var list<string> */
    private const EXCLUDING_FLAGS = ['url_like', 'contact_like'];

    /**
     * @param  list<KeywordGroupingEligibilityCandidate>  $candidates
     * @return list<KeywordGroupingEligibilityDecision>
     */
    public function decide(array $candidates): array
    {
        $decisions = [];
        foreach ($candidates as $candidate) {
            $decisions[] = $this->decideOne($candidate);
        }

        return $decisions;
    }

    public function decideOne(KeywordGroupingEligibilityCandidate $candidate): KeywordGroupingEligibilityDecision
    {
        $text = trim($candidate->text);
        $flags = [];
        if ($this->urlLike($text)) {
            $flags[] = 'url_like';
        }
        foreach ($this->contactFlags($text) as $flag) {
            $flags[] = $flag;
        }
        if ($this->questionLike($text)) {
            $flags[] = 'question_like';
        }

        $excludeReasons = array_values(array_intersect(self::EXCLUDING_FLAGS, $flags));

        return new KeywordGroupingEligibilityDecision(
            ref: $candidate->ref,
            text: $text,
            eligible: $excludeReasons === [],
            flags: $flags,
            excludeReasons: $excludeReasons,
        );
    }

    private function urlLike(string $text): bool
    {
        if (preg_match('#https?://#i', $text) === 1) {
            return true;
        }

        return preg_match('#\bwww\.[a-z0-9-]+(?:\.[a-z0-9-]+)+#i', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private function contactFlags(string $text): array
    {
        $flags = [];
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text) === 1) {
            $flags[] = 'email_like';
        }
        if ($this->phoneLike($text)) {
            $flags[] = 'phone_like';
        }
        if ($flags !== []) {
            array_unshift($flags, 'contact_like');
        }

        return $flags;
    }

    private function phoneLike(string $text): bool
    {
        if (preg_match_all('/\+?\d[\d\s.\-]{7,}\d/u', $text, $matches) < 1) {
            return false;
        }

        foreach ($matches[0] as $chunk) {
            $digits = preg_replace('/\D/', '', $chunk) ?? '';
            $length = strlen($digits);
            if ($length < 9 || $length > 15) {
                continue;
            }
            if (str_starts_with($chunk, '+') || str_starts_with($digits, '0')) {
                return true;
            }
        }

        return false;
    }

    private function questionLike(string $text): bool
    {
        if (str_contains($text, '?') || str_contains($text, '？')) {
            return true;
        }

        $lower = mb_strtolower($text);
        foreach (['ở đâu', 'loại nào', 'bao nhiêu', 'là gì', 'thế nào', 'tại sao', 'khi nào', 'bao lâu'] as $cue) {
            if (str_contains($lower, $cue)) {
                return true;
            }
        }

        return false;
    }
}
