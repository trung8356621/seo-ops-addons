<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

final class AiUsageTaxonomy
{
    public const ADDON_SEO = 'seo';
    public const ADDON_SEEDING = 'seeding';
    public const ADDON_UNKNOWN = 'unknown';

    public const MODULE_CONTENT_PROJECT = 'content_project';
    public const MODULE_TOPIC = 'topic';
    public const MODULE_SEO_AUDIT = 'seo_audit';
    public const MODULE_SEO_SCORING = 'seo_scoring';
    public const MODULE_SEEDING = 'seeding';
    public const MODULE_UNKNOWN = 'unknown';

    public const ACTION_OUTLINE = 'outline';
    public const ACTION_ARTICLE = 'article';
    public const ACTION_VOCABULARY = 'vocabulary';
    public const ACTION_METADATA = 'metadata';
    public const ACTION_CLUSTERING = 'clustering';
    public const ACTION_KEYWORD_CLASSIFICATION = 'keyword_classification';
    public const ACTION_AUDIT = 'audit';
    public const ACTION_SCORING = 'scoring';
    public const ACTION_GENERATE_COMMENT = 'generate_comment';
    public const ACTION_UNKNOWN = 'unknown';

    /**
     * @return array<string, string>
     */
    public static function addonLabels(): array
    {
        return [
            self::ADDON_SEO => 'SEO',
            self::ADDON_SEEDING => 'Seeding',
            self::ADDON_UNKNOWN => 'Chưa phân loại',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function moduleLabels(): array
    {
        return [
            self::MODULE_CONTENT_PROJECT => 'Content Project',
            self::MODULE_TOPIC => 'Topic',
            self::MODULE_SEO_AUDIT => 'SEO Audit',
            self::MODULE_SEO_SCORING => 'SEO Scoring',
            self::MODULE_SEEDING => 'Gen Comment',
            self::MODULE_UNKNOWN => 'Không rõ',
        ];
    }

    /**
     * Resolve taxonomy (addon, module, action) from prompt execution keys.
     *
     * @param  array<string, mixed>  $extra
     * @return array{addon: string, module: string, action: string}
     */
    public static function resolve(
        ?string $canonicalPromptKey,
        ?string $stage = null,
        ?string $hookKey = null,
        array $extra = [],
    ): array {
        // Explicit overrides if passed
        $explicitAddon = trim((string) ($extra['addon'] ?? ''));
        $explicitModule = trim((string) ($extra['module'] ?? ''));
        $explicitAction = trim((string) ($extra['action'] ?? ''));
        if ($explicitAddon !== '' && $explicitModule !== '') {
            return [
                'addon' => strtolower($explicitAddon),
                'module' => strtolower($explicitModule),
                'action' => $explicitAction !== '' ? strtolower($explicitAction) : self::ACTION_UNKNOWN,
            ];
        }

        $candidates = array_filter([
            $canonicalPromptKey,
            $hookKey,
            $stage,
            $extra['promptTaskType'] ?? null,
            $extra['canonical_prompt_key'] ?? null,
            $extra['hook_key'] ?? null,
            $extra['addon'] ?? null,
            $extra['module'] ?? null,
            $extra['action'] ?? null,
        ], static fn (mixed $v): bool => is_string($v) && trim($v) !== '');

        $combined = strtolower(implode(' ', $candidates));

        if ($combined === '') {
            return [
                'addon' => self::ADDON_UNKNOWN,
                'module' => self::MODULE_UNKNOWN,
                'action' => self::ACTION_UNKNOWN,
            ];
        }

        // Seeding checks
        if (str_contains($combined, 'seeding') || str_contains($combined, 'social.comment') || str_contains($combined, 'comment_generate') || str_contains($combined, 'comment')) {
            return [
                'addon' => self::ADDON_SEEDING,
                'module' => self::MODULE_SEEDING,
                'action' => self::ACTION_GENERATE_COMMENT,
            ];
        }

        // SEO Content Project checks
        if (str_contains($combined, 'outline')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_CONTENT_PROJECT,
                'action' => self::ACTION_OUTLINE,
            ];
        }

        if (str_contains($combined, 'vocabulary') || str_contains($combined, 'vocab')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_CONTENT_PROJECT,
                'action' => self::ACTION_VOCABULARY,
            ];
        }

        if (str_contains($combined, 'metadata') || str_contains($combined, 'meta.generate') || str_contains($combined, 'article.meta') || str_contains($combined, 'meta_tags')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_CONTENT_PROJECT,
                'action' => self::ACTION_METADATA,
            ];
        }

        if (
            str_contains($combined, 'article')
            || str_contains($combined, 'content_project')
        ) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_CONTENT_PROJECT,
                'action' => self::ACTION_ARTICLE,
            ];
        }

        // SEO Topic checks
        if (str_contains($combined, 'clustering') || str_contains($combined, 'cluster')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_TOPIC,
                'action' => self::ACTION_CLUSTERING,
            ];
        }

        if (str_contains($combined, 'keyword')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_TOPIC,
                'action' => self::ACTION_KEYWORD_CLASSIFICATION,
            ];
        }

        // SEO Audit checks
        if (str_contains($combined, 'audit')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_SEO_AUDIT,
                'action' => self::ACTION_AUDIT,
            ];
        }

        // SEO Scoring checks
        if (str_contains($combined, 'scoring') || str_contains($combined, 'score')) {
            return [
                'addon' => self::ADDON_SEO,
                'module' => self::MODULE_SEO_SCORING,
                'action' => self::ACTION_SCORING,
            ];
        }

        return [
            'addon' => self::ADDON_UNKNOWN,
            'module' => self::MODULE_UNKNOWN,
            'action' => self::ACTION_UNKNOWN,
        ];
    }

    /**
     * Extract normalized tokens from raw usage array.
     * Never invents tokens: returns null when usage is absent.
     *
     * @param  array<string, mixed>|null  $usage
     * @return array{input_tokens: ?int, output_tokens: ?int, total_tokens: ?int}
     */
    public static function extractTokens(?array $usage): array
    {
        if (! is_array($usage) || $usage === []) {
            return [
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
            ];
        }

        $inputRaw = $usage['input_tokens']
            ?? $usage['prompt_tokens']
            ?? $usage['promptTokenCount']
            ?? null;

        $outputRaw = $usage['output_tokens']
            ?? $usage['completion_tokens']
            ?? $usage['candidatesTokenCount']
            ?? null;

        $totalRaw = $usage['total_tokens']
            ?? $usage['totalTokenCount']
            ?? null;

        $input = is_numeric($inputRaw) ? max(0, (int) $inputRaw) : null;
        $output = is_numeric($outputRaw) ? max(0, (int) $outputRaw) : null;
        $total = is_numeric($totalRaw) ? max(0, (int) $totalRaw) : null;

        if ($total === null && ($input !== null || $output !== null)) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ];
    }
}
