<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\SettingsPromptBindingResolver;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Canonical Create-new-with-AI readiness — same structural gates as runtime handler/planner.
 * Does not inspect Prompt legacy model/mode/routing/legacy connection fields.
 */
final class NewContentGenerationReadinessService
{
    public function __construct(
        private readonly SitePrimaryLanguageService $primaryLanguage,
        private readonly SettingsPromptBindingResolver $promptBindings,
        private readonly PromptExecutionProfileResolver $profileResolver,
        private readonly ContentProjectPlannerRunService $plannerRuns,
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
    ) {}

    public function evaluate(?SeoProject $project, ?int $actorUserId = null): NewContentGenerationReadiness
    {
        $blockReasons = [];

        $draftReady = $project instanceof SeoProject && $project->isDraftPlanning();
        $draft = [
            'ready' => $draftReady,
            'reason' => $draftReady
                ? null
                : (string) __('seo-content-ai::filament.projects.planner_readiness_draft_missing'),
        ];
        if (! $draftReady) {
            $blockReasons[] = (string) $draft['reason'];
        }

        $language = $this->evaluateLanguage($project);
        if (! $language['ready']) {
            $blockReasons[] = (string) ($language['reason'] ?? '');
        }

        $prompt = $this->evaluatePrompt();
        if (! $prompt['ready']) {
            $blockReasons[] = (string) ($prompt['reason'] ?? '');
        }

        $profile = $this->evaluateProfile();

        $generation = $this->evaluateGeneration($project);
        if ($generation['active']) {
            $blockReasons[] = (string) ($generation['reason'] ?? '');
        }

        $permission = $this->evaluatePermission($actorUserId);
        if (! $permission['ready']) {
            $blockReasons[] = (string) ($permission['reason'] ?? '');
        }

        $blockReasons = array_values(array_filter(
            $blockReasons,
            static fn (string $reason): bool => $reason !== '',
        ));

        $ready = $draftReady
            && $language['ready']
            && $prompt['ready']
            && ! $generation['active']
            && $permission['ready'];

        // Quantity stays editable when Draft exists; only lock while a live run is active.
        $quantityEnabled = $draftReady && ! $generation['active'];

        return new NewContentGenerationReadiness(
            ready: $ready,
            quantityEnabled: $quantityEnabled,
            generateEnabled: $ready,
            draft: $draft,
            language: $language,
            prompt: $prompt,
            profile: $profile,
            generation: $generation,
            permission: $permission,
            blockReasons: $blockReasons,
        );
    }

    /**
     * @return array{ready: bool, value: ?string, label: ?string, domain_edit_url: ?string, reason: ?string}
     */
    protected function evaluateLanguage(?SeoProject $project): array
    {
        if (! $project instanceof SeoProject) {
            return [
                'ready' => false,
                'value' => null,
                'label' => null,
                'domain_edit_url' => null,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_language_missing'),
            ];
        }

        $siteId = (int) ($project->site_id ?? 0);
        $site = $project->site instanceof Site ? $project->site : null;
        if (! $site instanceof Site && $siteId > 0) {
            $site = Site::query()->find($siteId);
        }
        if (! $site instanceof Site) {
            return [
                'ready' => false,
                'value' => null,
                'label' => null,
                'domain_edit_url' => null,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_language_missing'),
            ];
        }

        $code = $this->primaryLanguage->resolvePrimaryLanguage($site);
        $normalized = is_string($code) ? trim($code) : '';
        $url = null;
        try {
            $url = DomainResource::getUrl('edit', ['record' => $siteId]);
        } catch (Throwable) {
            $url = null;
        }

        if ($normalized === '') {
            return [
                'ready' => false,
                'value' => null,
                'label' => null,
                'domain_edit_url' => $url,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_language_missing'),
            ];
        }

        return [
            'ready' => true,
            'value' => $normalized,
            'label' => $this->primaryLanguage->primaryLanguageLabel($site, $normalized),
            'domain_edit_url' => $url,
            'reason' => null,
        ];
    }

    /**
     * @return array{ready: bool, hook: string, prompt_id: ?int, prompt_name: ?string, reason: ?string}
     */
    protected function evaluatePrompt(): array
    {
        $hook = NewContentGenerationReadiness::HOOK_KEY;

        try {
            $prompt = $this->promptBindings->resolve($hook);
        } catch (PromptHookException) {
            return [
                'ready' => false,
                'hook' => $hook,
                'prompt_id' => null,
                'prompt_name' => null,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_prompt_missing'),
            ];
        } catch (Throwable) {
            return [
                'ready' => false,
                'hook' => $hook,
                'prompt_id' => null,
                'prompt_name' => null,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_prompt_missing'),
            ];
        }

        $name = trim((string) ($prompt->name ?? ''));

        return [
            'ready' => true,
            'hook' => $hook,
            'prompt_id' => $prompt->id !== null ? (int) $prompt->id : null,
            'prompt_name' => $name !== '' ? $name : null,
            'reason' => null,
        ];
    }

    /**
     * @return array{value: ?string, label: ?string}
     */
    protected function evaluateProfile(): array
    {
        $profile = $this->profileResolver->resolve(null, NewContentGenerationReadiness::HOOK_KEY, 'default');

        return [
            'value' => $profile->value,
            'label' => $profile->displayName(),
        ];
    }

    /**
     * @return array{active: bool, status: ?string, run_id: ?int, reason: ?string, stale_run_id?: ?int}
     */
    protected function evaluateGeneration(?SeoProject $project): array
    {
        if (! $project instanceof SeoProject) {
            return [
                'active' => false,
                'status' => null,
                'run_id' => null,
                'reason' => null,
            ];
        }

        $active = $this->plannerRuns->findActive(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
        );
        if (! $active instanceof SeoContentProjectPlannerRun) {
            return [
                'active' => false,
                'status' => null,
                'run_id' => null,
                'reason' => null,
            ];
        }

        if ($this->isStalePlannerRun($active)) {
            return [
                'active' => false,
                'status' => null,
                'run_id' => (int) $active->getKey(),
                'reason' => null,
                'stale_run_id' => (int) $active->getKey(),
            ];
        }

        $status = (string) (($active->result_summary ?? [])['status'] ?? SeoContentProjectPlannerRun::STATUS_QUEUED);
        $reason = $status === SeoContentProjectPlannerRun::STATUS_RUNNING
            ? (string) __('seo-content-ai::filament.projects.planner_readiness_generation_running')
            : (string) __('seo-content-ai::filament.projects.planner_readiness_generation_queued');

        return [
            'active' => true,
            'status' => $status,
            'run_id' => (int) $active->getKey(),
            'reason' => $reason,
            'stale_run_id' => null,
        ];
    }

    /**
     * Clear stale queued/running planner runs so UI is not locked forever.
     */
    public function reconcileStaleActiveRun(SeoProject $project): void
    {
        $active = $this->plannerRuns->findActive(
            $project,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
        );
        if (! $active instanceof SeoContentProjectPlannerRun) {
            return;
        }
        if (! $this->isStalePlannerRun($active)) {
            return;
        }

        $this->plannerRuns->failRun($active, [
            'message' => (string) __('seo-content-ai::filament.projects.planner_readiness_generation_stale'),
            'stale' => true,
        ]);
    }

    /**
     * @return array{ready: bool, reason: ?string}
     */
    protected function evaluatePermission(?int $actorUserId): array
    {
        unset($actorUserId);

        // Page already gates access; keep readiness aligned with workflow permission.
        if (! SeoAccessControl::canManageContentProjectWorkflow()) {
            return [
                'ready' => false,
                'reason' => (string) __('seo-content-ai::filament.projects.planner_readiness_permission_denied'),
            ];
        }

        return [
            'ready' => true,
            'reason' => null,
        ];
    }

    private function isStalePlannerRun(SeoContentProjectPlannerRun $run): bool
    {
        $timeout = max(1, $this->staleness->staleTimeoutMinutes());
        $anchor = $run->updated_at ?? $run->created_at;
        if ($anchor === null) {
            return false;
        }

        return Carbon::parse($anchor)->lte(now()->subMinutes($timeout));
    }
}
