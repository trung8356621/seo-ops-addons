<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use InvalidArgumentException;

/**
 * Strict JSON gate for New Content Planner AI output.
 * Importer SSOT: {@see NewContentSuggestionParser} — raw JSON array (or envelope), not prose.
 */
final class NewContentSuggestionStructuredResult
{
    public const CODE_INVALID = 'structured_output_invalid';

    public const CODE_INCOMPLETE = 'structured_output_incomplete';

    public const CODE_PROSE = 'structured_output_prose';

    public const CODE_EMPTY = 'structured_output_empty';

    /**
     * Decode provider text into a JSON array/object value the parser can flatten.
     *
     * @return array{ok: bool, value: mixed, error: string|null, code: string|null}
     */
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return ['ok' => true, 'value' => $raw, 'error' => null, 'code' => null];
        }

        if (! is_string($raw)) {
            return [
                'ok' => false,
                'value' => null,
                'error' => 'Planner output must be a JSON string or array.',
                'code' => self::CODE_INVALID,
            ];
        }

        $trimmed = self::stripOneMarkdownFence(trim($raw));
        if ($trimmed === '') {
            return [
                'ok' => false,
                'value' => null,
                'error' => 'Planner output is empty.',
                'code' => self::CODE_EMPTY,
            ];
        }

        // One unwrap of a JSON-encoded JSON string (provider sometimes double-encodes).
        // Does NOT scrape prose for the first [...] — first non-whitespace must stay [/{/" .
        $unwrapped = self::unwrapDoubleEncodedJsonString($trimmed);
        if ($unwrapped !== null) {
            $trimmed = $unwrapped;
        }

        $first = self::firstNonWhitespaceChar($trimmed);
        if ($first !== '[' && $first !== '{') {
            return [
                'ok' => false,
                'value' => null,
                'error' => 'Planner output must be JSON only (first character must be [ or {). Got prose/reasoning preamble.',
                'code' => self::CODE_PROSE,
            ];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $code = self::looksIncomplete($trimmed, $first)
                ? self::CODE_INCOMPLETE
                : self::CODE_INVALID;

            return [
                'ok' => false,
                'value' => null,
                'error' => ($code === self::CODE_INCOMPLETE
                    ? 'Planner JSON appears truncated/incomplete: '
                    : 'Planner output is not valid JSON: ').$e->getMessage(),
                'code' => $code,
            ];
        }

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'value' => null,
                'error' => 'Planner JSON root must be an array or object.',
                'code' => self::CODE_INVALID,
            ];
        }

        return ['ok' => true, 'value' => $decoded, 'error' => null, 'code' => null];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function decodeOrFail(mixed $raw): mixed
    {
        $decoded = self::decode($raw);
        if (! $decoded['ok']) {
            throw new InvalidArgumentException(
                (string) ($decoded['error'] ?? 'Planner structured output invalid.'),
            );
        }

        return $decoded['value'];
    }

    /**
     * OUTPUT CONTRACT footer appended to planning brief (consumed via {{brief}}).
     */
    public static function outputContractFooter(string $contentType, int $quantity): string
    {
        $isProduct = $contentType === NewContentSuggestionOptions::CONTENT_TYPE_PRODUCT;
        $qty = max(1, $quantity);

        $itemShape = $isProduct
            ? '{"keyword":"...","suggested_title":"...","description":"...","product_type":"...","gallery_description":"...","suggestion_reason":"...","source_signal":"keyword_gap"}'
            : '{"keyword":"...","suggested_title":"...","description":"...","suggestion_reason":"...","source_signal":"keyword_gap"}';

        $modeLines = $isProduct
            ? [
                'Mode: PRODUCT — every item MUST be a product-page planning candidate (not a blog/article topic).',
                'Include product_type and gallery_description on every item.',
                'Do not return Post/article-only items.',
            ]
            : [
                'Mode: POST — every item MUST be an article/post planning candidate.',
                'Do NOT include product_type or gallery_description.',
                'Do not return Product items.',
            ];

        $lines = [
            '',
            'OUTPUT CONTRACT — STRICT',
            'Return ONLY valid JSON matching the schema below.',
            'Do not output: explanations, reasoning, analysis, commentary, introductions, conclusions, markdown, ```json fences, XML, YAML, bullet lists, text before JSON, or text after JSON.',
            'Your response is consumed directly by a JSON parser.',
            'The FIRST non-whitespace character of your response MUST be: [',
            'The LAST non-whitespace character of your response MUST be: ]',
            'Return valid UTF-8 JSON with double quotes for keys/strings. No trailing commas, comments, single quotes, NaN, or undefined.',
            'Do not describe how you selected suggestions. Perform selection/deduplication internally; return only the final JSON.',
            ...$modeLines,
            'Root shape: a JSON array of about '.$qty.' objects.',
            'Each object shape: '.$itemShape,
            'source_signal must be one of: keyword_gap|cluster_gap|mcp_signal|gsc_signal|related_topic|manual_note|manual_focus',
            'suggestion_reason = short user-facing reason only (not chain-of-thought).',
            'keyword and suggested_title must be short concrete phrases — never paste planned-item lists or prompt context into keyword.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Compact repair brief — schema fix only, no new SEO research.
     */
    public static function repairBrief(string $invalidRaw, string $contentType, int $quantity): string
    {
        $snippet = trim($invalidRaw);
        if (mb_strlen($snippet) > 6000) {
            $snippet = mb_substr($snippet, 0, 6000).'…';
        }

        return implode("\n", [
            'REPAIR TASK — FORMAT ONLY',
            'The previous response is invalid for the required JSON contract.',
            'Convert the supplied candidate result into the exact required schema.',
            'Do not add new suggestions. Do not invent new keywords. Do not explain.',
            'Return only valid JSON.',
            NewContentSuggestionStructuredResult::outputContractFooter($contentType, $quantity),
            '',
            'INVALID PREVIOUS RESPONSE:',
            $snippet,
        ]);
    }

    private static function stripOneMarkdownFence(string $value): string
    {
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/u', $value, $m) === 1) {
            return trim($m[1]);
        }

        return $value;
    }

    /**
     * If the entire payload is a JSON string whose content is JSON array/object text, unwrap once.
     */
    private static function unwrapDoubleEncodedJsonString(string $trimmed): ?string
    {
        if (self::firstNonWhitespaceChar($trimmed) !== '"') {
            return null;
        }

        try {
            /** @var mixed $inner */
            $inner = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_string($inner)) {
            return null;
        }

        $innerTrimmed = trim($inner);
        $first = self::firstNonWhitespaceChar($innerTrimmed);
        if ($first !== '[' && $first !== '{') {
            return null;
        }

        return $innerTrimmed;
    }

    private static function firstNonWhitespaceChar(string $value): string
    {
        if (preg_match('/\S/u', $value, $m) === 1) {
            return $m[0];
        }

        return '';
    }

    private static function looksIncomplete(string $value, string $first): bool
    {
        if ($first === '[') {
            return substr_count($value, '[') > substr_count($value, ']');
        }
        if ($first === '{') {
            return substr_count($value, '{') > substr_count($value, '}');
        }

        return false;
    }
}
