<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
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
        // History cards intentionally omit `result` body for payload size — load outputs
        // once so classification/apply eligibility is not decided on an empty string.
        $outputByResultId = $this->loadOutputsForGroups($groups);

        $enrichedGroups = [];
        foreach ($groups as $group) {
            $prompts = [];
            $maxAttempt = null;

            foreach ((array) ($group['prompts'] ?? []) as $prompt) {
                if (! is_array($prompt)) {
                    continue;
                }

                $enriched = $this->enrichPrompt(
                    $prompt,
                    (int) ($group['run_id'] ?? 0) ?: null,
                    $tombstones,
                    $applyStats,
                    $outputByResultId,
                );

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
     * AI Calls tab — only rows backed by a real PromptResult.
     *
     * @param  list<int>  $accessibleProjectIds
     * @param  array{type?: string, status?: string, include_deleted?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function listAiCalls(SeoArticle $article, array $accessibleProjectIds, array $filters = []): array
    {
        $groups = $this->list($article, $accessibleProjectIds, $filters);
        $aiGroups = [];

        foreach ($groups as $group) {
            $prompts = array_values(array_filter(
                (array) ($group['prompts'] ?? []),
                static fn (mixed $prompt): bool => is_array($prompt)
                    && (int) ($prompt['result_id'] ?? 0) > 0,
            ));

            if ($prompts === []) {
                continue;
            }

            $group['prompts'] = $prompts;
            $aiGroups[] = $group;
        }

        return $aiGroups;
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
                $found = $this->findPromptByArtifactRef($prompt, $artifactRef);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|mixed  $prompt
     * @return array<string, mixed>|null
     */
    private function findPromptByArtifactRef(mixed $prompt, string $artifactRef): ?array
    {
        if (! is_array($prompt)) {
            return null;
        }
        if ((string) ($prompt['artifact_ref'] ?? '') === $artifactRef) {
            return $prompt;
        }
        foreach ((array) ($prompt['children'] ?? []) as $child) {
            $found = $this->findPromptByArtifactRef($child, $artifactRef);
            if ($found !== null) {
                return $found;
            }
        }
        foreach ((array) ($prompt['attempts'] ?? []) as $attempt) {
            $found = $this->findPromptByArtifactRef($attempt, $artifactRef);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, SeoArticleAiHistoryTombstone>  $tombstones
     * @param  array<string, array{count: int, committed_count: int, pending_count: int, last_applied_at: mixed}>  $applyStats
     * @param  array<int, string>  $outputByResultId
     * @return array<string, mixed>
     */
    private function enrichPrompt(
        array $prompt,
        ?int $groupRunId,
        array $tombstones,
        array $applyStats,
        array $outputByResultId = [],
    ): array {
        $artifactRef = $this->computeArtifactRef($prompt);
        $classifyOutput = $this->resolveClassifyOutput($prompt, $outputByResultId);

        $classification = $this->classifier->classify([
            'hook_key' => $prompt['hook_key'] ?? null,
            'execution_role' => $prompt['execution_role'] ?? null,
            'artifact_type' => $prompt['artifact_type'] ?? null,
            'output' => $classifyOutput,
            'outline_markdown' => $prompt['outline_markdown'] ?? null,
            'status' => $prompt['status'] ?? null,
            'persists_as_outline' => (bool) ($prompt['persists_as_outline'] ?? false),
            'outline_subtask' => $prompt['outline_subtask'] ?? null,
        ], $classifyOutput);

        $tombstone = $tombstones[$artifactRef] ?? null;
        $applyStat = $applyStats[$artifactRef] ?? null;
        $applyCount = (int) ($applyStat['count'] ?? 0);
        $committedCount = (int) ($applyStat['committed_count'] ?? 0);
        $pendingCount = (int) ($applyStat['pending_count'] ?? 0);

        $prompt['artifact_ref'] = $artifactRef;
        $prompt['artifact_type'] = $classification['artifact_type'];
        $prompt['classification'] = $classification['classification'];
        $prompt['classification_reason'] = $classification['reason'];
        $prompt['normalized_artifact'] = $classification['normalized_payload'];
        // Fail-closed: never force can_apply_* when classifier rejects the payload.
        $prompt['can_apply_outline'] = $classification['can_apply']
            && $classification['artifact_type'] === WorkflowArtifactType::ArticleOutline->value;
        $prompt['can_apply_content'] = $classification['can_apply']
            && $classification['artifact_type'] === WorkflowArtifactType::ArticleContent->value;

        $prompt['apply_block_reason'] = $this->applyBlockReason($prompt, $classification);
        $prompt['has_raw_prompt'] = (int) ($prompt['prompt_version_id'] ?? 0) > 0
            || trim((string) ($prompt['compiled_prompt_hash'] ?? '')) !== ''
            || (int) ($prompt['result_id'] ?? 0) > 0
            || ! empty($prompt['exact_prompt_available']);
        $prompt['has_raw_output'] = in_array(
            strtolower(trim((string) ($prompt['status'] ?? ''))),
            ['completed', 'success', 'failed', 'error'],
            true,
        );
        $prompt['has_normalized_artifact'] = trim((string) $classification['normalized_payload']) !== '';
        $prompt['apply_count'] = $applyCount;
        $prompt['apply_committed_count'] = $committedCount;
        $prompt['apply_pending_count'] = $pendingCount;
        $prompt['last_applied_at'] = $applyStat['last_applied_at'] ?? null;
        $prompt['applied_label'] = $this->buildAppliedLabel($applyCount, $committedCount, $pendingCount);
        $prompt['is_deleted'] = $tombstone instanceof SeoArticleAiHistoryTombstone;
        $prompt['deleted_at'] = $tombstone?->deleted_at;
        $prompt['run_id'] = $prompt['run_id'] ?? $groupRunId;
        $prompt['run_item_id'] = $prompt['run_item_id'] ?? null;
        $prompt['attempt'] = $prompt['attempt'] ?? null;

        $children = is_array($prompt['children'] ?? null) ? $prompt['children'] : [];
        if ($children !== []) {
            $prompt['children'] = array_map(
                fn (mixed $child): array => is_array($child)
                    ? $this->enrichPrompt($child, $groupRunId, $tombstones, $applyStats, $outputByResultId)
                    : [],
                $children,
            );
            $prompt['children'] = array_values(array_filter(
                $prompt['children'],
                static fn (array $child): bool => $child !== [],
            ));
            $prompt['child_count'] = count($prompt['children']);
        }

        $attempts = is_array($prompt['attempts'] ?? null) ? $prompt['attempts'] : [];
        if ($attempts !== []) {
            $prompt['attempts'] = array_map(
                fn (mixed $attempt): array => is_array($attempt)
                    ? $this->enrichPrompt($attempt, $groupRunId, $tombstones, $applyStats, $outputByResultId)
                    : [],
                $attempts,
            );
            $prompt['attempts'] = array_values(array_filter(
                $prompt['attempts'],
                static fn (array $attempt): bool => $attempt !== [],
            ));
        }

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
     * @return array<string, array{count: int, committed_count: int, pending_count: int, last_applied_at: mixed}>
     */
    private function applyStatsByRef(int $articleId): array
    {
        $rows = SeoArticleAiHistoryApply::query()
            ->where('article_id', $articleId)
            ->selectRaw(
                'artifact_ref, COUNT(*) as apply_count, '
                .'SUM(CASE WHEN committed = 1 THEN 1 ELSE 0 END) as committed_count, '
                .'SUM(CASE WHEN committed = 0 THEN 1 ELSE 0 END) as pending_count, '
                .'MAX(applied_at) as last_applied_at'
            )
            ->groupBy('artifact_ref')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->artifact_ref] = [
                'count' => (int) $row->apply_count,
                'committed_count' => (int) $row->committed_count,
                'pending_count' => (int) $row->pending_count,
                'last_applied_at' => $row->last_applied_at,
            ];
        }

        return $stats;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<int, string>
     */
    private function loadOutputsForGroups(array $groups): array
    {
        $ids = [];
        $collect = function (mixed $node) use (&$collect, &$ids): void {
            if (! is_array($node)) {
                return;
            }
            $resultId = (int) ($node['result_id'] ?? 0);
            if ($resultId > 0 && trim((string) ($node['result'] ?? '')) === '') {
                $ids[$resultId] = true;
            }
            foreach (['children', 'attempts', 'prompts'] as $key) {
                foreach ((array) ($node[$key] ?? []) as $child) {
                    $collect($child);
                }
            }
        };
        foreach ($groups as $group) {
            $collect($group);
            foreach ((array) ($group['prompts'] ?? []) as $prompt) {
                $collect($prompt);
            }
        }

        if ($ids === []) {
            return [];
        }

        return PromptResult::query()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'output_text'])
            ->mapWithKeys(static fn (PromptResult $row): array => [
                (int) $row->id => trim((string) ($row->output_text ?? '')),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<int, string>  $outputByResultId
     */
    private function resolveClassifyOutput(array $prompt, array $outputByResultId): string
    {
        $inline = trim((string) ($prompt['result'] ?? ''));
        if ($inline !== '') {
            return $inline;
        }

        $resultId = (int) ($prompt['result_id'] ?? 0);
        if ($resultId > 0 && isset($outputByResultId[$resultId])) {
            return trim((string) $outputByResultId[$resultId]);
        }

        return '';
    }

    private function buildAppliedLabel(int $applyCount, int $committedCount, int $pendingCount): ?string
    {
        if ($applyCount <= 0) {
            return null;
        }

        if ($committedCount > 0 && $pendingCount > 0) {
            return sprintf(
                'Đã lưu vào bài %d lần · còn bản nháp chưa lưu',
                $committedCount,
            );
        }

        if ($committedCount > 0) {
            return sprintf('Đã lưu vào bài %d lần', $committedCount);
        }

        // Pending-only apply rows mean editor draft — not proof body was persisted.
        return sprintf('Đã đưa vào bản nháp editor %d lần (chưa lưu body)', $pendingCount > 0 ? $pendingCount : $applyCount);
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
        $payload = trim((string) ($classification['normalized_payload'] ?? ''));

        return match (true) {
            str_contains($reason, 'status_not_succeeded') => 'Bước không thành công (skipped/failed) — không thể áp dụng.',
            str_contains($reason, 'outline_marker') => 'Output có marker dàn ý / không phải article_content hợp lệ — không ghi vào body.',
            str_contains($reason, 'content_payload_invalid') && $payload === '' => 'Output bài viết trống trong lịch sử — không thể áp dụng.',
            str_contains($reason, 'content_payload_invalid') => 'Output không phải article_content hợp lệ — không ghi vào body.',
            str_contains($reason, 'outline_payload_invalid'),
            str_contains($reason, 'outline_payload_not_usable') => 'Dàn ý không parse được — không thể áp dụng.',
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
