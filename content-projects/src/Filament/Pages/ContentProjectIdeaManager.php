<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use App\Models\Site;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerActiveMonthPreference;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceWarehouseReadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use Throwable;

/**
 * Idea Manager Phase 2 — Active Month + Cluster sidebar + Kho Idea + Site Planning.
 * Distribution merges into destination Idea workspace (no Generate/Publish).
 * Does not modify SEO Audit.
 */
final class ContentProjectIdeaManager extends SeoPanelPage
{
    use HidesFilamentPageHeader;

    protected static ?string $slug = 'content-projects/idea-manager';

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = SeoUserNavigation::SORT_PROJECTS + 3;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-idea-manager';

    /**
     * Default owner site for newly seeded Ideas (not a Kho Idea search filter).
     * Independent of sidebar Cluster source filter and of global warehouse read.
     */
    public ?int $filterSiteId = null;

    /**
     * Sidebar Cluster Notes filter only — must NOT mutate warehouse or Idea provenance.
     */
    public ?int $sourceFilterSiteId = null;

    /** Destination site from Site Planning selection (independent of source filter + warehouse). */
    public ?int $targetSiteId = null;

    /** Idea Manager Active Month (YYYY-MM) — user preference; not item planning_month. */
    public string $activeMonth = '';

    public bool $sidebarCollapsed = false;

    /** @var array<string, mixed>|null */
    public ?array $workspaceSnapshot = null;

    /** @var list<array<string, mixed>> */
    public array $clusterRows = [];

    public int $clusterTotal = 0;

    public string $clusterSearch = '';

    public string $clusterFilter = 'all';

    public int $clusterPage = 1;

    public bool $clusterLoading = false;

    /**
     * Temporary runtime diagnostics (?idea_debug=1). Manager+ only. Read-only UI.
     * Remove after missing-Idea browser root cause is confirmed.
     */
    public bool $ideaDebug = false;

    public static function getNavigationLabel(): string
    {
        return (string) __('seo-content-ai::filament.projects.idea_manager_nav_label');
    }

    public static function getNavigationParentItem(): ?string
    {
        return SeoUserNavigation::moduleProjects();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageContentProjectWorkflow();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->activeMonth = IdeaManagerActiveMonthPreference::get();
        $this->applyOptionalSiteQueryParam();
        $this->ensureConcreteGlobalWorkingSite();
        // Source filter defaults to working site for Cluster search only.
        $this->sourceFilterSiteId = (int) ($this->filterSiteId ?? 0) > 0
            ? (int) $this->filterSiteId
            : null;
        $this->refreshWorkspaceFromServer();
        $this->loadClusterSuggestions();
        $this->ideaDebug = $this->resolveIdeaDebugFlag();
    }

    /**
     * Admin/manager-only debug panel gate. Query must be exactly idea_debug=1.
     */
    protected function resolveIdeaDebugFlag(): bool
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return false;
        }
        $raw = request()->query('idea_debug');

        return $raw === '1' || $raw === 1;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.projects.idea_manager_title');
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $resolved = is_numeric($siteId) && (int) $siteId > 0
            ? (int) $siteId
            : SeoAccessControl::globalSiteId();

        // Global Domain updates default seed owner only — never filters Kho Idea warehouse,
        // never overwrites an explicit target, and never forces sourceFilterSiteId
        // (sidebar Cluster filter stays user-controlled).
        $this->ensureConcreteGlobalWorkingSite($resolved);
        $this->refreshWorkspaceFromServer();
        $this->dispatch('idea-manager-site-changed', siteId: (int) ($this->filterSiteId ?? 0));
    }

    public function setActiveMonth(string $month): void
    {
        $this->activeMonth = IdeaManagerActiveMonthPreference::set($month);
        // Re-render Site Planning highlight only — do not touch workspaces or planning_month.
        $this->dispatch('idea-manager-active-month-changed', month: $this->activeMonth);
    }

    public function updatedActiveMonth(string $value): void
    {
        $this->setActiveMonth($value);
    }

    public function toggleSidebar(): void
    {
        $this->sidebarCollapsed = ! $this->sidebarCollapsed;
    }

    public function setSourceSite(int $siteId): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }

        // Cluster Notes filter only — do NOT change warehouse ownership / Alpine working set.
        $this->sourceFilterSiteId = $siteId;
        $this->clusterPage = 1;
        $this->clusterRows = [];
        $this->clusterTotal = 0;
        $this->loadClusterSuggestions();
        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');
        $this->dispatch('idea-manager-source-filter-changed', siteId: $siteId, domain: $domain);
    }

    public function selectIdeaManagerTargetSite(int $siteId): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }

        // Destination only — never mutates source workspace or item ownership.
        $this->targetSiteId = $siteId;
        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');
        $this->dispatch('idea-manager-target-changed', siteId: $siteId, domain: $domain);
    }

    /**
     * Destination duplicate preview for selected Ideas (workspace + Topic Core).
     *
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function previewDistribution(array $selectedItems): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $targetId = (int) ($this->targetSiteId ?? 0);
        if ($targetId <= 0) {
            return [
                'target_site_id' => null,
                'target_domain' => '',
                'active_month' => ContentProjectMonthContext::normalize($this->activeMonth ?: null),
                'eligible_count' => 0,
                'duplicate_count' => 0,
                'can_confirm' => false,
                'items' => [],
            ];
        }

        return app(IdeaManagerDistributionService::class)->preview(
            $targetId,
            $selectedItems,
            $this->activeMonth,
        );
    }

    /**
     * Confirm distribution into destination Idea workspace. Source unchanged.
     *
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function confirmDistribution(array $selectedItems): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $sourceId = (int) ($this->filterSiteId ?? 0);
        $targetId = (int) ($this->targetSiteId ?? 0);

        return app(IdeaManagerDistributionService::class)->confirm(
            $sourceId,
            $targetId,
            $selectedItems,
            $this->activeMonth,
            $this->actorId(),
        );
    }

    /**
     * Action A (read-only): Check selected Ideas against destination Article Focus Keywords.
     *
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function checkDistributionDuplicates(array $selectedItems): array
    {
        return $this->previewDistribution($selectedItems);
    }

    /**
     * Action B: Distribute selected Ideas via canonical SEO Audit Generate Ideas workflow.
     * Source Ideas remain reusable and are never consumed/deleted.
     *
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function distributeWithAi(array $selectedItems, string $requestKey = ''): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $targetId = (int) ($this->targetSiteId ?? 0);
        if ($targetId <= 0) {
            return [
                'ok' => false,
                'status' => 'failed',
                'code' => 'missing_target_site',
                'message' => (string) __('seo-content-ai::filament.projects.idea_manager_distribution_pick_target'),
                'planner_run_id' => 0,
            ];
        }

        return app(IdeaManagerDistributionService::class)->distributeWithAi(
            $targetId,
            $selectedItems,
            $this->activeMonth,
            $requestKey,
            $this->actorId(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function pollDistributionRun(int $runId): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        return app(IdeaManagerDistributionService::class)->pollRun($runId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reconcileDistributionRun(int $targetSiteId): ?array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        return app(IdeaManagerDistributionService::class)->reconcileLatestRun($targetId = $targetSiteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function sitePlanningPayload(): array
    {
        return app(SitePlanningReadModel::class)->overview(
            null,
            ContentProjectMonthContext::normalize($this->activeMonth ?: null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sitePlanningCellDetail(int $siteId, string $planningMonth): array
    {
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

        return app(SitePlanningReadModel::class)->cellDetail($siteId, $planningMonth);
    }

    /**
     * @return array{
     *   active_month: string,
     *   target_site_id: int|null,
     *   target_domain: string,
     *   planned: int|null,
     *   target: int|null,
     *   over_target: bool,
     *   contract: string
     * }
     */
    public function distributionContext(): array
    {
        $month = ContentProjectMonthContext::normalize($this->activeMonth ?: null);
        $targetId = (int) ($this->targetSiteId ?? 0);
        $domain = '';
        $planned = null;
        $target = null;
        $over = false;

        if ($targetId > 0 && SeoAccessControl::canAccessSite($targetId)) {
            $domain = (string) (Site::query()->whereKey($targetId)->value('domain') ?? '');
            $payload = $this->sitePlanningPayload();
            foreach (($payload['rows'] ?? []) as $row) {
                if ((int) ($row['site_id'] ?? 0) !== $targetId) {
                    continue;
                }
                foreach (($row['months'] ?? []) as $cell) {
                    if (ContentProjectMonthContext::normalize((string) ($cell['planning_month'] ?? '')) !== $month) {
                        continue;
                    }
                    $planned = (int) ($cell['planned'] ?? 0);
                    $target = (int) ($cell['target'] ?? 0);
                    $over = (bool) ($cell['over_target'] ?? false);
                    break 2;
                }
            }
        }

        return [
            'active_month' => $month,
            'target_site_id' => $targetId > 0 ? $targetId : null,
            'target_domain' => $domain,
            'planned' => $planned,
            'target' => $target,
            'over_target' => $over,
            'contract' => 'ai_new_content_generation',
        ];
    }

    public function refreshWorkspaceFromServer(): void
    {
        try {
            $this->workspaceSnapshot = app(IdeaWorkspaceWarehouseReadService::class)
                ->loadAuthorized($this->actorId());
        } catch (Throwable) {
            $this->workspaceSnapshot = [
                'status' => IdeaWorkspacePersistenceService::STATUS_FORBIDDEN,
                'site_id' => 0,
                'revision' => 0,
                'note_items' => [],
                'content_hash' => '',
                'updated_at' => null,
                'item_count' => 0,
                'sites' => [],
                'global' => true,
            ];
        }
    }

    /**
     * Persist one owning workspace only — never a multi-site merged JSON dump.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function saveIdeaWorkspace(int $siteId, array $items, int $expectedRevision): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

        // Strip warehouse-only keys; reject accidental cross-owner rows in this payload.
        $owned = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $owner = (int) ($item['owner_site_id'] ?? $item['source_site_id'] ?? $siteId);
            if ($owner > 0 && $owner !== $siteId) {
                continue;
            }
            $clean = $item;
            unset($clean['owner_site_id'], $clean['owner_domain'], $clean['warehouse_key']);
            $owned[] = $clean;
        }

        try {
            $result = app(IdeaWorkspacePersistenceService::class)->save(
                $siteId,
                $owned,
                $expectedRevision,
                $this->actorId(),
            );
        } catch (Throwable $e) {
            return [
                'status' => IdeaWorkspacePersistenceService::STATUS_FORBIDDEN,
                'site_id' => $siteId,
                'revision' => $expectedRevision,
                'note_items' => [],
                'content_hash' => '',
                'updated_at' => null,
                'item_count' => 0,
                'message' => $e->getMessage(),
            ];
        }

        // Keep Livewire snapshot as global read-model after a successful owner write.
        if (in_array($result['status'], [
            IdeaWorkspacePersistenceService::STATUS_OK,
            IdeaWorkspacePersistenceService::STATUS_NOOP,
        ], true)) {
            $this->refreshWorkspaceFromServer();
        }

        return $result;
    }

    /**
     * Soft-reload global warehouse (default seed owner may change with $siteId).
     *
     * @return array<string, mixed>
     */
    public function loadIdeaWorkspace(int $siteId): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        if ($siteId > 0) {
            abort_unless(SeoAccessControl::canAccessSite($siteId), 403);
            $this->filterSiteId = $siteId;
        }

        $this->refreshWorkspaceFromServer();

        $snap = is_array($this->workspaceSnapshot) ? $this->workspaceSnapshot : [
            'status' => IdeaWorkspacePersistenceService::STATUS_OK,
            'site_id' => 0,
            'revision' => 0,
            'note_items' => [],
            'content_hash' => '',
            'updated_at' => null,
            'item_count' => 0,
            'sites' => [],
            'global' => true,
        ];
        $snap['domain'] = $siteId > 0
            ? (string) (Site::query()->whereKey($siteId)->value('domain') ?? '')
            : '';
        $snap['default_owner_site_id'] = (int) ($this->filterSiteId ?? 0);

        return $snap;
    }

    /**
     * Explicit global warehouse reload (focus / intake ping) — does not change seed owner.
     *
     * @return array<string, mixed>
     */
    public function loadGlobalIdeaWarehouse(): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        $this->refreshWorkspaceFromServer();
        $snap = is_array($this->workspaceSnapshot) ? $this->workspaceSnapshot : [
            'status' => IdeaWorkspacePersistenceService::STATUS_OK,
            'note_items' => [],
            'item_count' => 0,
            'sites' => [],
            'global' => true,
        ];
        $snap['default_owner_site_id'] = (int) ($this->filterSiteId ?? 0);
        $snap['domain'] = (string) ($this->workingSitePayload['domain'] ?? '');

        return $snap;
    }

    public function applyClusterSearch(): void
    {
        $this->clusterSearch = trim($this->clusterSearch);
        $this->clusterPage = 1;
        $this->loadClusterSuggestions();
    }

    public function updatedClusterFilter(): void
    {
        $this->clusterPage = 1;
        $this->loadClusterSuggestions();
    }

    public function gotoClusterPage(int $page): void
    {
        $this->clusterPage = max(1, $page);
        $this->loadClusterSuggestions();
    }

    public function loadClusterSuggestions(): void
    {
        $siteId = (int) ($this->sourceFilterSiteId ?? $this->filterSiteId ?? 0);
        $this->clusterLoading = true;
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            $this->clusterRows = [];
            $this->clusterTotal = 0;
            $this->clusterLoading = false;

            return;
        }

        $result = app(AuditNoteClusterSuggestionQuery::class)->paginate($siteId, [
            'search' => $this->clusterSearch,
            'filter' => $this->clusterFilter,
            'page' => $this->clusterPage,
        ]);
        $this->clusterRows = $result['rows'];
        $this->clusterTotal = (int) $result['total'];
        $this->clusterLoading = false;
        $this->dispatch('idea-manager-cluster-rows', rows: $this->clusterRows, total: $this->clusterTotal);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return array<string, mixed>
     */
    public function previewBulkIdeas(string $textarea, array $existing): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        return IdeaWorkspaceItemOps::previewBulkLines($textarea, $existing);
    }

    /**
     * @return array{site_id: int, domain: string}
     */
    public function getWorkingSitePayloadProperty(): array
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        $domain = '';
        if ($siteId > 0) {
            $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');
        }

        return ['site_id' => $siteId, 'domain' => $domain];
    }

    /**
     * @return array{site_id: int, domain: string}
     */
    public function getSourceFilterPayloadProperty(): array
    {
        $siteId = (int) ($this->sourceFilterSiteId ?? 0);
        $domain = '';
        if ($siteId > 0) {
            $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');
        }

        return ['site_id' => $siteId, 'domain' => $domain];
    }

    /**
     * @return list<array{id: int, domain: string}>
     */
    public function getSourceSiteOptionsProperty(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->map(static fn (Site $s): array => [
                'id' => (int) $s->getKey(),
                'domain' => (string) ($s->domain ?? ('#'.$s->getKey())),
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getActiveMonthOptionsProperty(): array
    {
        return IdeaManagerActiveMonthPreference::options();
    }

    protected function applyOptionalSiteQueryParam(): void
    {
        $raw = request()->query('site_id', request()->query('site'));
        if (! is_numeric($raw)) {
            return;
        }
        $siteId = (int) $raw;
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }
        SeoAccessControl::setGlobalSiteId($siteId);
    }

    protected function ensureConcreteGlobalWorkingSite(?int $preferred = null): void
    {
        $siteId = ($preferred !== null && $preferred > 0)
            ? $preferred
            : SeoAccessControl::globalSiteId();

        if ($siteId === null || $siteId <= 0) {
            $first = SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->first();
            if ($first instanceof Site) {
                $siteId = (int) $first->getKey();
                SeoAccessControl::setGlobalSiteId($siteId);
            } else {
                $siteId = 0;
            }
        }

        if ($siteId > 0 && ! SeoAccessControl::canAccessSite($siteId)) {
            $siteId = 0;
        }

        $this->filterSiteId = $siteId > 0 ? $siteId : null;
    }

    private function actorId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
