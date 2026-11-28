<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Minimum output / truncation sanity checks for one section.
 */
final class SectionedFreeSectionValidator
{
    /** Conservative incomplete threshold — below this ⇒ retry candidate. */
    public const INCOMPLETE_WORD_THRESHOLD = 120;

    public function countWords(string $text): int
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($normalized === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];

        return count(array_filter($parts, static fn (string $w): bool => $w !== ''));
    }

    public function looksTruncated(string $text): bool
    {
        $trimmed = rtrim($text);
        if ($trimmed === '') {
            return true;
        }

        // Obvious unfinished markdown fences.
        if (preg_match('/```[a-zA-Z0-9_-]*$/u', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedContextTexts
     *
     * @throws PromptRunException retryable when below incomplete threshold or certain language drift
     */
    public function assertAcceptable(
        string $output,
        SectionedFreeSectionUnit $unit,
        ?string $language = null,
        array $allowedContextTexts = [],
    ): int {
        $trimmed = trim($output);
        if ($trimmed === '') {
            throw new PromptRunException(
                'Sectioned free section produced empty output.',
                0,
                null,
                [
                    'classification' => AiFailureClass::OutputQuality->value,
                    'retryable' => true,
                    'section_id' => $unit->sectionId,
                    'failure_code' => 'SECTION_EMPTY',
                    'user_message' => 'Section output was empty.',
                ],
            );
        }

        if ($this->looksTruncated($trimmed)) {
            throw new PromptRunException(
                'Sectioned free section output looks truncated.',
                0,
                null,
                [
                    'classification' => AiFailureClass::OutputQuality->value,
                    'retryable' => true,
                    'section_id' => $unit->sectionId,
                    'failure_code' => 'SECTION_TRUNCATED',
                    'user_message' => 'Section output looks truncated.',
                ],
            );
        }

        $this->assertManagedRowCoverage($trimmed, $unit);

        if ($language !== null && trim($language) !== '' && $unit->role !== SectionedFreeSectionUnit::ROLE_FAQ) {
            $langCheck = $this->checkLanguageConsistency($trimmed, $unit, $language, $allowedContextTexts);
            if ($langCheck['certain_drift']) {
                throw new PromptRunException(
                    'Sectioned free section output drifted from requested language ('
                    .($langCheck['reason'] ?? 'language_drift').').',
                    0,
                    null,
                    [
                        'classification' => AiFailureClass::OutputQuality->value,
                        'retryable' => true,
                        'section_id' => $unit->sectionId,
                        'language' => $language,
                        'drift_reason' => $langCheck['reason'],
                        'drift_sample' => $langCheck['sample'],
                        'failure_code' => 'SECTION_LANGUAGE_DRIFT',
                        'user_message' => 'Section output drifted into an unexpected language; retrying section.',
                    ],
                );
            }
        }

        $words = $this->countWords($trimmed);
        if (
            $unit->role === SectionedFreeSectionUnit::ROLE_FAQ
            && (strcasecmp($trimmed, '[omi_faq]') === 0 || str_contains($trimmed, '[omi_faq]'))
        ) {
            return max(1, $words);
        }

        $minimum = $this->minimumWordsFor($unit);
        if ($words < $minimum) {
            throw new PromptRunException(
                'Sectioned free section below minimum word threshold ('
                .$words.' < '.$minimum.').',
                0,
                null,
                [
                    'classification' => AiFailureClass::OutputQuality->value,
                    'retryable' => true,
                    'section_id' => $unit->sectionId,
                    'word_count' => $words,
                    'failure_code' => 'SECTION_INCOMPLETE',
                    'user_message' => 'Section output was too short; retrying with another free model.',
                ],
            );
        }

        return $words;
    }

    /**
     * Lightweight language-consistency check. Only flags certain drift as retryable;
     * uncertain cases return a diagnostic warning without failing valid content.
     *
     * @param  list<string>  $allowedContextTexts
     * @return array{ok: bool, certain_drift: bool, warning: ?string, reason: ?string, sample: ?string}
     */
    public function checkLanguageConsistency(
        string $output,
        SectionedFreeSectionUnit $unit,
        string $language,
        array $allowedContextTexts = [],
    ): array {
        $cleaned = $this->stripNonProseArtifacts($output);
        if ($cleaned === '') {
            return ['ok' => true, 'certain_drift' => false, 'warning' => null, 'reason' => null, 'sample' => null];
        }

        $primaryLang = strtolower(trim(explode('-', str_replace('_', '-', $language))[0]));
        $contextParts = array_merge($allowedContextTexts, [$unit->label, $unit->scopeMarkdown()], $unit->requiredPoints);
        $allowedBlob = implode("\n", array_filter($contextParts, static fn ($v): bool => is_string($v) && $v !== ''));

        // 1. Unexpected non-Latin scripts (e.g. CJK/Hangul/Kana/Cyrillic/Arabic/Thai in vi/en articles).
        if (! in_array($primaryLang, ['zh', 'ja', 'ko', 'ru', 'uk', 'bg', 'sr', 'ar', 'fa', 'he', 'th'], true)) {
            preg_match_all('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{Cyrillic}\p{Arabic}\p{Thai}]/u', $allowedBlob, $allowedMatches);
            $allowedChars = array_flip($allowedMatches[0] ?? []);

            preg_match_all('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{Cyrillic}\p{Arabic}\p{Thai}]+/u', $cleaned, $scriptMatches);
            $unexpectedCount = 0;
            $firstSample = null;
            foreach ($scriptMatches[0] ?? [] as $token) {
                $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $unexpectedInToken = 0;
                foreach ($chars as $ch) {
                    if (! isset($allowedChars[$ch])) {
                        $unexpectedInToken++;
                    }
                }
                if ($unexpectedInToken > 0) {
                    $unexpectedCount += $unexpectedInToken;
                    $firstSample ??= $token;
                }
            }

            if ($unexpectedCount >= 2) {
                return [
                    'ok' => false,
                    'certain_drift' => true,
                    'warning' => null,
                    'reason' => 'unexpected_foreign_script',
                    'sample' => $firstSample,
                ];
            }
            if ($unexpectedCount === 1) {
                return [
                    'ok' => false,
                    'certain_drift' => false,
                    'warning' => 'single_unexpected_foreign_script_char',
                    'reason' => 'single_unexpected_foreign_script_char',
                    'sample' => $firstSample,
                ];
            }
        }

        // 2. Long foreign-language prose sentences in Vietnamese articles.
        if (SectionedFreeSectionPromptBuilder::isVietnamese($language)) {
            $warningSample = null;
            $lines = preg_split('/\R+/u', $cleaned) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^#{1,6}\s/u', $line) === 1 || str_starts_with($line, '|')) {
                    continue;
                }
                $sentences = preg_split('/(?<=[.!?])\s+/u', $line) ?: [$line];
                foreach ($sentences as $sentence) {
                    $sentence = trim((string) $sentence);
                    if ($sentence === '' || ($allowedBlob !== '' && mb_stripos($allowedBlob, $sentence) !== false)) {
                        continue;
                    }
                    $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $wordCount = count($words);
                    if ($wordCount < 7) {
                        continue;
                    }
                    $hasViDiacritic = preg_match(
                        '/[àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/iu',
                        $sentence,
                    ) === 1;
                    if ($hasViDiacritic) {
                        continue;
                    }
                    $enFunctionCount = preg_match_all(
                        '/\b(the|is|are|was|were|be|been|have|has|had|do|does|did|will|would|could|should|can|may|might|this|that|these|those|with|from|into|about|because|however|therefore|which|while|where|when|your|you|they|their|for|and|but|not)\b/iu',
                        $sentence,
                    ) ?: 0;

                    if ($wordCount >= 12 && $enFunctionCount >= 3) {
                        return [
                            'ok' => false,
                            'certain_drift' => true,
                            'warning' => null,
                            'reason' => 'long_foreign_sentence',
                            'sample' => mb_substr($sentence, 0, 120),
                        ];
                    }
                    if ($enFunctionCount >= 3 && $warningSample === null) {
                        $warningSample = mb_substr($sentence, 0, 120);
                    }
                }
            }
            if ($warningSample !== null) {
                return [
                    'ok' => false,
                    'certain_drift' => false,
                    'warning' => 'short_foreign_sentence',
                    'reason' => 'short_foreign_sentence',
                    'sample' => $warningSample,
                ];
            }
        }

        return ['ok' => true, 'certain_drift' => false, 'warning' => null, 'reason' => null, 'sample' => null];
    }

    private function stripNonProseArtifacts(string $text): string
    {
        $out = preg_replace('/<!--.*?-->/su', ' ', $text) ?? $text;
        $out = preg_replace('/```.*?```/su', ' ', $out) ?? $out;
        $out = preg_replace('/`[^`\n]+`/u', ' ', $out) ?? $out;
        $out = preg_replace('/https?:\/\/\S+/iu', ' ', $out) ?? $out;

        return trim($out);
    }

    public function minimumWordsFor(SectionedFreeSectionUnit $unit): int
    {
        if ($unit->role === SectionedFreeSectionUnit::ROLE_FAQ) {
            return 0;
        }
        // Non-grouped sectioned strategies keep their established validator.
        if ($unit->sourceRowIds === []) {
            return self::INCOMPLETE_WORD_THRESHOLD;
        }
        $ratio = $unit->role === SectionedFreeSectionUnit::ROLE_CONCLUSION ? 0.55 : 0.70;

        return max(35, (int) floor($unit->targetMinWords * $ratio));
    }

    private function assertManagedRowCoverage(string $output, SectionedFreeSectionUnit $unit): void
    {
        $markers = array_keys($unit->managedRowMarkers());
        if ($markers === [] || $unit->role === SectionedFreeSectionUnit::ROLE_FAQ) {
            return;
        }
        $missing = [];
        $cursor = 0;
        foreach ($markers as $marker) {
            $position = mb_strpos($output, $marker, $cursor);
            if ($position === false || substr_count($output, $marker) !== 1) {
                $missing[] = $marker;

                continue;
            }
            $cursor = $position + mb_strlen($marker);
        }
        if ($missing !== []) {
            throw new PromptRunException(
                'Sectioned free grouped output is missing or duplicating outline row markers.',
                0,
                null,
                [
                    'classification' => AiFailureClass::OutputQuality->value,
                    'retryable' => true,
                    'section_id' => $unit->sectionId,
                    'failure_code' => 'SECTION_OUTLINE_ROW_COVERAGE',
                    'missing_markers' => $missing,
                    'user_message' => 'Section output did not preserve every required outline row.',
                ],
            );
        }
    }
}
