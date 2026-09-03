<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerPlanCloneAllowlist;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\PlannerPlanCloneService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Clone Project Planner configuration (Topics / DNA / targets) across domains.
 * Never clones generated suggestions, runs, PromptResults, or Draft items.
 */
trait InteractsWithPlannerPlanClone
{
    /** @var array<string, mixed>|null */
    public ?array $plannerPlanCloneResult = null;

    public bool $plannerPlanCloneBusy = false;

    /**
     * @return array<int, string> site_id => domain (excludes current working site)
     */
    public function getPlannerPlanCloneDestinationOptionsProperty(): array
    {
        $sourceId = $this->resolvePlannerPlanCloneSourceSiteId();
        $options = [];
        foreach (SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->get(['id', 'domain']) as $site) {
            $id = (int) $site->getKey();
            if ($id <= 0 || $id === $sourceId) {
                continue;
            }
            $options[$id] = (string) $site->domain;
        }

        return $options;
    }

    public function getPlannerPlanCloneSourceDomainProperty(): string
    {
        $sourceId = $this->resolvePlannerPlanCloneSourceSiteId();
        if ($sourceId <= 0) {
            return '';
        }

        return (string) (Site::query()->whereKey($sourceId)->value('domain') ?? ('#'.$sourceId));
    }

    public function canShowPlannerPlanClone(): bool
    {
        if (! SeoAccessControl::canManageContentProjectWorkflow()) {
            return false;
        }
        if ($this->resolvePlannerPlanCloneSourceSiteId() <= 0) {
            return false;
        }

        // Livewire may be empty while Alpine localStorage holds the plan — UI also gates on Alpine.
        return true;
    }

    /**
     * @param  list<array<string, mixed>>|null  $sourceNoteItems  Alpine snapshot (preferred)
     * @param  list<int>  $destinationSiteIds
     * @param  array<int|string, bool|int>  $clientHasPlanBySite
     * @param  array<int|string, list<array<string, mixed>>>  $clientItemsBySite
     */
    public function clonePlannerPlan(
        ?array $sourceNoteItems = null,
        array $destinationSiteIds = [],
        string $mode = PlannerPlanCloneAllowlist::MODE_SKIP_EXISTING,
        array $clientHasPlanBySite = [],
        array $clientItemsBySite = [],
    ): void {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $this->plannerPlanCloneBusy = true;
        $this->plannerPlanCloneResult = null;

        try {
            $project = $this->resolvePlannerPlanCloneProject();
            if (! $project instanceof SeoProject) {
                Notification::make()
                    ->warning()
                    ->title((string) __('seo-content-ai::filament.projects.planner_clone_blocked'))
                    ->body((string) __('seo-content-ai::filament.projects.seo_audit_draft_required_body'))
                    ->send();

                return;
            }

            $sourceSiteId = $this->resolvePlannerPlanCloneSourceSiteId();
            if ($sourceSiteId <= 0) {
                Notification::make()
                    ->warning()
                    ->title((string) __('seo-content-ai::filament.projects.planner_clone_blocked'))
                    ->body((string) __('seo-content-ai::filament.projects.planner_clone_need_source'))
                    ->send();

                return;
            }

            $items = is_array($sourceNoteItems) && $sourceNoteItems !== []
                ? $sourceNoteItems
                : (method_exists($this, 'auditNoteItemsForOptions') ? $this->auditNoteItemsForOptions() : []);
            $items = AuditNoteDnaNormalizer::normalizeNoteItems($items);
            if ($items === []) {
                Notification::make()
                    ->warning()
                    ->title((string) __('seo-content-ai::filament.projects.planner_clone_blocked'))
                    ->body((string) __('seo-content-ai::filament.projects.audit_notes_empty_plan'))
                    ->send();

                return;
            }

            $contentType = NewContentSuggestionOptions::CONTENT_TYPE_POST;
            if (property_exists($this, 'newContentPostType')) {
                $contentType = NewContentSuggestionOptions::normalizeContentType(
                    (string) $this->newContentPostType,
                );
            }

            $hasPlan = [];
            foreach ($clientHasPlanBySite as $siteId => $flag) {
                $hasPlan[(int) $siteId] = (bool) $flag;
            }
            $itemsBySite = [];
            foreach ($clientItemsBySite as $siteId => $rows) {
                if (is_array($rows)) {
                    $itemsBySite[(int) $siteId] = $rows;
                }
            }

            $result = app(PlannerPlanCloneService::class)->cloneToDestinations(
                $project,
                $sourceSiteId,
                array_map('intval', $destinationSiteIds),
                $items,
                $contentType,
                $mode,
                auth()->id() !== null ? (int) auth()->id() : null,
                $hasPlan,
                $itemsBySite,
            );

            $payload = $result->toArray();
            // Attach open URLs for UI (per destination).
            foreach ($payload['destinations'] as $i => $row) {
                $destId = (int) ($row['site_id'] ?? 0);
                $payload['destinations'][$i]['open_url'] = $destId > 0
                    ? ContentProjectSeoAuditPlanner::getUrl(['draft_domain' => (string) $destId])
                    : '#';
            }

            $this->plannerPlanCloneResult = $payload;
            $this->dispatch('planner-plan-cloned', result: $payload);

            Notification::make()
                ->success()
                ->title((string) __('seo-content-ai::filament.projects.planner_clone_done_title'))
                ->body((string) ($payload['summary_message'] ?? ''))
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->warning()
                ->title((string) __('seo-content-ai::filament.projects.planner_clone_blocked'))
                ->body($e->getMessage())
                ->send();
        } catch (Throwable $e) {
            Log::warning('planner.plan_clone.failed', ['message' => $e->getMessage()]);
            Notification::make()
                ->danger()
                ->title((string) __('seo-content-ai::filament.projects.planner_clone_failed'))
                ->body($e->getMessage())
                ->send();
        } finally {
            $this->plannerPlanCloneBusy = false;
        }
    }

    protected function resolvePlannerPlanCloneSourceSiteId(): int
    {
        if (property_exists($this, 'filterSiteId')) {
            $id = (int) ($this->filterSiteId ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return (int) (SeoAccessControl::globalSiteId() ?? 0);
    }

    protected function resolvePlannerPlanCloneProject(): ?SeoProject
    {
        if (method_exists($this, 'resolveNewContentProject')) {
            $project = $this->resolveNewContentProject();
            if ($project instanceof SeoProject) {
                return $project;
            }
        }
        if (property_exists($this, 'project') && $this->project instanceof SeoProject) {
            return $this->project;
        }

        return null;
    }
}
