<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptResultOwnershipResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptReconstructor;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Resolve reconstructed prompt + output_text for a single AI call (PromptResult).
 * List never preloads these blobs.
 */
final class ArticleAiCallRawDetailService
{
    public const HASH_MISMATCH_WARNING = 'Reconstructed prompt differs from execution hash';

    public function __construct(
        private readonly PromptReconstructor $reconstructor,
        private readonly ArticlePromptResultOwnershipResolver $ownership,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string, hash_mismatch?: bool}
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

        $reconstructed = $this->reconstructor->reconstruct($result);
        $promptText = trim((string) ($reconstructed['prompt'] ?? ''));
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

        $version = $reconstructed['version_label'] ?? null;
        $hashMismatch = (bool) ($reconstructed['mismatch'] ?? false);
        $titleParts = array_values(array_filter([
            trim((string) ($result->prompt?->name ?? 'AI Call')),
            trim((string) ($result->canonical_prompt_key ?? self::extractHookKey($result))),
            is_string($version) && $version !== '' ? 'v'.$version : null,
        ]));

        $metaParts = array_values(array_filter([
            self::extractModel($result),
            trim((string) $result->status),
            'PromptResult #'.$promptResultId,
            $hashMismatch ? self::HASH_MISMATCH_WARNING : null,
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
        ];
    }

    public static function resolveRawPromptText(PromptResult $result, ?array $step = null): string
    {
        try {
            $reconstructed = app(PromptReconstructor::class)->reconstruct($result);
            $compiled = trim((string) ($reconstructed['prompt'] ?? ''));
            if ($compiled !== '') {
                return $compiled;
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
