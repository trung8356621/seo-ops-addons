<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Rejects Prompt Hook instruction / template echo leaking into SEO meta description.
 */
final class MetaDescriptionOutputGuard
{
    /**
     * Fingerprints from hook presentation / template — never valid meta copy.
     *
     * @var list<string>
     */
    private const INSTRUCTION_MARKERS = [
        'return exactly one meta description',
        'do not add explanations, prefixes',
        'hook constraints',
        'base the description on the title',
        // Prompt template uses "If the old_description…" — keep both phrasings.
        'if the old_description is not null',
        'if old_description is not null',
        'use it as context to improve',
        'target length between',
        'do not invent specific facts',
        'do not invent facts that are not present',
        'one complete meta description — no prefix',
        'write an seo meta description',
        'write one seo meta description',
        'based on the following',
        'use all punycode',
        'following data',
        'stick to the title',
        'follow the title',
        'bám sát tiêu đề',
        'chỉ trả về đúng',
        'do not invent',
        'không bịa',
        'yêu cầu:',
        'nhiệm vụ',
        'improve on the old description',
        'should not make up',
        'clearly show the benefits',
        'length between',
        '{{title}}',
        '{{old_description}}',
        '{{min_length}}',
        '{{max_length}}',
    ];

    /** Max chars accepted as SERP-context old_description (prompt only; draft/SEO untouched). */
    public const OLD_DESCRIPTION_CONTEXT_MAX = 200;

    public static function looksLikePromptInstruction(?string $value): bool
    {
        $text = trim((string) $value);
        if ($text === '') {
            return false;
        }

        $lower = mb_strtolower($text);
        foreach (self::INSTRUCTION_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // Task/Requirement preambles — not customer-facing SERP copy.
        if (preg_match('/^(task|requirements?|output|instruction|constraints?|note|format|prompt)\s*:/iu', $text) === 1) {
            return true;
        }

        // Ends mid-instruction (colon) without a product sentence.
        if (str_ends_with($text, ':') && mb_strlen($text) < 160) {
            return true;
        }

        // Imperative / template leftover openings.
        if (preg_match('/^(stick to|write |return |do not |chỉ trả|bám sát|viết )/iu', $text) === 1) {
            return true;
        }

        // Bare URLs / CDN asset paths are not meta descriptions.
        if (preg_match('#^https?://#i', $text) === 1
            || preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $text) === 1) {
            return true;
        }

        // Multi-bullet instruction dump (template echo).
        $bulletLines = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+\S/u', (string) $line) === 1) {
                $bulletLines++;
            }
        }

        // Numbered/bulleted requirement lists are never a single SERP paragraph.
        return $bulletLines >= 3;
    }

    /**
     * Reject outputs that are mostly the title restated with a short instruction prefix.
     */
    public static function looksLikeTitleRestatement(?string $value, ?string $title): bool
    {
        $text = trim((string) $value);
        $titleText = trim((string) $title);
        if ($text === '' || $titleText === '') {
            return false;
        }

        if (self::looksLikePromptInstruction($text)) {
            return true;
        }

        $norm = static fn (string $s): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
        $t = $norm($text);
        $titleNorm = $norm($titleText);
        if ($titleNorm === '') {
            return false;
        }

        if ($t === $titleNorm) {
            return true;
        }

        // "Stick to the title: {title}" / "{title}" with tiny wrapper.
        if (str_contains($t, $titleNorm) && mb_strlen($t) <= mb_strlen($titleNorm) + 40) {
            return true;
        }

        return false;
    }

    /**
     * Prefer a non-instruction paragraph when the model prefixes echoed rules.
     *
     * Never peel individual bullet lines from an instruction dump — that produced
     * false "meta" like "If the old_description is not null, use it as context…".
     */
    public static function extractCandidate(?string $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || ! self::looksLikePromptInstruction($text)) {
            return $text;
        }

        $parts = preg_split('/\R\s*\R/u', $text) ?: [];
        foreach ($parts as $part) {
            $candidate = trim((string) $part);
            if ($candidate !== ''
                && ! self::looksLikePromptInstruction($candidate)
                && mb_strlen($candidate) >= 50) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Sanitize runtime old_description for prompt context only.
     * Invalid / instruction / oversized values become null — never mutate SEO DB.
     */
    public static function sanitizeOldDescriptionForPrompt(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }

        if (self::looksLikePromptInstruction($text)) {
            return null;
        }

        if (mb_strlen($text) > self::OLD_DESCRIPTION_CONTEXT_MAX) {
            return null;
        }

        return $text;
    }
}
