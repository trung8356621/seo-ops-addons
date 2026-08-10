<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleAiHistoryApply;
use Omnichannel\Addons\Content\Models\SeoArticleAiHistoryTombstone;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;

/**
 * Bọc {@see ArticlePromptRunHistoryService::build()} và enrich mỗi prompt item với
 * artifact_ref/classification/apply/tombstone metadata cho Article AI History.
 *
 * Không tin dữ liệu client gửi lên: {@see resolveOwnedArtifact()} luôn re-resolve
 * type/ownership từ danh sách này trước khi cho apply/delete.
 */
final class ArticleAiHistoryListService
{
    public function __construct(
        private readonly ArticlePromptRunHistoryService $historyService,
        private readonly ArticleAiHistoryLegacyClassifier $classifier,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @param  array{type?: string, status?: string, include_deleted?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(SeoArticle $article, array $accessibleProjectIds, array $filters = []): array
    {
        $articleId = (int) $article->getKey();
        $groups = $this->historyService->build($article, $accessibleProjectIds);

        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? 'all')));
        $includeDeleted = (bool) ($filters['include_deleted'] ?? false) || $statusFilter === 'deleted';

        $tombstones = $this->tombstonesByRef($articleId);
        $applyStats = $this->applyStatsByRef($articleId);

        $enrichedGroups = [];
        foreach ($groups as $group) {
            $prompts = [];
            $maxAttempt = null;

            foreach ((array) ($group['prompts'] ?? []) as $prompt) {
                if (! is_array($prompt)) {
                    continue;
                }

                $enriched = $this->enrichPrompt($prompt, (int) ($group['run_id'] ?? 0) ?: null, $tombstones, $applyStats);

                if (! $includeDeleted && $enriched['is_deleted']) {
                    continue;
                }
                if (! $this->passesTypeFilter($enriched, $typeFilter)) {
                    continue;
                }
                if (! $this->passesStatusFilter($enriched, $statusFilter)) {
                    continue;
                }

                if ($enriched['attempt'] !== null) {
                    $maxAttempt = $maxAttempt === null
                        ? $enriched['attempt']
                        : max($maxAttempt, $enriched['attempt']);
                }

                $prompts[] = $enriched;
            }

            if ($prompts === []) {
                continue;
            }

            $group['prompts'] = $prompts;
            $group['max_attempt'] = $maxAttempt;
            $enrichedGroups[] = $group;
        }

        return $enrichedGroups;
    }

    /**
     * Re-resolve artifact bằng chính list() — never trust client-provided type/ownership.
     *
     * @param  list<int>  $accessibleProjectIds
     * @return array<string, mixed>|null
     */
    public function resolveOwnedArtifact(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): ?array
    {
        $artifactRef = trim($artifactRef);
        if ($artifactRef === '') {
            return null;
        }

        $groups = $this->list($article, $accessibleProjectIds, ['include_deleted' => true]);
        foreach ($groups as $group) {
            foreach ((array) ($group['prompts'] ?? []) as $prompt) {
                if (is_array($prompt) && (string) ($prompt['artifact_ref'] ?? '') === $artifactRef) {
                    return $prompt;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, SeoArticleAiHistoryTombstone>  $tombstones
     * @param  array<string, array{count: int, last_applied_at: mixed}>  $applyStats
     * @return array<string, mixed>
     */
    private function enrichPrompt(array $prompt, ?int $groupRunId, array $tombstones, array $applyStats): array
    {
        $artifactRef = $this->computeArtifactRef($prompt);

        $classification = $this->classifier->classify([
            'hook_key' => $prompt['hook_key'] ?? null,
            'execution_role' => $prompt['execution_role'] ?? null,
            'artifact_type' => $prompt['artifact_type'] ?? null,
            'output' => (string) ($prompt['result'] ?? ''),
            'outline_markdown' => $prompt['outline_markdown'] ?? null,
            'status' => $prompt['status'] ?? null,
            'persists_as_outline' => (bool) ($prompt['persists_as_outline'] ?? false),
        ], (string) ($prompt['result'] ?? ''));

        $tombstone = $tombstones[$artifactRef] ?? null;
        $applyStat = $applyStats[$artifactRef] ?? null;
        $applyCount = (int) ($applyStat['count'] ?? 0);

        $prompt['artifact_ref'] = $artifactRef;
        $prompt['artifact_type'] = $classification['artifact_type'];
        $prompt['classification'] = $classification['classification'];
        $prompt['classification_reason'] = $classification['reason'];
        $prompt['normalized_artifact'] = $classification['normalized_payload'];
        $prompt['can_apply_outline'] = $classification['can_apply']
            && $classification['artifact_type'] === WorkflowArtifactType::ArticleOutline->value;
        $prompt['can_apply_content'] = $classification['can_apply']
            && $classification['artifact_type'] === WorkflowArtifactType::ArticleContent->value;
        $prompt['apply_block_reason'] = $this->applyBlockReason($prompt, $classification);
        $prompt['has_raw_prompt'] = trim((string) ($prompt['prompt'] ?? '')) !== '';
        $prompt['has_raw_output'] = trim((string) ($prompt['result'] ?? '')) !== '';
        $prompt['has_normalized_artifact'] = trim((string) $classification['normalized_payload']) !== '';
        $prompt['apply_count'] = $applyCount;
        $prompt['last_applied_at'] = $applyStat['last_applied_at'] ?? null;
        $prompt['applied_label'] = $applyCount > 0
            ? sprintf('Đã áp dụng %d lần', $applyCount)
            : null;
        $prompt['is_deleted'] = $tombstone instanceof SeoArticleAiHistoryTombstone;
        $prompt['deleted_at'] = $tombstone?->deleted_at;
        $prompt['run_id'] = $prompt['run_id'] ?? $groupRunId;
        $prompt['run_item_id'] = $prompt['run_item_id'] ?? null;
        $prompt['attempt'] = $prompt['attempt'] ?? null;

        return $prompt;
    }

    /**
     * @param  array<string, mixed>  $prompt
     */
    private function computeArtifactRef(array $prompt): string
    {
        $resultId = (int) ($prompt['result_id'] ?? 0);
        if ($resultId > 0) {
            return ArticleAiHistoryArtifactRef::encodePromptResult($resultId);
        }

        $runItemId = (int) ($prompt['run_item_id'] ?? 0);
        if ($runItemId > 0) {
            $stepIndex = (int) ($prompt['step_index'] ?? 0);

            return ArticleAiHistoryArtifactRef::encodeRunItemStep($runItemId, $stepIndex);
        }

        return (string) ($prompt['key'] ?? '');
    }

    /**
     * @return array<string, SeoArticleAiHistoryTombstone>
     */
    private function tombstonesByRef(int $articleId): array
    {
        return SeoArticleAiHistoryTombstone::query()
            ->where('article_id', $articleId)
            ->get()
            ->keyBy(fn (SeoArticleAiHistoryTombstone $row): string => (string) $row->artifact_ref)
            ->all();
    }

    /**
     * @return array<string, array{count: int, last_applied_at: mixed}>
     */
    private function applyStatsByRef(int $articleId): array
    {
        $rows = SeoArticleAiHistoryApply::query()
            ->where('article_id', $articleId)
            ->selectRaw('artifact_ref, COUNT(*) as apply_count, MAX(applied_at) as last_applied_at')
            ->groupBy('artifact_ref')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->artifact_ref] = [
                'count' => (int) $row->apply_count,
                'last_applied_at' => $row->last_applied_at,
            ];
        }

        return $stats;
    }

    /**
     * Human-readable why Apply is hidden — for content/outline-looking steps only.
     *
     * @param  array<string, mixed>  $prompt
     * @param  array{artifact_type: ?string, classification: string, can_apply: bool, reason: string, normalized_payload: string}  $classification
     */
    private function applyBlockReason(array $prompt, array $classification): ?string
    {
        if ($classification['can_apply']) {
            return null;
        }

        $hook = strtolower(trim((string) ($prompt['hook_key'] ?? $prompt['execution_role'] ?? '')));
        $isContentHook = str_contains($hook, 'article.content');
        $isOutlineHook = str_contains($hook, 'article.outline')
            || (bool) ($prompt['persists_as_outline'] ?? false);

        if (! $isContentHook && ! $isOutlineHook) {
            return null;
        }

        $reason = (string) ($classification['reason'] ?? '');

        return match (true) {
            str_contains($reason, 'status_not_succeeded') => 'Bước không thành công (skipped/failed) — không thể áp dụng.',
            str_contains($reason, 'content_payload_invalid'),
            str_contains($reason, 'outline_marker') => 'Output có marker dàn ý / không phải article_content hợp lệ — không ghi vào body.',
            str_contains($reason, 'outline_payload_invalid') => 'Dàn ý không parse được — không thể áp dụng.',
            default => $isContentHook
                ? 'Chưa classify được article_content hợp lệ — chỉ xem/xóa.'
                : 'Chưa classify được article_outline hợp lệ — chỉ xem/xóa.',
        };
    }

    /**
     * @param  array<string, mixed>  $prompt
     */
    private function passesTypeFilter(array $prompt, string $filter): bool
    {
        if ($filter === '' || $filter === 'all') {
            return true;
        }

        $isUnknown = $prompt['classification'] === 'unknown';
        $reason = (string) ($prompt['classification_reason'] ?? '');

        return match ($filter) {
            'outline' => $prompt['artifact_type'] === WorkflowArtifactType::ArticleOutline->value,
            'content' => $prompt['artifact_type'] === WorkflowArtifactType::ArticleContent->value,
            'invalid' => $isUnknown && str_contains($reason, 'payload'),
            'other' => $isUnknown && ! str_contains($reason, 'payload'),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $prompt
     */
    private function passesStatusFilter(array $prompt, string $filter): bool
    {
        if ($filter === '' || $filter === 'all') {
            return true;
        }

        $status = (string) ($prompt['status'] ?? '');

        return match ($filter) {
            'success' => in_array($status, ['success', 'completed'], true),
            'error' => in_array($status, ['failed', 'error'], true),
            'skipped' => $status === 'skipped',
            'applied' => (int) ($prompt['apply_count'] ?? 0) > 0,
            'unapplied' => (int) ($prompt['apply_count'] ?? 0) === 0,
            'deleted' => (bool) ($prompt['is_deleted'] ?? false),
            default => true,
        };
    }
}
