<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptResultOwnershipResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptReconstructor;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Resolve exact execution prompt (preferred) or reconstructed legacy prompt + output_text.
 * List never preloads these blobs.
 */
final class ArticleAiCallRawDetailService
{
    public const HASH_MISMATCH_WARNING = 'Reconstructed prompt differs from execution hash';

    public const LEGACY_RECONSTRUCTED_WARNING = 'Legacy reconstructed prompt — not exact execution prompt';

    public const PROMPT_SOURCE_EXECUTION_SNAPSHOT = 'execution_snapshot';

    public const PROMPT_SOURCE_RECONSTRUCTED_LEGACY = 'reconstructed_legacy';

    public function __construct(
        private readonly PromptReconstructor $reconstructor,
        private readonly ArticlePromptResultOwnershipResolver $ownership,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return array{
     *   success: bool,
     *   title?: string,
     *   prompt?: string,
     *   output?: string,
     *   meta?: string,
     *   message?: string,
     *   prompt_result_id?: int,
     *   artifact_ref?: string,
     *   hash_mismatch?: bool,
     *   prompt_source?: string,
     *   exact_execution_prompt?: bool
     * }
     */
    public function resolve(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): array
    {
        $artifactRef = trim($artifactRef);
        $parsed = ArticleAiHistoryArtifactRef::parse($artifactRef);
        if ($parsed === null || ($parsed['kind'] ?? '') !== ArticleAiHistoryArtifactRef::KIND_PROMPT_RESULT) {
            return [
                'success' => false,
                'message' => 'AI call reference is invalid.',
            ];
        }

        $promptResultId = (int) ($parsed['prompt_result_id'] ?? 0);
        if ($promptResultId <= 0) {
            return [
                'success' => false,
                'message' => 'AI call reference is invalid.',
            ];
        }

        if (! $this->ownership->isOwned((int) $article->getKey(), $promptResultId, $accessibleProjectIds)) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy AI call này trong lịch sử bài viết.',
            ];
        }

        $result = PromptResult::query()
            ->with(['prompt', 'promptVersion'])
            ->find($promptResultId);
        if (! $result instanceof PromptResult) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy PromptResult cho AI call này.',
            ];
        }

        $resolved = self::resolvePromptAuthority($result, $this->reconstructor);
        $promptText = $resolved['prompt'];
        $output = self::resolveRawOutputText($result);
        $error = \Omnichannel\Addons\Content\Support\PromptAiCallErrorNormalizer::display($result->error_message);
        if ($output === '' && $error !== null) {
            $output = $error;
        }

        if ($promptText === '') {
            $promptText = 'Không còn dữ liệu prompt.';
        }
        if ($output === '') {
            $output = 'Không có raw output được lưu cho AI call này.';
        }

        $version = $resolved['version_label'];
        $hashMismatch = $resolved['hash_mismatch'];
        $titleParts = array_values(array_filter([
            trim((string) ($result->prompt?->name ?? 'AI Call')),
            trim((string) ($result->canonical_prompt_key ?? self::extractHookKey($result))),
            is_string($version) && $version !== '' ? 'v'.$version : null,
        ]));

        $metaParts = array_values(array_filter([
            self::extractModel($result),
            trim((string) $result->status),
            'PromptResult #'.$promptResultId,
            $resolved['warning'],
            $error,
        ]));

        return [
            'success' => true,
            'title' => implode(' · ', $titleParts),
            'prompt' => $promptText,
            'output' => $output,
            'meta' => implode(' · ', $metaParts),
            'prompt_result_id' => $promptResultId,
            'artifact_ref' => $artifactRef,
            'hash_mismatch' => $hashMismatch,
            'prompt_source' => $resolved['prompt_source'],
            'exact_execution_prompt' => $resolved['exact_execution_prompt'],
        ];
    }

    /**
     * Exact input_snapshot.compiled_prompt wins over PromptReconstructor.
     *
     * @return array{
     *   prompt: string,
     *   prompt_source: string,
     *   exact_execution_prompt: bool,
     *   hash_mismatch: bool,
     *   warning: ?string,
     *   version_label: ?string
     * }
     */
    public static function resolvePromptAuthority(PromptResult $result, ?PromptReconstructor $reconstructor = null): array
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $exact = trim((string) ($snapshot['compiled_prompt'] ?? ''));
        $storedHash = trim((string) ($result->compiled_prompt_hash ?? $snapshot['compiled_prompt_hash'] ?? ''));

        if ($exact !== '') {
            $exactHash = hash('sha256', $exact);
            $hashMismatch = $storedHash !== '' && ! hash_equals($storedHash, $exactHash);
            $versionLabel = null;
            if ($result->relationLoaded('promptVersion') && $result->promptVersion !== null) {
                $versionLabel = (string) ($result->promptVersion->version_label ?? '');
                $versionLabel = $versionLabel !== '' ? $versionLabel : null;
            }

            return [
                'prompt' => $exact,
                'prompt_source' => self::PROMPT_SOURCE_EXECUTION_SNAPSHOT,
                'exact_execution_prompt' => true,
                'hash_mismatch' => $hashMismatch,
                'warning' => $hashMismatch ? self::HASH_MISMATCH_WARNING : null,
                'version_label' => $versionLabel,
            ];
        }

        $reconstructor ??= app(PromptReconstructor::class);
        $reconstructed = $reconstructor->reconstruct($result);
        $promptText = trim((string) ($reconstructed['prompt'] ?? ''));
        $legacyMismatch = (bool) ($reconstructed['mismatch'] ?? false);

        return [
            'prompt' => $promptText,
            'prompt_source' => self::PROMPT_SOURCE_RECONSTRUCTED_LEGACY,
            'exact_execution_prompt' => false,
            'hash_mismatch' => $legacyMismatch,
            'warning' => $legacyMismatch ? self::LEGACY_RECONSTRUCTED_WARNING : null,
            'version_label' => isset($reconstructed['version_label']) && is_string($reconstructed['version_label'])
                ? $reconstructed['version_label']
                : null,
        ];
    }

    public static function resolveRawPromptText(PromptResult $result, ?array $step = null): string
    {
        try {
            $resolved = self::resolvePromptAuthority($result);
            if ($resolved['prompt'] !== '') {
                return $resolved['prompt'];
            }
        } catch (\Throwable) {
            // fall through
        }

        return trim((string) ($step['input_used'] ?? ''));
    }

    public static function resolveRawOutputText(PromptResult $result, ?array $step = null): string
    {
        $output = trim((string) ($result->output_text ?? ''));
        if ($output !== '') {
            return $output;
        }

        return trim((string) ($step['output'] ?? ''));
    }

    private static function extractHookKey(PromptResult $result): string
    {
        if (trim((string) ($result->canonical_prompt_key ?? '')) !== '') {
            return trim((string) $result->canonical_prompt_key);
        }
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        return trim((string) ($snapshot['hook_key'] ?? $snapshot['variables']['hook_key'] ?? ''));
    }

    private static function extractModel(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $usage = is_array($result->token_usage) ? $result->token_usage : [];
        $attribution = \Omnichannel\Addons\AiPrompt\Support\AiExecutionModelAttribution::fromPersistence(
            $snapshot,
            $usage,
        );

        return $attribution->displayModel();
    }
}
