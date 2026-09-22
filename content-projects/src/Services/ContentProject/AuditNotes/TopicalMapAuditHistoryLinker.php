<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Illuminate\Support\Facades\Log;

/**
 * Links Topical Map AI Audit PromptResults into Content Plan AI History
 * (Shared Draft planner runs). Does not re-run AI.
 */
final class TopicalMapAuditHistoryLinker
{
    public const OPERATION = 'topical_map_audit';

    public function __construct(
        private readonly ContentProjectPlannerRunService $plannerRuns,
    ) {}

    /**
     * @param  array{
     *   ok: bool,
     *   message?: string,
     *   payload?: array<string, mixed>|null,
     *   prompt_result_id?: int|null
     * }  $result
     */
    public function linkFromAuditResult(int $siteId, ?int $actorId, array $result): void
    {
        $promptResultId = (int) ($result['prompt_result_id'] ?? 0);
        if ($promptResultId <= 0) {
            return;
        }

        $project = $this->resolvePlanningProject($siteId, $actorId);
        if (! $project instanceof SeoProject) {
            Log::warning('topical_map_audit.history_link_skipped_no_project', [
                'site_id' => $siteId,
                'prompt_result_id' => $promptResultId,
            ]);

            return;
        }

        $ok = (bool) ($result['ok'] ?? false);
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $findings = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];
        $actions = is_array($payload['recommended_actions'] ?? null) ? $payload['recommended_actions'] : [];

        $this->plannerRuns->recordExecuted(
            project: $project,
            sourceType: SeoContentProjectPlannerRun::SOURCE_TOPICAL_MAP_AUDIT,
            requestedQuantity: 0,
            configurationSnapshot: [
                'operation' => self::OPERATION,
                'hook_key' => DefaultTopicalMapAuditPromptInstaller::HOOK_KEY,
                'hook_version' => DefaultTopicalMapAuditPromptInstaller::HOOK_VERSION,
                'site_id' => $siteId,
            ],
            resultSummary: [
                'status' => $ok
                    ? SeoContentProjectPlannerRun::STATUS_COMPLETED
                    : SeoContentProjectPlannerRun::STATUS_FAILED,
                'operation' => self::OPERATION,
                'findings_count' => count($findings),
                'actions_count' => count($actions),
                'message' => (string) ($result['message'] ?? ''),
            ],
            actorId: $actorId,
            promptResultId: $promptResultId,
        );
    }

    public function resolvePlanningProject(int $siteId, ?int $actorId = null): ?SeoProject
    {
        $draft = app(PlanningDraftResolver::class)->findCanonicalSharedDraft();
        if ($draft instanceof SeoProject) {
            return $draft;
        }

        if ($siteId <= 0) {
            return null;
        }

        try {
            return app(PlanningDraftIntakeService::class)->ensureSharedDraft(
                $actorId !== null && $actorId > 0 ? $actorId : null,
                $siteId,
            );
        } catch (\Throwable $e) {
            Log::warning('topical_map_audit.ensure_shared_draft_failed', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
