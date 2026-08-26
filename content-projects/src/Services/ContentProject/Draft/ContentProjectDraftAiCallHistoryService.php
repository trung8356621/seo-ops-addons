<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiCallRawDetailService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryArtifactRef;
use Omnichannel\Addons\Content\Support\PromptAiCallErrorNormalizer;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Support\Collection;

/**
 * Draft-scoped AI Calls read projection.
 * SSOT remains PromptResult; planner runs only supply linkage.
 */
final class ContentProjectDraftAiCallHistoryService
{
    public const DEFAULT_PAGE_SIZE = 20;

    /** @var list<string> */
    public const AI_PLANNER_SOURCES = [
        SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
    ];

    /**
     * Distinct PromptResult IDs linked to this Draft via planner runs.
     */
    public function count(SeoProject $project): int
    {
        return $this->linkedPromptResultIds($project)->count();
    }

    /**
     * @param  array{type?: string, status?: string, page?: int, per_page?: int}  $filters
     * @return array{
     *   groups: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   has_more: bool
     * }
     */
    public function list(SeoProject $project, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE)));
        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? 'all')));

        $rows = $this->loadLinkedRows($project);
        $items = [];
        foreach ($rows as $row) {
            $item = $this->mapRow($row);
            if ($item === null) {
                continue;
            }
            if (! $this->passesTypeFilter($item, $typeFilter)) {
                continue;
            }
            if (! $this->passesStatusFilter($item, $statusFilter)) {
                continue;
            }
            $items[] = $item;
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        $groups = [];
        if ($pageItems !== []) {
            $groups[] = [
                'run_id' => null,
                'project_name' => 'Planning Draft',
                'ran_at' => $pageItems[0]['ran_at'] ?? null,
                'status' => '',
                'max_attempt' => null,
                'prompts' => $pageItems,
            ];
        }

        return [
            'groups' => $groups,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($offset + $perPage) < $total,
        ];
    }

    /**
     * @return array{success: bool, title?: string, prompt?: string, output?: string, meta?: string, message?: string, prompt_result_id?: int, artifact_ref?: string}
     */
    public function rawDetail(SeoProject $project, string $artifactRef): array
    {
        $parsed = ArticleAiHistoryArtifactRef::parse($artifactRef);
        if ($parsed === null || ($parsed['kind'] ?? '') !== ArticleAiHistoryArtifactRef::KIND_PROMPT_RESULT) {
            return [
                'success' => false,
                'message' => 'AI call reference is invalid.',
            ];
        }

        $promptResultId = (int) ($parsed['prompt_result_id'] ?? 0);
        if ($promptResultId <= 0 || ! $this->ownsPromptResult($project, $promptResultId)) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy AI call này trong Draft.',
            ];
        }

        $result = PromptResult::query()->with('prompt')->find($promptResultId);
        if (! $result instanceof PromptResult) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy PromptResult cho AI call này.',
            ];
        }

        $plannerRunId = $this->plannerRunIdForPromptResult($project, $promptResultId);
        $prompt = ArticleAiCallRawDetailService::resolveRawPromptText($result);
        $output = ArticleAiCallRawDetailService::resolveRawOutputText($result);
        $error = PromptAiCallErrorNormalizer::display($result->error_message);
        if ($error !== null && trim($output) === '') {
            $output = $error;
        }

        $hookKey = $this->extractHookKey($result);
        $promptName = trim((string) ($result->prompt?->name ?? ''));
        $model = $this->extractModel($result);
        $provider = $this->extractProvider($result);
        $profile = $this->extractProfile($result);

        $titleParts = array_values(array_filter([
            $promptName !== '' ? $promptName : 'AI Call',
            $hookKey,
        ]));

        $metaParts = array_values(array_filter([
            $profile !== '' ? 'Profile: '.$profile : null,
            $provider !== '' ? $provider : null,
            $model !== '' ? $model : null,
            (string) $result->status,
            'PromptResult #'.$promptResultId,
            $plannerRunId !== null ? 'Run #'.$plannerRunId : null,
            $error,
        ]));

        return [
            'success' => true,
            'title' => implode(' · ', $titleParts),
            'prompt' => $prompt !== '' ? $prompt : 'Không còn dữ liệu prompt.',
            'output' => $output !== '' ? $output : 'Không có raw output được lưu cho AI call này.',
            'meta' => implode(' · ', $metaParts),
            'prompt_result_id' => $promptResultId,
            'artifact_ref' => ArticleAiHistoryArtifactRef::encodePromptResult($promptResultId),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function linkedPromptResultIds(SeoProject $project): Collection
    {
        return SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('source_type', self::AI_PLANNER_SOURCES)
            ->whereNotNull('prompt_result_id')
            ->where('prompt_result_id', '>', 0)
            ->orderByDesc('id')
            ->pluck('prompt_result_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * Newest planner linkage first; one row per PromptResult.
     *
     * @return list<array{planner_run: SeoContentProjectPlannerRun, result: PromptResult}>
     */
    private function loadLinkedRows(SeoProject $project): array
    {
        $runs = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('source_type', self::AI_PLANNER_SOURCES)
            ->whereNotNull('prompt_result_id')
            ->where('prompt_result_id', '>', 0)
            ->orderByDesc('id')
            ->get();

        $ids = [];
        $runByResult = [];
        foreach ($runs as $run) {
            if (! $run instanceof SeoContentProjectPlannerRun) {
                continue;
            }
            $id = (int) ($run->prompt_result_id ?? 0);
            if ($id <= 0 || isset($runByResult[$id])) {
                continue;
            }
            $runByResult[$id] = $run;
            $ids[] = $id;
        }

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, PromptResult> $results */
        $results = PromptResult::query()
            ->with('prompt')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (PromptResult $r): int => (int) $r->getKey());

        $rows = [];
        foreach ($ids as $id) {
            $result = $results->get($id);
            $run = $runByResult[$id] ?? null;
            if (! $result instanceof PromptResult || ! $run instanceof SeoContentProjectPlannerRun) {
                continue;
            }
            $rows[] = [
                'planner_run' => $run,
                'result' => $result,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{planner_run: SeoContentProjectPlannerRun, result: PromptResult}  $row
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row): ?array
    {
        $result = $row['result'];
        $run = $row['planner_run'];
        $promptResultId = (int) $result->getKey();
        if ($promptResultId <= 0) {
            return null;
        }

        $status = strtolower(trim((string) $result->status));
        $error = PromptAiCallErrorNormalizer::display($result->error_message);
        $hookKey = $this->extractHookKey($result);
        $promptName = trim((string) ($result->prompt?->name ?? ''));
        $typeLabel = $this->typeLabelForHook($hookKey, $promptName);
        $ranAt = $result->finished_at ?? $result->started_at ?? $run->created_at;
        $model = $this->extractModel($result);
        $provider = $this->extractProvider($result);
        $profile = $this->extractProfile($result);

        return [
            'artifact_ref' => ArticleAiHistoryArtifactRef::encodePromptResult($promptResultId),
            'result_id' => $promptResultId,
            'prompt_result_id' => $promptResultId,
            'planner_run_id' => (int) $run->getKey(),
            'type' => $typeLabel,
            'prompt_name' => $promptName !== '' ? $promptName : $typeLabel,
            'hook_key' => $hookKey,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'model' => $model,
            'provider' => $provider,
            'execution_profile' => $profile,
            'execution_type_label' => $profile,
            'ran_at' => $ranAt,
            'prompt' => ArticleAiCallRawDetailService::resolveRawPromptText($result),
            'result' => ArticleAiCallRawDetailService::resolveRawOutputText($result),
            'message' => $error,
            'has_raw_prompt' => true,
            'has_raw_output' => trim((string) ($result->output_text ?? '')) !== '',
            'has_normalized_artifact' => false,
            'normalized_artifact' => '',
            'can_apply_outline' => false,
            'can_apply_content' => false,
            'is_deleted' => false,
            'apply_count' => 0,
            'applied_label' => '',
            'classification' => 'prompt_result',
            'artifact_type' => '',
            'attempt' => null,
        ];
    }

    private function ownsPromptResult(SeoProject $project, int $promptResultId): bool
    {
        return SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('source_type', self::AI_PLANNER_SOURCES)
            ->where('prompt_result_id', $promptResultId)
            ->exists();
    }

    private function plannerRunIdForPromptResult(SeoProject $project, int $promptResultId): ?int
    {
        $id = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('source_type', self::AI_PLANNER_SOURCES)
            ->where('prompt_result_id', $promptResultId)
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function passesTypeFilter(array $item, string $typeFilter): bool
    {
        if ($typeFilter === '' || $typeFilter === 'all') {
            return true;
        }

        $hook = strtolower((string) ($item['hook_key'] ?? ''));
        if ($typeFilter === 'keyword_discovery' || $typeFilter === 'keyword.discovery.structured') {
            return str_contains($hook, 'keyword.discovery');
        }

        return $hook === $typeFilter || str_contains($hook, $typeFilter);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function passesStatusFilter(array $item, string $statusFilter): bool
    {
        if ($statusFilter === '' || $statusFilter === 'all') {
            return true;
        }

        $status = strtolower((string) ($item['status'] ?? ''));

        return match ($statusFilter) {
            'success', 'completed' => in_array($status, ['success', 'completed', 'ok'], true),
            'error', 'failed' => in_array($status, ['error', 'failed', 'failure'], true),
            'running', 'pending' => in_array($status, ['running', 'pending', 'queued'], true),
            default => $status === $statusFilter,
        };
    }

    private function extractHookKey(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $hook = trim((string) ($snapshot['hook_key'] ?? $snapshot['variables']['hook_key'] ?? ''));
        if ($hook !== '') {
            return $hook;
        }

        // Content Planning Assistant is bound to keyword.discovery.structured.
        $promptName = strtolower(trim((string) ($result->prompt?->name ?? '')));
        if (str_contains($promptName, 'content planning') || str_contains($promptName, 'keyword discovery')) {
            return 'keyword.discovery.structured';
        }

        return '';
    }

    private function extractModel(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        return trim((string) ($snapshot['raw_model_used'] ?? $snapshot['render_model'] ?? $snapshot['planner_model'] ?? ''));
    }

    private function extractProvider(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

        return trim((string) ($snapshot['provider'] ?? $snapshot['model_category'] ?? ''));
    }

    private function extractProfile(PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $profile = trim((string) (
            $snapshot['execution_profile']
            ?? $snapshot['routing_profile']
            ?? $snapshot['routing_profile_key']
            ?? $snapshot['variables']['execution_profile']
            ?? ''
        ));

        return $profile;
    }

    private function typeLabelForHook(string $hookKey, string $promptName): string
    {
        if (str_contains($hookKey, 'keyword.discovery')) {
            return 'Keyword Discovery';
        }
        if ($promptName !== '') {
            return $promptName;
        }

        return $hookKey !== '' ? $hookKey : 'AI Call';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'success', 'completed', 'ok' => 'Completed',
            'error', 'failed', 'failure' => 'Failed',
            'running', 'pending', 'queued' => 'Running',
            default => $status !== '' ? ucfirst($status) : '',
        };
    }
}
