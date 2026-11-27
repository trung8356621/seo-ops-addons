<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;

use App\Models\Site;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use Throwable;

/**
 * Idea Manager — parallel to Content Planning SEO Audit Notes.
 * Local-first UI + debounced server autosave. Does not modify SEO Audit.
 */
final class ContentProjectIdeaManager extends SeoPanelPage
{
    use HidesFilamentPageHeader;

    protected static ?string $slug = 'content-projects/idea-manager';

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = SeoUserNavigation::SORT_PROJECTS + 3;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-idea-manager';

    public ?int $filterSiteId = null;

    public string $activeTab = 'ideas';

    /** @var array<string, mixed>|null */
    public ?array $workspaceSnapshot = null;

    /** @var list<array<string, mixed>> */
    public array $clusterRows = [];

    public int $clusterTotal = 0;

    public string $clusterSearch = '';

    public string $clusterFilter = 'all';

    public int $clusterPage = 1;

    public bool $clusterLoading = false;

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
        $this->applyOptionalSiteQueryParam();
        $this->ensureConcreteGlobalWorkingSite();
        $this->refreshWorkspaceFromServer();
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

        $this->ensureConcreteGlobalWorkingSite($resolved);
        $this->clusterPage = 1;
        $this->clusterRows = [];
        $this->clusterTotal = 0;
        $this->refreshWorkspaceFromServer();
        $this->dispatch('idea-manager-site-changed', siteId: (int) ($this->filterSiteId ?? 0));
    }

    public function setActiveTab(string $tab): void
    {
        $allowed = ['ideas', 'cluster', 'published'];
        $this->activeTab = in_array($tab, $allowed, true) ? $tab : 'ideas';
        if ($this->activeTab === 'cluster' && $this->clusterRows === []) {
            $this->loadClusterSuggestions();
        }
    }

    public function refreshWorkspaceFromServer(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            $this->workspaceSnapshot = null;

            return;
        }

        try {
            $this->workspaceSnapshot = app(IdeaWorkspacePersistenceService::class)->load($siteId, $this->actorId());
        } catch (Throwable) {
            $this->workspaceSnapshot = [
                'status' => IdeaWorkspacePersistenceService::STATUS_FORBIDDEN,
                'site_id' => $siteId,
                'revision' => 0,
                'note_items' => [],
                'content_hash' => '',
                'updated_at' => null,
                'item_count' => 0,
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function saveIdeaWorkspace(int $siteId, array $items, int $expectedRevision): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);
        abort_unless($siteId === (int) ($this->filterSiteId ?? 0), 403);

        try {
            $result = app(IdeaWorkspacePersistenceService::class)->save(
                $siteId,
                $items,
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

        if (in_array($result['status'], [
            IdeaWorkspacePersistenceService::STATUS_OK,
            IdeaWorkspacePersistenceService::STATUS_NOOP,
        ], true)) {
            $this->workspaceSnapshot = $result;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadIdeaWorkspace(int $siteId): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

        $this->filterSiteId = $siteId;
        $this->refreshWorkspaceFromServer();

        $snap = is_array($this->workspaceSnapshot) ? $this->workspaceSnapshot : [
            'status' => IdeaWorkspacePersistenceService::STATUS_OK,
            'site_id' => $siteId,
            'revision' => 0,
            'note_items' => [],
            'content_hash' => '',
            'updated_at' => null,
            'item_count' => 0,
        ];
        $snap['domain'] = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');

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
        $siteId = (int) ($this->filterSiteId ?? 0);
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
     * Server-side bulk preview (canonical normalize + dedupe).
     *
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
     * Optional ?site_id= preselect (permitted sites only). Never required.
     */
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

    /**
     * Mirror Content Planning: prefer Global Domain, else first accessible site.
     * Page works with no site_id in the URL.
     */
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
