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

        $words = $this->countWords($trimmed);
        if (
            $unit->role === SectionedFreeSectionUnit::ROLE_FAQ
            && (strcasecmp($trimmed, '[omi_faq]') === 0 || str_contains($trimmed, '[omi_faq]'))
        ) {
            return max(1, $words);
        }

        if ($words < self::INCOMPLETE_WORD_THRESHOLD) {
            throw new PromptRunException(
                'Sectioned free section below minimum word threshold ('
                .$words.' < '.self::INCOMPLETE_WORD_THRESHOLD.').',
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
}
