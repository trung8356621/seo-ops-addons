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
     * @throws PromptRunException retryable when below incomplete threshold
     */
    public function assertAcceptable(string $output, SectionedFreeSectionUnit $unit): int
    {
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
