<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleManualIndexMarkerService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscUrlInspectionRunItem;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionBindingResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

final class ContentProjectArchivePreview extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.content-project-archive-preview';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Route parameter `{archive}` — scalar only; model loaded in mount().
     * Do not type this as SeoProjectArchive (Livewire would 404 on binding).
     */
    public int|string $archive = 0;

    public ?SeoProjectArchive $archiveRecord = null;

    public ?string $snapshotLoadError = null;

    /** @var list<array<string, mixed>> */
    public array $articleRows = [];

    public bool $cleanupWorkspaceBusy = false;

    public bool $markingIndexBusy = false;

    public ?int $markingIndexItemId = null;

    /** @var array<string, mixed>|null */
    public ?array $gscInspectionRun = null;

    public ?string $gscInspectionPublicRef = null;

    public bool $gscInspectionCompletedNotified = false;

    public function mount(int|string $archive): void
    {
        self::authorizeResourceAccess();

        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        $this->archive = (int) $archive;
        $this->snapshotLoadError = null;
        $this->articleRows = [];

        try {
            $this->archiveRecord = SeoProjectArchive::query()
                ->current()
                ->with([
                    'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                    'archivedByUser',
                    'owner',
                    'site',
                    'project',
                ])
                ->findOrFail((int) $this->archive);

            $this->rebuildArticleRows();
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'archive_id' => (int) $this->archive,
                'source_project_id' => null,
                'endpoint' => 'content-project-archive-preview',
            ]);

            $this->archiveRecord = SeoProjectArchive::query()
                ->current()
                ->find((int) $this->archive);

            $this->snapshotLoadError = __('seo-content-ai::filament.projects.archive_preview_snapshot_error');

            if (! $this->archiveRecord instanceof SeoProjectArchive) {
                throw $exception;
            }

            try {
                $this->archiveRecord->load([
                    'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                ]);
                $this->rebuildArticleRows();
            } catch (Throwable) {
                $this->articleRows = [];
            }
        }

        $siteId = (int) ($this->archiveRecord->site_id ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        $this->refreshGscInspectionRun();
    }

    public function getTitle(): string|Htmlable
    {
        $name = trim((string) ($this->archiveRecord?->project_name ?? ''));

        return $name !== ''
            ? __('seo-content-ai::filament.projects.archive_preview_heading').': '.$name
            : __('seo-content-ai::filament.projects.archive_preview_heading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('check_index_all')
                ->label('Check Index All')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->countGscInspectableArticles() > 0)
                ->disabled(fn (): bool => $this->isGscInspectionRunning())
                ->action(fn (): null => $this->startCheckIndexAll()),
            Actions\Action::make('cleanup_workspace')
                ->label(__('seo-content-ai::filament.projects.archive_cleanup_workspace'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.projects.archive_cleanup_workspace_heading'))
                ->modalDescription(__('seo-content-ai::filament.projects.archive_cleanup_workspace_confirm'))
                ->visible(fn (): bool => $this->canCleanupArchiveWorkspace())
                ->action(fn (): null => $this->cleanupArchiveWorkspace()),
            Actions\Action::make('back_to_archive')
                ->label(__('seo-content-ai::filament.projects.open_site_archive'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SeoProjectResource::getUrl('archive')),
        ];
    }

    public function canCleanupArchiveWorkspace(): bool
    {
        return $this->archiveRecord instanceof SeoProjectArchive
            && SeoAccessControl::canArchiveContentProjects();
    }

    public function cleanupArchiveWorkspace(): null
    {
        abort_unless($this->canCleanupArchiveWorkspace(), 403);

        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            return null;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $this->cleanupWorkspaceBusy = true;

        try {
            $stats = app(ArchiveContentProjectService::class)->cleanupArchivedWorkspace(
                $this->archiveRecord->loadMissing(['project', 'items']),
                (int) $user->id,
            );

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_cleanup_workspace_completed'))
                ->body($this->formatCleanupStats($stats))
                ->success()
                ->send();

            $this->archiveRecord->refresh();
            $this->archiveRecord->load([
                'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                'archivedByUser',
                'owner',
                'site',
                'project',
            ]);
            $this->rebuildArticleRows();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive_preview.cleanup_workspace',
                'archive_id' => (int) $this->archive,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_cleanup_workspace_failed'))
                ->body($exception instanceof RuntimeException ? $exception->getMessage() : 'Workspace cleanup failed.')
                ->danger()
                ->send();
        } finally {
            $this->cleanupWorkspaceBusy = false;
        }

        return null;
    }

    public function startCheckIndexAll(): null
    {
        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_check_index_all_no_urls'))
                ->warning()
                ->send();

            return null;
        }

        // 1) Current site
        $siteId = (int) ($this->archiveRecord->site_id ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        if ($this->isGscInspectionRunning()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_check_index_all_busy'))
                ->warning()
                ->send();

            return null;
        }

        // 2–3) Master OAuth vs Site↔property mapping (never conflate)
        $diagnosis = app(GscUrlInspectionBindingResolver::class)->diagnoseForSite($siteId);
        $status = (string) ($diagnosis['status'] ?? '');
        if ($status !== 'ok') {
            $this->notifyGscPrerequisiteFailure($diagnosis);

            return null;
        }

        // 4) Eligible = archive row with same public URL as Copy Link
        $urlRows = $this->collectGscInspectableUrlRows();
        if ($urlRows === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_check_index_all_no_urls'))
                ->warning()
                ->send();

            return null;
        }

        $limit = GscUrlInspectionPolicy::MAX_BATCH_LIMIT;
        $queuedRows = array_slice($urlRows, 0, $limit);
        $truncated = count($urlRows) > count($queuedRows);

        try {
            // Async job + progress poll. createAndDispatch falls back to sync if queue dispatch fails.
            $result = app(GscUrlInspectionRunService::class)->queueForResolvedUrls(
                $siteId,
                $queuedRows,
                auth()->id() !== null ? (int) auth()->id() : null,
                $limit,
            );
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'endpoint' => 'content_project_archive_preview.check_index_all',
                'archive_id' => (int) $this->archive,
                'site_id' => $siteId,
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.index_health.gsc_inspect_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        if (! ($result['ok'] ?? false)) {
            $code = (string) ($result['error_code'] ?? '');
            $message = (string) ($result['error_message'] ?? __('seo-content-ai::filament.index_health.gsc_inspect_failed'));

            if ($code === 'gsc.oauth_missing' || $code === 'gsc.property_missing' || $code === 'gsc.permission_denied') {
                $this->notifyGscPrerequisiteFailure([
                    'status' => match ($code) {
                        'gsc.oauth_missing' => 'oauth_missing',
                        'gsc.property_missing' => 'property_unmapped',
                        default => 'permission',
                    },
                    'message' => $message,
                    'domain' => (string) ($diagnosis['domain'] ?? ''),
                    'error_code' => $code,
                ]);

                return null;
            }

            Notification::make()
                ->title($code === 'gsc.no_eligible_articles'
                    ? __('seo-content-ai::filament.projects.archive_check_index_all_no_urls')
                    : __('seo-content-ai::filament.index_health.gsc_inspect_failed'))
                ->body($code === 'gsc.no_eligible_articles' ? null : $message)
                ->warning()
                ->send();

            return null;
        }

        $this->gscInspectionPublicRef = is_string($result['public_ref'] ?? null)
            ? (string) $result['public_ref']
            : null;
        $this->gscInspectionCompletedNotified = false;
        $this->gscInspectionRun = $this->presentGscInspectionRun($result);

        $queuedCount = (int) ($result['requested'] ?? count($queuedRows));
        $queuedNotification = Notification::make()
            ->title(__('seo-content-ai::filament.index_health.gsc_queued', ['count' => $queuedCount]))
            ->success();

        if ($truncated) {
            $queuedNotification->body(__('seo-content-ai::filament.projects.archive_check_index_all_truncated', [
                'queued' => $queuedCount,
                'total' => count($urlRows),
            ]));
        }

        $queuedNotification->send();

        if (! ($result['queued'] ?? true)) {
            $this->handleGscInspectionFinished($result);
        }

        return null;
    }

    public function refreshGscInspectionRun(): void
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            $this->gscInspectionRun = null;

            return;
        }

        $runs = app(GscUrlInspectionRunService::class);
        $summary = null;

        if (is_string($this->gscInspectionPublicRef) && $this->gscInspectionPublicRef !== '') {
            $summary = $runs->summarizeByPublicRef($this->gscInspectionPublicRef);
        }

        if ($summary === null) {
            $siteId = (int) ($this->archiveRecord->site_id ?? 0);
            $summary = $siteId > 0 ? $runs->latestActiveRunForSite($siteId) : null;
            if (is_array($summary) && is_string($summary['public_ref'] ?? null)) {
                $this->gscInspectionPublicRef = (string) $summary['public_ref'];
            }
        }

        // Drop operational boolean `ok` — not an index verdict (avoids UI/DevTools "false" noise).
        $this->gscInspectionRun = $this->presentGscInspectionRun($summary);

        if (! is_array($summary)) {
            return;
        }

        $status = (string) ($summary['status'] ?? '');
        $isActive = in_array($status, ['queued', 'running'], true);

        if ($isActive) {
            // Live badges: re-query canonical Article index health during the batch.
            $this->syncOrphanArchiveSnapshotsFromGscRun((int) ($summary['run_id'] ?? 0));
            $this->reloadArticleRowsFromDatabase();
        }

        if (in_array($status, ['completed', 'partial', 'failed'], true) && ! $this->gscInspectionCompletedNotified) {
            $this->handleGscInspectionFinished($summary);
        }
    }

    public function markArticleIndexed(int $itemId): void
    {
        abort_unless(SeoAccessControl::canViewProjectArchives(), 403);

        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_preview_index_failed'))
                ->body(__('seo-content-ai::filament.projects.archive_preview_no_data'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->archiveRecord->site_id ?? 0);
        abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);

        if ($this->isGscInspectionRunning()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_check_index_all_index_locked'))
                ->warning()
                ->send();

            return;
        }

        if ($itemId <= 0 || $this->markingIndexBusy) {
            return;
        }

        $this->markingIndexBusy = true;
        $this->markingIndexItemId = $itemId;

        try {
            // Server-side lock (cross-tab): active Check Index All run for this site.
            if (app(GscUrlInspectionRunService::class)->hasActiveRunForSite($siteId)) {
                $this->refreshGscInspectionRun();
                throw new RuntimeException(__('seo-content-ai::filament.projects.archive_check_index_all_index_locked'));
            }

            $result = app(ArticleManualIndexMarkerService::class)
                ->markFromArchiveItem($this->archiveRecord, $itemId);

            $this->reloadArticleRowsFromDatabase();

            $indexedLabel = ArchivePreviewArticlePresenter::formatIndexDate($result['indexed_at'] ?? null)
                ?? __('seo-content-ai::filament.projects.archive_preview_index_saved');

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_preview_index_saved'))
                ->body($indexedLabel)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'archive_id' => (int) ($this->archiveRecord->getKey() ?? 0),
                'item_id' => $itemId,
                'endpoint' => 'content-project-archive-preview-mark-index',
            ]);

            Notification::make()
                ->title(__('seo-content-ai::filament.projects.archive_preview_index_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->markingIndexBusy = false;
            $this->markingIndexItemId = null;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function handleGscInspectionFinished(array $summary): void
    {
        $this->gscInspectionCompletedNotified = true;
        $this->syncOrphanArchiveSnapshotsFromGscRun((int) ($summary['run_id'] ?? 0));
        $this->reloadArticleRowsFromDatabase();

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.archive_check_index_all_finished_title'))
            ->body(__('seo-content-ai::filament.projects.archive_check_index_all_finished_body', [
                'checked' => (int) ($summary['inspected'] ?? 0) + (int) ($summary['failed'] ?? 0),
                'indexed_count' => (int) ($summary['indexed'] ?? 0),
                'not_indexed_count' => (int) ($summary['not_indexed'] ?? 0),
                'failed_count' => (int) ($summary['failed'] ?? 0),
            ]))
            ->success()
            ->send();
    }

    public function isGscInspectionRunning(): bool
    {
        $status = (string) ($this->gscInspectionRun['status'] ?? '');

        return in_array($status, ['queued', 'running'], true);
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>|null
     */
    private function presentGscInspectionRun(?array $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        // `ok` is an operational flag (true/false), not indexed/not_indexed.
        unset($summary['ok']);

        return $summary;
    }

    private function reloadArticleRowsFromDatabase(): void
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            $this->articleRows = [];

            return;
        }

        $this->archiveRecord->unsetRelation('items');
        $this->archiveRecord->load([
            'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);
        $this->rebuildArticleRows();
    }

    /**
     * When Article is deleted, Index Health cannot store results — persist onto archive snapshot
     * so Index badges still reflect GSC URL Inspection of the WP link.
     */
    private function syncOrphanArchiveSnapshotsFromGscRun(int $runId): void
    {
        if ($runId <= 0 || ! $this->archiveRecord instanceof SeoProjectArchive) {
            return;
        }

        try {
            $recorded = SeoGscUrlInspectionRunItem::query()
                ->where('run_id', $runId)
                ->where('status', 'recorded')
                ->get(['article_id', 'url', 'check_status']);
        } catch (Throwable) {
            return;
        }

        if ($recorded->isEmpty()) {
            return;
        }

        $byArticleId = [];
        $byUrl = [];
        foreach ($recorded as $item) {
            $articleId = (int) ($item->article_id ?? 0);
            $urlKey = rtrim(mb_strtolower(trim((string) ($item->url ?? ''))), '/');
            $status = (string) ($item->check_status ?? '');
            if ($articleId > 0) {
                $byArticleId[$articleId] = $status;
            }
            if ($urlKey !== '') {
                $byUrl[$urlKey] = $status;
            }
        }

        $items = $this->archiveRecord->items instanceof Collection
            ? $this->archiveRecord->items
            : collect($this->archiveRecord->items ?? []);

        $now = Carbon::now();
        foreach ($items as $archiveItem) {
            if (! $archiveItem instanceof SeoProjectArchiveItem) {
                continue;
            }

            $snapshot = is_array($archiveItem->article_snapshot) ? $archiveItem->article_snapshot : [];
            $articleId = (int) ($archiveItem->article_id ?? ($snapshot['article_id'] ?? 0));
            $wpUrl = trim((string) ($snapshot['wordpress_url'] ?? ''));
            $urlKey = rtrim(mb_strtolower($wpUrl), '/');

            $checkStatus = $byArticleId[$articleId] ?? ($urlKey !== '' ? ($byUrl[$urlKey] ?? null) : null);
            if (! is_string($checkStatus) || $checkStatus === '') {
                continue;
            }

            // Live articles already updated via Index Health recorder.
            if ($articleId > 0 && SeoArticle::query()->whereKey($articleId)->exists()) {
                continue;
            }

            $previous = $snapshot['indexed_at'] ?? ($snapshot['previous_indexed_at'] ?? null);
            if ($checkStatus === ArticleIndexCheckStatus::Indexed->value) {
                $snapshot['previous_indexed_at'] = is_string($previous) && $previous !== ''
                    ? $previous
                    : ($snapshot['previous_indexed_at'] ?? null);
                $snapshot['indexed_at'] = $now->toIso8601String();
            } elseif ($checkStatus === ArticleIndexCheckStatus::NotIndexed->value) {
                if (isset($snapshot['indexed_at']) && $snapshot['indexed_at'] !== null && $snapshot['indexed_at'] !== '') {
                    $snapshot['previous_indexed_at'] = $snapshot['indexed_at'];
                }
                $snapshot['indexed_at'] = null;
            } else {
                continue;
            }

            $archiveItem->article_snapshot = $snapshot;
            $archiveItem->save();
        }
    }

    public function gscConfigureUrl(): string
    {
        try {
            return AiConnectionResource::getUrl('index');
        } catch (Throwable) {
            return '/seo/settings/api';
        }
    }

    /**
     * Same public URL source as Copy Link / per-row Check Index (presenter wordpress_url).
     * Article may be deleted ("Bài gốc không còn tồn tại") — WP URL is still eligible.
     *
     * @return list<array{article_id: int, url: string}>
     */
    private function collectGscInspectableUrlRows(): array
    {
        $rows = [];
        $seenArticle = [];
        $seenUrl = [];
        foreach ($this->articleRows as $row) {
            if (! (bool) ($row['has_public_wordpress_url'] ?? false)) {
                continue;
            }
            $articleId = (int) ($row['article_id'] ?? 0);
            $url = trim((string) ($row['wordpress_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            // Run schema requires article_id; archive items keep it even after Article delete.
            if ($articleId <= 0) {
                continue;
            }
            $urlKey = rtrim(mb_strtolower($url), '/');
            if (isset($seenArticle[$articleId]) || isset($seenUrl[$urlKey])) {
                continue;
            }
            $seenArticle[$articleId] = true;
            $seenUrl[$urlKey] = true;
            $rows[] = ['article_id' => $articleId, 'url' => $url];
        }

        return $rows;
    }

    private function countGscInspectableArticles(): int
    {
        return count($this->collectGscInspectableUrlRows());
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     */
    private function notifyGscPrerequisiteFailure(array $diagnosis): void
    {
        $status = (string) ($diagnosis['status'] ?? '');
        $domain = trim((string) ($diagnosis['domain'] ?? ''));
        $message = trim((string) ($diagnosis['message'] ?? ''));

        $title = match ($status) {
            'oauth_missing' => __('seo-content-ai::filament.projects.archive_check_index_all_oauth_missing'),
            'property_unmapped' => $domain !== ''
                ? __('seo-content-ai::filament.projects.archive_check_index_all_property_unmapped', ['domain' => $domain])
                : ($message !== '' ? $message : __('seo-content-ai::filament.projects.archive_check_index_all_property_unmapped_generic')),
            default => $message !== ''
                ? $message
                : __('seo-content-ai::filament.index_health.gsc_inspect_failed'),
        };

        $notification = Notification::make()
            ->title($title)
            ->warning()
            ->actions([
                \Filament\Notifications\Actions\Action::make('configure_gsc')
                    ->label(__('seo-content-ai::filament.index_health.configure_gsc'))
                    ->url($this->gscConfigureUrl())
                    ->openUrlInNewTab(),
            ]);

        $notification->send();
    }

    public function viewArchiveItemAction(): Action
    {
        return Action::make('viewArchiveItem')
            ->label(__('seo-content-ai::filament.projects.archive_preview_item'))
            ->slideOver()
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->stickyModalHeader()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('seo-content-ai::filament.projects.archive_preview_close'))
            ->extraModalWindowAttributes([
                'class' => 'fi-archive-preview-item-slideover',
            ])
            ->modalHeading(function (array $arguments): HtmlString {
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));
                $title = trim((string) ($row['title'] ?? ''));
                $badge = e(__('seo-content-ai::filament.projects.archive_preview_badge_archived'));

                return new HtmlString(
                    '<div class="flex flex-col gap-2 pe-6">'
                    .'<span class="inline-flex w-fit items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">'
                    .$badge
                    .'</span>'
                    .'<span class="text-base font-semibold text-gray-950 dark:text-white">'
                    .e($title !== '' ? $title : __('seo-content-ai::filament.projects.archive_preview_no_data'))
                    .'</span>'
                    .'</div>'
                );
            })
            ->extraModalFooterActions(function (Action $action): array {
                $arguments = $action->getArguments();
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));
                $editUrl = is_string($row['edit_url'] ?? null) ? $row['edit_url'] : null;

                if ($editUrl === null || $editUrl === '' || ! ($row['can_edit'] ?? false)) {
                    return [];
                }

                return [
                    Action::make('editArticle')
                        ->label(__('seo-content-ai::filament.projects.archive_preview_edit_article'))
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->url($editUrl, shouldOpenInNewTab: true),
                ];
            })
            ->modalContent(function (array $arguments) {
                $row = $this->findRow((int) ($arguments['itemId'] ?? 0));

                return view(
                    'seo-content-ai::filament.resources.seo-project-resource.partials.archive-preview-item-slideover',
                    ['row' => $row],
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaderSummary(): array
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            return [];
        }

        $snapshot = is_array($this->archiveRecord->summary_snapshot) ? $this->archiveRecord->summary_snapshot : [];

        return [
            'project_name' => (string) ($this->archiveRecord->project_name ?: ($snapshot['project_name'] ?? '')),
            'domain' => trim((string) ($this->archiveRecord->site?->domain ?? ($snapshot['domain_name'] ?? ''))),
            'owner' => trim((string) ($this->archiveRecord->owner?->name ?? ($snapshot['owner_name'] ?? ''))),
            'month' => (int) ($this->archiveRecord->project_month ?? ($snapshot['month'] ?? 0)),
            'year' => (int) ($this->archiveRecord->project_year ?? ($snapshot['year'] ?? 0)),
            'total_articles' => (int) ($this->archiveRecord->total_articles ?? $this->archiveRecord->articles_count ?? ($snapshot['total_articles'] ?? 0)),
            'completed_articles' => (int) ($this->archiveRecord->completed_articles ?? ($snapshot['completed_articles'] ?? 0)),
            'synced_articles' => (int) ($this->archiveRecord->synced_articles ?? ($snapshot['synced_articles'] ?? 0)),
            'average_seo_score' => $this->archiveRecord->average_seo_score ?? ($snapshot['average_seo_score'] ?? null),
            'archived_at' => $this->archiveRecord->archived_at,
            'archived_by' => trim((string) ($this->archiveRecord->archivedByUser?->name ?? '')),
            'note' => trim((string) ($this->archiveRecord->note ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findRow(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        foreach ($this->articleRows as $row) {
            if ((int) ($row['item_id'] ?? 0) === $itemId) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function formatCleanupStats(array $stats): string
    {
        $parts = [];
        foreach ($stats as $key => $value) {
            if ($value <= 0) {
                continue;
            }

            $parts[] = str_replace('_', ' ', $key).': '.$value;
        }

        return $parts !== []
            ? implode(' | ', array_slice($parts, 0, 6))
            : __('seo-content-ai::filament.projects.archive_cleanup_workspace_noop');
    }

    private function rebuildArticleRows(): void
    {
        if (! $this->archiveRecord instanceof SeoProjectArchive) {
            $this->articleRows = [];

            return;
        }

        /** @var Collection<int, SeoProjectArchiveItem> $items */
        $items = $this->archiveRecord->items instanceof Collection
            ? $this->archiveRecord->items
            : collect($this->archiveRecord->items ?? []);

        $presenter = app(ArchivePreviewArticlePresenter::class);
        $articlesById = $presenter->loadArticlesById($items);
        $this->articleRows = $presenter->presentItems($items, $articlesById);
    }
}
