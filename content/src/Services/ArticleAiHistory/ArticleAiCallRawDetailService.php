<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;

/**
 * Resolve raw compiled prompt + output_text for a single AI call (PromptResult).
 * Separate from normalized artifact preview used by Apply.
 */
final class ArticleAiCallRawDetailService
{
    public function __construct(
        private readonly ArticleAiHistoryListService $listService,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
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

        if (! $this->isOwnedPromptResult($article, $promptResultId, $accessibleProjectIds)) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy AI call này trong lịch sử bài viết.',
            ];
        }

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if (is_array($artifact)) {
            return $this->buildPayload($artifactRef, $promptResultId, $artifact);
        }

        $result = PromptResult::query()->with('prompt')->find($promptResultId);
        if (! $result instanceof PromptResult) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy PromptResult cho AI call này.',
            ];
        }

        return $this->buildPayload($artifactRef, $promptResultId, [
            'prompt' => self::resolveRawPromptText($result),
            'result' => self::resolveRawOutputText($result),
            'prompt_name' => trim((string) ($result->prompt?->name ?? '')),
            'hook_key' => self::extractHookKey($result),
            'model' => self::extractModel($result),
            'provider' => trim((string) (is_array($result->input_snapshot) ? ($result->input_snapshot['provider'] ?? '') : '')),
            'status' => (string) $result->status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
     */
    private function buildPayload(string $artifactRef, int $promptResultId, array $artifact): array
    {
        $prompt = trim((string) ($artifact['prompt'] ?? ''));
        $output = trim((string) ($artifact['result'] ?? ''));

        if ($prompt === '') {
            $prompt = 'Không còn dữ liệu prompt.';
        }

        if ($output === '') {
            $output = 'Không có raw output được lưu cho AI call này.';
        }

        $titleParts = array_values(array_filter([
            trim((string) ($artifact['prompt_name'] ?? $artifact['type'] ?? 'AI Call')),
            trim((string) ($artifact['hook_key'] ?? '')),
        ]));

        $metaParts = array_values(array_filter([
            trim((string) ($artifact['model'] ?? $artifact['render_model'] ?? '')),
            trim((string) ($artifact['provider'] ?? '')),
            trim((string) ($artifact['status'] ?? '')),
            'PromptResult #'.$promptResultId,
        ]));

        return [
            'success' => true,
            'title' => implode(' · ', $titleParts),
            'prompt' => $prompt,
            'output' => $output,
            'meta' => implode(' · ', $metaParts),
            'prompt_result_id' => $promptResultId,
            'artifact_ref' => $artifactRef,
        ];
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function isOwnedPromptResult(SeoArticle $article, int $promptResultId, array $accessibleProjectIds): bool
    {
        if ($accessibleProjectIds === []) {
            return false;
        }

        $accessibleRunIds = SeoProjectRun::query()
            ->whereIn('project_id', $accessibleProjectIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($accessibleRunIds === []) {
            return false;
        }

        return SeoPromptResultLink::query()
            ->where('article_id', (int) $article->getKey())
            ->where('prompt_result_id', $promptResultId)
            ->whereIn('project_run_id', $accessibleRunIds)
            ->exists();
    }

    public static function resolveRawPromptText(PromptResult $result, ?array $step = null): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $compiledPrompt = trim((string) ($snapshot['compiled_prompt'] ?? ''));
        $promptTemplate = '';
        if ($result->relationLoaded('prompt')) {
            $prompt = $result->getRelation('prompt');
            $promptTemplate = $prompt instanceof \Omnichannel\Addons\AiPrompt\Models\SeoPrompt
                ? trim((string) ($prompt->markdown_content ?? ''))
                : '';
        }
        $fallbackInput = trim((string) ($step['input_used'] ?? ''));

        if ($compiledPrompt !== '') {
            return $compiledPrompt;
        }

        if ($promptTemplate !== '') {
            return $promptTemplate;
        }

        return $fallbackInput;
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
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        return trim((string) ($snapshot['hook_key'] ?? $snapshot['variables']['hook_key'] ?? ''));
    }

    private static function extractModel(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        return trim((string) ($snapshot['raw_model_used'] ?? $snapshot['render_model'] ?? ''));
    }
}
