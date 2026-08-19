<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveVaultListPresenter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Omnichannel\Addons\Seo\Livewire\Concerns\RefreshesOnDomainContextChanged;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ContentProjectArchive extends Page
{
    use WithPagination;
    use RefreshesOnDomainContextChanged;

    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive';

    protected static bool $shouldRegisterNavigation = false;

    /** Selected global site, or 0 when All domains. */
    public int $siteId = 0;

    /**
     * Site IDs the current user may view on this page.
     *
     * @var list<int>
     */
    public array $scopedSiteIds = [];

    public string $activeTab = 'projects';

    public string $search = '';

    public string $siteFilter = '';

    public string $monthFilter = '';

    public string $yearFilter = '';

    public string $ownerFilter = '';

    public string $archivedByFilter = '';

    public ?int $reopenSubmittingId = null;

    public ?int $restoreSubmittingId = null;

    /** @var array<string, mixed> */
    protected $queryString = [
        'activeTab' => ['except' => 'projects'],
        'search' => ['except' => ''],
        'siteFilter' => ['except' => ''],
        'monthFilter' => ['except' => ''],
        'yearFilter' => ['except' => ''],
        'ownerFilter' => ['except' => ''],
        'archivedByFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $this->applyDomainContextScope();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        $this->applyDomainContextScope();
        $this->resetPage();
    }

    private function applyDomainContextScope(): void
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            if (! SeoAccessControl::canAccessSite($globalSiteId)) {
                $this->siteId = 0;
                $this->scopedSiteIds = SeoAccessControl::accessibleSiteIds();

                return;
            }

            $this->siteId = $globalSiteId;
            $this->scopedSiteIds = [$globalSiteId];

            return;
        }

        $this->siteId = 0;
        $this->scopedSiteIds = SeoAccessControl::accessibleSiteIds();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.projects.archive_dashboard_heading');
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_projects')
                ->label(__('seo-content-ai::filament.projects.back_to_projects'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('index')),
        ];
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['projects', 'legacy'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSiteFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedOwnerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedArchivedByFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->siteFilter = '';
        $this->monthFilter = '';
        $this->yearFilter = '';
        $this->ownerFilter = '';
        $this->archivedByFilter = '';
        $this->resetPage();
    }

    public function getActiveFilterCount(bool $siteFilterAvailable = true): int
    {
        return ContentProjectArchiveVaultListPresenter::activeFilterCount(
            $this->siteFilter,
            $this->monthFilter,
            $this->yearFilter,
            $this->ownerFilter,
            $this->archivedByFilter,
            $siteFilterAvailable,
        );
    }

    /**
     * @return Builder<SeoProjectArchive>
     */
    public function getProjectArchivesQuery(): Builder
    {
        $query = SeoProjectArchive::query()
            ->current()
            ->with(['project', 'archivedByUser', 'owner', 'site'])
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('archived_at')
                    ->orWhereHas('project', function (Builder $projectQuery): void {
                        $projectQuery->whereNotNull('archived_at');
                    });
            })
            ->orderByDesc('archived_at')
            ->orderByDesc('id');

        ContentProjectArchiveVaultListPresenter::applyIndexSummaryAggregates($query);

        if ($this->scopedSiteIds !== []) {
            $query->whereIn('site_id', $this->scopedSiteIds);
        }

        $search = trim($this->search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('project_name', 'like', $like)
                    ->orWhereHas('site', fn (Builder $siteQuery): Builder => $siteQuery->where('domain', 'like', $like))
                    ->orWhereHas('owner', fn (Builder $ownerQuery): Builder => $ownerQuery
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        if ($this->siteFilter !== '' && (int) $this->siteFilter > 0) {
            $query->where('site_id', (int) $this->siteFilter);
        }

        if ($this->monthFilter !== '' && (int) $this->monthFilter > 0) {
            $query->where('project_month', (int) $this->monthFilter);
        }

        if ($this->yearFilter !== '' && (int) $this->yearFilter > 0) {
            $query->where('project_year', (int) $this->yearFilter);
        }

        if ($this->ownerFilter !== '' && (int) $this->ownerFilter > 0) {
            $query->where('owner_id', (int) $this->ownerFilter);
        }

        if ($this->archivedByFilter !== '' && (int) $this->archivedByFilter > 0) {
            $query->where('archived_by', (int) $this->archivedByFilter);
        }

        return $query;
    }

    /**
     * @return LengthAwarePaginator<int, SeoProjectArchive>
     */
    public function getArchivesProperty(): LengthAwarePaginator
    {
        return $this->getProjectArchivesQuery()->paginate(15);
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getSiteFilterOptions(): array
    {
        return $this->buildUserFilterOptions('site_id', 'site', 'domain');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getOwnerFilterOptions(): array
    {
        return $this->buildUserFilterOptions('owner_id', 'owner', 'name');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getArchivedByFilterOptions(): array
    {
        return $this->buildUserFilterOptions('archived_by', 'archivedByUser', 'name');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getMonthFilterOptions(): array
    {
        $months = $this->getBaseFilterQuery()
            ->whereNotNull('project_month')
            ->distinct()
            ->orderBy('project_month')
            ->pluck('project_month')
            ->map(static fn (mixed $month): int => (int) $month)
            ->filter(static fn (int $month): bool => $month >= 1 && $month <= 12)
            ->values()
            ->all();

        $options = [];
        foreach ($months as $month) {
            $options[] = [
                'value' => $month,
                'label' => sprintf('%02d', $month),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getYearFilterOptions(): array
    {
        $years = $this->getBaseFilterQuery()
            ->whereNotNull('project_year')
            ->distinct()
            ->orderByDesc('project_year')
            ->pluck('project_year')
            ->map(static fn (mixed $year): int => (int) $year)
            ->filter(static fn (int $year): bool => $year > 0)
            ->values()
            ->all();

        $options = [];
        foreach ($years as $year) {
            $options[] = [
                'value' => $year,
                'label' => (string) $year,
            ];
        }

        return $options;
    }

    public function canRestoreArchives(): bool
    {
        return SeoAccessControl::canArchiveContentProjects();
    }

    public function canReopenArchivedArticles(): bool
    {
        return SeoAccessControl::canFinalizeArticleReview() || SeoAccessControl::canApproveArticleReview();
    }

    public function restoreArchive(int $archiveId): void
    {
        abort_unless($this->canRestoreArchives(), 403);

        if ($archiveId <= 0) {
            $this->skipRender();

            return;
        }

        $this->restoreSubmittingId = $archiveId;

        try {
            $archive = $this->findAccessibleArchive($archiveId);
            $project = $archive->project;

            if (! $project instanceof SeoProject) {
                throw new RuntimeException('Project không tồn tại.');
            }

            $user = auth()->user();
            if (! $user instanceof User) {
                abort(403);
            }

            $result = app(ContentProjectCommandBus::class)->dispatch(
                new RestoreContentProjectCommand((int) $project->getKey()),
                ActorContext::user(
                    (int) $user->id,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );

            app(ContentProjectActionResultNotifier::class)->send($result);

            if (! $result->success) {
                throw new RuntimeException($result->message);
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_restore_completed'))
                ->success()
                ->send();

            $this->resetPage();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive.restore',
                'archive_id' => $archiveId,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->restoreSubmittingId = null;
        }
    }

    public function exportArchive(int $archiveId): StreamedResponse
    {
        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        if ($archiveId <= 0) {
            abort(404);
        }

        try {
            $archive = $this->findAccessibleArchive($archiveId);

            return app(ContentProjectArchiveExportService::class)->streamDownload($archive);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive.export',
                'archive_id' => $archiveId,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            abort(500, $exception->getMessage());
        }
    }

    public function reopenArticle(int $articleId): void
    {
        abort_unless($this->canReopenArchivedArticles(), 403);

        if ($articleId <= 0) {
            $this->skipRender();

            return;
        }

        $this->reopenSubmittingId = $articleId;

        try {
            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.projects.unarchive_item_not_found'))
                    ->danger()
                    ->send();

                return;
            }

            $siteId = (int) ($article->site_id ?? 0);
            if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
                abort(403);
            }

            if ($this->scopedSiteIds !== [] && ! in_array($siteId, $this->scopedSiteIds, true)) {
                abort(403);
            }

            $user = auth()->user();
            if (! $user instanceof User) {
                abort(403);
            }

            app(ArticleReviewService::class)->performAction(
                $article,
                $user,
                ArticleReviewActionType::Reopen,
            );

            Notification::make()
                ->title(__('seo-content-ai::filament.article_review.success.reopen'))
                ->success()
                ->send();

            $this->activeTab = 'legacy';
            $this->redirect(static::getUrl());
        } catch (ArticleReviewException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive.reopen',
                'article_id' => $articleId,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.unarchive_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->reopenSubmittingId = null;
        }
    }

    /**
     * @return Builder<SeoProjectArchive>
     */
    private function getBaseFilterQuery(): Builder
    {
        $query = SeoProjectArchive::query()
            ->current()
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('archived_at')
                    ->orWhereHas('project', function (Builder $projectQuery): void {
                        $projectQuery->whereNotNull('archived_at');
                    });
            });

        if ($this->scopedSiteIds !== []) {
            $query->whereIn('site_id', $this->scopedSiteIds);
        }

        return $query;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function buildUserFilterOptions(string $column, string $relation, string $labelColumn): array
    {
        $ids = $this->getBaseFilterQuery()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $archives = $this->getBaseFilterQuery()
            ->with([$relation])
            ->whereIn($column, $ids)
            ->get();

        $options = [];
        $seen = [];

        foreach ($archives as $archive) {
            if (! $archive instanceof SeoProjectArchive) {
                continue;
            }

            $value = (int) ($archive->{$column} ?? 0);
            if ($value <= 0 || isset($seen[$value])) {
                continue;
            }

            $related = $archive->{$relation};
            $label = '—';

            if ($relation === 'site' && $related !== null) {
                $label = trim((string) ($related->domain ?? ''));
            } elseif ($related instanceof User) {
                $label = trim((string) ($related->name ?? ''));
                if ($label === '') {
                    $label = (string) ($related->email ?? '');
                }
            } elseif ($related !== null) {
                $label = trim((string) ($related->{$labelColumn} ?? ''));
            }

            if ($label === '') {
                $label = '#'.$value;
            }

            $seen[$value] = true;
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        usort($options, static fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        return $options;
    }

    private function findAccessibleArchive(int $archiveId): SeoProjectArchive
    {
        $archive = SeoProjectArchive::query()
            ->current()
            ->with('project')
            ->find($archiveId);

        if (! $archive instanceof SeoProjectArchive) {
            throw new RuntimeException('Không tìm thấy bản lưu trữ.');
        }

        $siteId = (int) ($archive->site_id ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            abort(403);
        }

        if ($this->scopedSiteIds !== [] && ! in_array($siteId, $this->scopedSiteIds, true)) {
            abort(403);
        }

        return $archive;
    }
}
