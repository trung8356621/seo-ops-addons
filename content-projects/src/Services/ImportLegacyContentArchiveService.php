<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use App\Models\User;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Materialize historical `seo_content_archive_items` into the canonical
 * “Legacy articles” archived Content Project (SeoProjectArchive + items).
 *
 * Does NOT call the normal strong archive() path — no workspace destroy.
 * Task shells keep article_id=null so active CP ownership elsewhere is never stolen.
 */
final class ImportLegacyContentArchiveService
{
    public const PROJECT_NAME = 'Legacy articles';

    public const META_IMPORT_SOURCE = ContentProjectGlobalLegacyArchive::META_IMPORT_SOURCE;

    public const META_IMPORT_VERSION = 1;

    public const SOURCE_PREFIX = 'legacy-archive-article:';

    public function __construct(
        private readonly ContentProjectArticleMembership $membership,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys = new ProjectTaskSourceKeyGenerator(),
    ) {}

    /**
     * @return array{
     *     source_rows: int,
     *     valid_article_ids: int,
     *     missing_articles: int,
     *     duplicate_source_article_ids: int,
     *     already_imported: int,
     *     active_cp_memberships: int,
     *     historical_no_active_membership: int,
     *     target_archive_items_after_run: int,
     *     project_id: int|null,
     *     archive_id: int|null,
     *     missing_article_id_samples: list<int>,
     *     active_membership_article_id_samples: list<int>,
     * }
     */
    public function dryRun(): array
    {
        return $this->plan(mutate: false);
    }

    /**
     * @return array{
     *     source_rows: int,
     *     valid_article_ids: int,
     *     missing_articles: int,
     *     duplicate_source_article_ids: int,
     *     already_imported: int,
     *     active_cp_memberships: int,
     *     historical_no_active_membership: int,
     *     target_archive_items_after_run: int,
     *     project_id: int|null,
     *     archive_id: int|null,
     *     created_project: bool,
     *     created_archive: bool,
     *     tasks_upserted: int,
     *     items_upserted: int,
     *     missing_article_id_samples: list<int>,
     *     active_membership_article_id_samples: list<int>,
     * }
     */
    public function apply(?int $actorUserId = null): array
    {
        return $this->plan(mutate: true, actorUserId: $actorUserId);
    }

    /**
     * @return array{
     *     source_count: int,
     *     source_unique_articles: int,
     *     target_count: int,
     *     target_unique_articles: int,
     *     missing_in_target: list<int>,
     *     extra_in_target: list<int>,
     *     duplicate_target_articles: list<int>,
     *     project_id: int|null,
     *     archive_id: int|null,
     * }
     */
    public function reconcile(): array
    {
        $sourceIds = $this->validUniqueSourceArticleIds();
        $project = $this->findImportedProject();
        $archive = $project instanceof SeoProject ? $this->findCurrentArchive($project) : null;

        $targetIds = [];
        $duplicateTarget = [];
        if ($archive instanceof SeoProjectArchive) {
            $seen = [];
            foreach (SeoProjectArchiveItem::query()
                ->where('seo_project_archive_id', (int) $archive->getKey())
                ->pluck('article_id') as $rawId
            ) {
                $id = (int) $rawId;
                if ($id <= 0) {
                    continue;
                }
                if (isset($seen[$id])) {
                    $duplicateTarget[$id] = $id;
                    continue;
                }
                $seen[$id] = $id;
                $targetIds[] = $id;
            }
        }

        $sourceSet = array_fill_keys($sourceIds, true);
        $targetSet = array_fill_keys($targetIds, true);

        $missing = [];
        foreach ($sourceIds as $id) {
            if (! isset($targetSet[$id])) {
                $missing[] = $id;
            }
        }

        $extra = [];
        foreach ($targetIds as $id) {
            if (! isset($sourceSet[$id])) {
                $extra[] = $id;
            }
        }

        return [
            'source_count' => SeoContentArchiveItem::query()->count(),
            'source_unique_articles' => count($sourceIds),
            'target_count' => $archive instanceof SeoProjectArchive
                ? (int) SeoProjectArchiveItem::query()
                    ->where('seo_project_archive_id', (int) $archive->getKey())
                    ->count()
                : 0,
            'target_unique_articles' => count($targetIds),
            'missing_in_target' => array_slice($missing, 0, 50),
            'extra_in_target' => array_slice($extra, 0, 50),
            'duplicate_target_articles' => array_values(array_slice($duplicateTarget, 0, 50)),
            'project_id' => $project instanceof SeoProject ? (int) $project->getKey() : null,
            'archive_id' => $archive instanceof SeoProjectArchive ? (int) $archive->getKey() : null,
        ];
    }

    public function findImportedProject(): ?SeoProject
    {
        $query = SeoProject::query()->where('name', self::PROJECT_NAME);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_projects', 'meta')) {
            $found = (clone $query)
                ->where('meta->import_source', self::META_IMPORT_SOURCE)
                ->orderBy('id')
                ->first();
            if ($found instanceof SeoProject) {
                return $found;
            }
        }

        $fallback = $query->orderBy('id')->first();

        return $fallback instanceof SeoProject ? $fallback : null;
    }

    /**
     * @return list<int>
     */
    public function validUniqueSourceArticleIds(): array
    {
        $ids = [];
        $dupes = [];
        foreach (SeoContentArchiveItem::query()->orderBy('id')->cursor() as $item) {
            if (! $item instanceof SeoContentArchiveItem) {
                continue;
            }
            $articleId = (int) ($item->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }
            if (isset($ids[$articleId])) {
                $dupes[$articleId] = $articleId;
                continue;
            }
            if (! SeoArticle::query()->whereKey($articleId)->exists()) {
                continue;
            }
            $ids[$articleId] = $articleId;
        }

        return array_values($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(bool $mutate, ?int $actorUserId = null): array
    {
        $sourceRows = (int) SeoContentArchiveItem::query()->count();
        $seen = [];
        $duplicates = 0;
        $missing = 0;
        $missingSamples = [];
        /** @var list<SeoContentArchiveItem> $validItems */
        $validItems = [];

        foreach (SeoContentArchiveItem::query()->orderBy('id')->cursor() as $item) {
            if (! $item instanceof SeoContentArchiveItem) {
                continue;
            }
            $articleId = (int) ($item->article_id ?? 0);
            if ($articleId <= 0) {
                $missing++;
                continue;
            }
            if (isset($seen[$articleId])) {
                $duplicates++;
                continue;
            }
            $seen[$articleId] = true;
            if (! SeoArticle::query()->whereKey($articleId)->exists()) {
                $missing++;
                if (count($missingSamples) < 20) {
                    $missingSamples[] = $articleId;
                }
                continue;
            }
            $validItems[] = $item;
        }

        $activeMemberships = 0;
        $activeSamples = [];
        foreach ($validItems as $item) {
            $articleId = (int) $item->article_id;
            if ($this->membership->belongsToActiveContentProject($articleId)) {
                $activeMemberships++;
                if (count($activeSamples) < 20) {
                    $activeSamples[] = $articleId;
                }
            }
        }

        $existingProject = $this->findImportedProject();
        $existingArchive = $existingProject instanceof SeoProject
            ? $this->findCurrentArchive($existingProject)
            : null;
        $alreadyImported = 0;
        if ($existingArchive instanceof SeoProjectArchive) {
            $alreadyImported = (int) SeoProjectArchiveItem::query()
                ->where('seo_project_archive_id', (int) $existingArchive->getKey())
                ->count();
        }

        $report = [
            'source_rows' => $sourceRows,
            'valid_article_ids' => count($validItems),
            'missing_articles' => $missing,
            'duplicate_source_article_ids' => $duplicates,
            'already_imported' => $alreadyImported,
            'active_cp_memberships' => $activeMemberships,
            'historical_no_active_membership' => max(0, count($validItems) - $activeMemberships),
            'target_archive_items_after_run' => count($validItems),
            'project_id' => $existingProject instanceof SeoProject ? (int) $existingProject->getKey() : null,
            'archive_id' => $existingArchive instanceof SeoProjectArchive ? (int) $existingArchive->getKey() : null,
            'missing_article_id_samples' => $missingSamples,
            'active_membership_article_id_samples' => $activeSamples,
        ];

        if (! $mutate) {
            return $report;
        }

        $actorUserId = ($actorUserId !== null && $actorUserId > 0)
            ? $actorUserId
            : $this->resolveDefaultActorUserId($validItems);

        $createdProject = false;
        $createdArchive = false;
        $tasksUpserted = 0;
        $itemsUpserted = 0;

        $result = DB::connection('omi_seo_ai')->transaction(function () use (
            $validItems,
            $actorUserId,
            &$createdProject,
            &$createdArchive,
            &$tasksUpserted,
            &$itemsUpserted,
        ): array {
            $project = $this->findImportedProject();
            if (! $project instanceof SeoProject) {
                $project = new SeoProject;
                $project->fill([
                    'name' => self::PROJECT_NAME,
                    'user_id' => $actorUserId,
                    'site_id' => null,
                    // Schema requires non-null month; historical bulk import is not month-scoped.
                    // Deterministic: first day of month of latest legacy archived_at.
                    'month' => $this->resolveProjectMonth($validItems),
                    'status' => SeoProject::STATUS_COMPLETED,
                    'kind' => SeoProject::KIND_MONTHLY,
                    'total_tasks' => 0,
                    'description' => 'Imported historical articles into Legacy articles archive project.',
                ]);
                $createdProject = true;
            }

            $archivedAt = $this->resolveProjectArchivedAt($validItems);
            $meta = is_array($project->meta) ? $project->meta : [];
            $meta['import_source'] = self::META_IMPORT_SOURCE;
            $meta['import_version'] = self::META_IMPORT_VERSION;
            $meta['import_multi_domain'] = true;
            $meta['imported_at'] = now()->toIso8601String();

            $project->forceFill([
                'name' => self::PROJECT_NAME,
                'site_id' => null,
                'month' => $project->month ?? $this->resolveProjectMonth($validItems),
                'archived_at' => $project->archived_at ?? $archivedAt,
                'archived_by' => $project->archived_by ?? $actorUserId,
                'meta' => $meta,
                'status' => SeoProject::STATUS_COMPLETED,
            ])->save();

            $archive = $this->findCurrentArchive($project);
            if (! $archive instanceof SeoProjectArchive) {
                $archive = new SeoProjectArchive;
                $archive->project_id = (int) $project->getKey();
                $createdArchive = true;
            }

            $archive->fill([
                'site_id' => null,
                'owner_id' => (int) ($project->user_id ?? $actorUserId),
                'project_name' => self::PROJECT_NAME,
                // Schema/UI filter on archive header month/year (not a domain/month split).
                'project_month' => (int) $archivedAt->month,
                'project_year' => (int) $archivedAt->year,
                'articles_count' => count($validItems),
                'total_articles' => count($validItems),
                'completed_articles' => count($validItems),
                'approved_articles' => count($validItems),
                'synced_articles' => 0,
                'average_seo_score' => null,
                'note' => 'Historical import from seo_content_archive_items (no strong archive cleanup).',
                'archived_by' => (int) ($archive->archived_by ?? $actorUserId),
                'archived_at' => $archive->archived_at ?? $archivedAt,
                'restored_at' => null,
                'restored_by' => null,
                'summary_snapshot' => [
                    'project_name' => self::PROJECT_NAME,
                    'domain_name' => 'Multiple domains',
                    'multi_domain' => true,
                    'import_source' => self::META_IMPORT_SOURCE,
                    'import_version' => self::META_IMPORT_VERSION,
                    'total_articles' => count($validItems),
                    'completed_articles' => count($validItems),
                    'archived_at' => $archivedAt->toIso8601String(),
                ],
            ]);
            $archive->save();

            $position = 0;
            $seoScores = [];
            $synced = 0;
            $keptArticleIds = [];

            foreach ($validItems as $legacyItem) {
                $articleId = (int) $legacyItem->article_id;
                $article = SeoArticle::query()
                    ->with(['site', 'articleMetas', 'seoProfile', 'wordpressLink', 'user'])
                    ->find($articleId);
                if (! $article instanceof SeoArticle) {
                    continue;
                }

                $position++;
                $keptArticleIds[] = $articleId;
                $siteId = (int) ($legacyItem->site_id ?? $article->site_id ?? 0);
                $sourceContent = self::SOURCE_PREFIX.$articleId;
                $sourceKey = $this->sourceKeys->generate(
                    (int) $project->getKey(),
                    SeoProjectTask::TYPE_CREATE,
                    SeoProjectTask::POST_TYPE_ARTICLE,
                    $sourceContent,
                );

                $task = SeoProjectTask::query()
                    ->where('project_id', (int) $project->getKey())
                    ->where('source_key', $sourceKey)
                    ->first();

                if (! $task instanceof SeoProjectTask) {
                    $task = new SeoProjectTask;
                }

                $task->fill([
                    'project_id' => (int) $project->getKey(),
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'article_id' => null,
                    'type' => SeoProjectTask::TYPE_CREATE,
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => $sourceContent,
                    'source_key' => $sourceKey,
                    'keyword' => $this->resolveKeyword($article, $legacyItem),
                    'title' => trim((string) ($article->title ?? '')),
                    'target_date' => $this->resolveTargetDate($legacyItem),
                    'status' => SeoProjectTask::STATUS_PENDING,
                    'completed_at' => null,
                    'connected_at' => $legacyItem->connected_at,
                    'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
                    'archived_at' => null,
                ]);
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
                    $task->publish_queue_status = 'none';
                }
                $task->save();
                $tasksUpserted++;

                $snapshot = $this->buildHistoricalSnapshot($article, $legacyItem, (int) $task->getKey());
                if (($snapshot['seo_score'] ?? null) !== null) {
                    $seoScores[] = (float) $snapshot['seo_score'];
                }
                if ((int) ($snapshot['wordpress_post_id'] ?? 0) > 0) {
                    $synced++;
                }

                SeoProjectArchiveItem::query()->updateOrCreate(
                    [
                        'seo_project_archive_id' => (int) $archive->getKey(),
                        'article_id' => $articleId,
                    ],
                    [
                        'task_id' => (int) $task->getKey(),
                        'position' => $position,
                        'article_snapshot' => $snapshot,
                    ],
                );
                $itemsUpserted++;
            }

            if ($keptArticleIds !== []) {
                SeoProjectArchiveItem::query()
                    ->where('seo_project_archive_id', (int) $archive->getKey())
                    ->whereNotIn('article_id', $keptArticleIds)
                    ->delete();
            } else {
                SeoProjectArchiveItem::query()
                    ->where('seo_project_archive_id', (int) $archive->getKey())
                    ->delete();
            }

            $archive->forceFill([
                'articles_count' => count($keptArticleIds),
                'total_articles' => count($keptArticleIds),
                'completed_articles' => count($keptArticleIds),
                'synced_articles' => $synced,
                'average_seo_score' => $seoScores === []
                    ? null
                    : round(array_sum($seoScores) / count($seoScores), 2),
            ])->save();

            $project->forceFill([
                'total_tasks' => (int) SeoProjectTask::query()
                    ->where('project_id', (int) $project->getKey())
                    ->whereNull('archived_at')
                    ->count(),
            ])->saveQuietly();

            return [
                'project_id' => (int) $project->getKey(),
                'archive_id' => (int) $archive->getKey(),
            ];
        });

        RuntimeLogger::info('content_project_legacy_archive_imported', [
            'project_id' => $result['project_id'],
            'archive_id' => $result['archive_id'],
            'items' => count($validItems),
            'active_cp_memberships' => $activeMemberships,
            'created_project' => $createdProject,
            'created_archive' => $createdArchive,
        ]);

        return array_merge($report, [
            'project_id' => $result['project_id'],
            'archive_id' => $result['archive_id'],
            'created_project' => $createdProject,
            'created_archive' => $createdArchive,
            'tasks_upserted' => $tasksUpserted,
            'items_upserted' => $itemsUpserted,
            'already_imported' => count($validItems),
        ]);
    }

    private function findCurrentArchive(SeoProject $project): ?SeoProjectArchive
    {
        $archive = SeoProjectArchive::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('restored_at')
            ->orderByDesc('id')
            ->first();

        return $archive instanceof SeoProjectArchive ? $archive : null;
    }

    /**
     * @param  list<SeoContentArchiveItem>  $items
     */
    private function resolveProjectArchivedAt(array $items): Carbon
    {
        $latest = null;
        foreach ($items as $item) {
            $at = $item->archived_at;
            if ($at instanceof Carbon && ($latest === null || $at->gt($latest))) {
                $latest = $at->copy();
            }
        }

        return $latest ?? now();
    }

    /**
     * Schema requires seo_projects.month NOT NULL. Import is not month-scoped;
     * store first day of the month of the latest legacy archived_at for determinism.
     *
     * @param  list<SeoContentArchiveItem>  $items
     */
    private function resolveProjectMonth(array $items): string
    {
        return $this->resolveProjectArchivedAt($items)->copy()->startOfMonth()->toDateString();
    }

    /**
     * @param  list<SeoContentArchiveItem>  $items
     */
    private function resolveDefaultActorUserId(array $items): int
    {
        foreach ($items as $item) {
            $id = (int) ($item->archived_by ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        $userId = (int) (User::query()->orderBy('id')->value('id') ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('No actor user available for Legacy archive import.');
        }

        return $userId;
    }

    private function resolveKeyword(SeoArticle $article, SeoContentArchiveItem $legacy): string
    {
        $fromSource = trim((string) ($legacy->source_content ?? ''));
        if ($fromSource !== '' && ! str_starts_with($fromSource, self::SOURCE_PREFIX)) {
            return mb_substr($fromSource, 0, 255);
        }

        $article->loadMissing('articleMetas');
        $meta = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));

        return $meta !== '' ? mb_substr($meta, 0, 255) : mb_substr((string) ($article->title ?? ''), 0, 255);
    }

    private function resolveTargetDate(SeoContentArchiveItem $legacy): string
    {
        $at = $legacy->completed_at ?? $legacy->archived_at ?? now();

        return Carbon::parse($at)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHistoricalSnapshot(
        SeoArticle $article,
        SeoContentArchiveItem $legacy,
        int $taskId,
    ): array {
        $article->loadMissing(['articleMetas', 'site', 'seoProfile', 'wordpressLink', 'user']);

        $siteId = (int) ($legacy->site_id ?? $article->site_id ?? 0);
        $domain = '';
        if ($article->site instanceof Site) {
            $domain = trim((string) ($article->site->domain ?? ''));
        } elseif ($siteId > 0) {
            $domain = trim((string) (Site::query()->whereKey($siteId)->value('domain') ?? ''));
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $syncStatus = trim((string) ($article->wordpressLink?->sync_status ?? ''));
        if ($syncStatus === '') {
            $syncStatus = $wpPostId > 0 ? 'synced' : 'unsynced';
        }

        $cachedPermalink = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));
        $wordpressUrl = $this->permalinkBuilder->resolve(
            $article,
            $cachedPermalink,
            $slug !== '' ? $slug : null,
        );

        $keyword = $this->resolveKeyword($article, $legacy);
        $author = trim((string) ($article->user?->name ?? $article->user?->email ?? ''));

        $completedAt = $legacy->completed_at ?? $legacy->archived_at;
        $archivedAt = $legacy->archived_at ?? $legacy->completed_at;

        return [
            'task_id' => $taskId,
            'article_id' => (int) $article->getKey(),
            'site_id' => $siteId > 0 ? $siteId : null,
            'domain' => $domain,
            'title' => (string) ($article->title ?? ''),
            'slug' => $slug,
            'primary_keyword' => $keyword !== '' ? $keyword : null,
            'author' => $author !== '' ? $author : null,
            'status' => SeoProjectTask::STATUS_PENDING,
            'approved_status' => (string) ($article->review_status ?? ''),
            'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
            'sync_status' => $syncStatus,
            'wordpress_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'wordpress_url' => $wordpressUrl !== '' ? $wordpressUrl : null,
            'created_at' => $this->toIso($article->created_at),
            'updated_at' => $this->toIso($article->updated_at),
            'completed_at' => $this->toIso($completedAt),
            'archived_at' => $this->toIso($archivedAt),
            'connected_at' => $this->toIso($legacy->connected_at),
            'indexed_at' => $this->toIso($article->seoProfile?->indexed_at ?? null),
            'previous_indexed_at' => $this->toIso($article->seoProfile?->previous_indexed_at ?? null),
            'import_source' => self::META_IMPORT_SOURCE,
            'legacy_archive_item_id' => (int) $legacy->getKey(),
        ];
    }

    private function toIso(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
