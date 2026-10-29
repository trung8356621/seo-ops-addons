<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Media\Models\ArticleMediaState;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaMeta;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn local AI Workspace media của Content Project đang Archive.
 *
 * Ownership SoT:
 * - seo_media (+ seo_media_meta.article_id) — local/AI library rows
 * - article_media_states — featured projection (display_url may be WP CDN)
 *
 * Không xóa: WordPress attachment mirrors, shared media, media cần giữ cho article chưa sync WP.
 */
final class LocalMediaWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'local_media';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $articleIds = $context->articleIds();
        $articleIdSet = [];
        foreach ($articleIds as $articleId) {
            if ($articleId > 0) {
                $articleIdSet[$articleId] = true;
            }
        }
        if ($articleIdSet === []) {
            return;
        }

        $candidates = $this->collectCandidateMedia(array_keys($articleIdSet));
        if ($candidates->isEmpty()) {
            $this->nullOrphanStateMediaIds(array_keys($articleIdSet), []);
            $context->bumpStat('local_media_deleted', 0);

            return;
        }

        $unsyncedArticleIds = $this->unsyncedArticleIds(array_keys($articleIdSet));
        $statesByMediaId = $this->featuredStatesByMediaId(array_keys($articleIdSet));

        $deleteIds = [];
        $detached = 0;

        foreach ($candidates as $media) {
            if (! $media instanceof SeoMedia) {
                continue;
            }

            $mediaId = (int) $media->getKey();
            if ($mediaId <= 0) {
                continue;
            }

            $linkedArticleIds = SeoMedia::normalizeArticleIds($media->article_id);
            $outsideArticleIds = [];
            $insideArticleIds = [];
            foreach ($linkedArticleIds as $linkedId) {
                if (isset($articleIdSet[$linkedId])) {
                    $insideArticleIds[] = $linkedId;
                } else {
                    $outsideArticleIds[] = $linkedId;
                }
            }

            // Shared with articles outside this cleanup scope — detach only.
            if ($outsideArticleIds !== []) {
                $media->article_id = $outsideArticleIds;
                $media->save();
                $detached++;
                $context->bumpStat('local_media_shared_detached', 1);

                continue;
            }

            // WordPress-synced mirror — keep library row; detach article workspace link.
            if ((int) ($media->wp_attachment_id ?? 0) > 0) {
                if ($insideArticleIds !== []) {
                    $media->article_id = null;
                    $media->save();
                    $detached++;
                    $context->bumpStat('local_media_wp_mirror_detached', 1);
                }

                continue;
            }

            // Unsynced article whose featured state depends on this local file with no WP URL.
            if ($this->mustKeepForUnsyncedFeatured(
                $mediaId,
                $insideArticleIds !== [] ? $insideArticleIds : array_keys($articleIdSet),
                $unsyncedArticleIds,
                $statesByMediaId,
            )) {
                $context->bumpStat('local_media_kept_unsynced_featured', 1);

                continue;
            }

            if (! $this->isDeletableWorkspaceMedia($media)) {
                // Conservative: unknown business local media — detach article link only.
                if ($insideArticleIds !== [] || $linkedArticleIds !== []) {
                    $media->article_id = null;
                    $media->save();
                    $detached++;
                    $context->bumpStat('local_media_unknown_detached', 1);
                }

                continue;
            }

            $deleteIds[] = $mediaId;
            $path = trim((string) ($media->path ?? ''));
            if ($path !== '') {
                $context->queueDiskPath($path);
            }
        }

        $this->nullOrphanStateMediaIds(array_keys($articleIdSet), $deleteIds);

        if ($deleteIds === []) {
            $context->bumpStat('local_media_deleted', 0);
            $context->bumpStat('local_media_article_links_detached', $detached);

            return;
        }

        $deleteIds = array_values(array_unique($deleteIds));

        $deletedHistory = SeoMediaProcessingHistory::query()
            ->whereIn('media_ref_id', $deleteIds)
            ->delete();
        $context->bumpStat('media_processing_histories_deleted', (int) $deletedHistory);

        if (Schema::connection('omi_seo_ai')->hasTable('seo_media_meta')) {
            SeoMediaMeta::query()->whereIn('media_id', $deleteIds)->delete();
        }

        $deletedMedia = SeoMedia::query()->whereIn('id', $deleteIds)->delete();
        $context->bumpStat('local_media_deleted', (int) $deletedMedia);
        $context->bumpStat('local_media_article_links_detached', $detached);
    }

    /**
     * @param  list<int>  $articleIds
     * @return Collection<int, SeoMedia>
     */
    private function collectCandidateMedia(array $articleIds): Collection
    {
        /** @var Collection<int, SeoMedia> $byArticle */
        $byArticle = SeoMedia::query()
            ->whereIn('article_id', $articleIds)
            ->get();

        $byId = collect();
        if (Schema::connection('omi_seo_ai')->hasTable('article_media_states')) {
            $stateMediaIds = ArticleMediaState::query()
                ->whereIn('article_id', $articleIds)
                ->whereNotNull('media_id')
                ->where('media_id', '>', 0)
                ->pluck('media_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($stateMediaIds !== []) {
                $known = $byArticle->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $missing = array_values(array_diff($stateMediaIds, $known));
                if ($missing !== []) {
                    $byId = SeoMedia::query()->whereIn('id', $missing)->get();
                }
            }
        }

        return $byArticle->concat($byId)->unique(static fn (SeoMedia $m): int => (int) $m->getKey())->values();
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, true>
     */
    private function unsyncedArticleIds(array $articleIds): array
    {
        if ($articleIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            $out = [];
            foreach ($articleIds as $id) {
                $out[$id] = true;
            }

            return $out;
        }

        $synced = WordpressArticleLink::query()
            ->whereIn('article_id', $articleIds)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->pluck('article_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $syncedSet = array_fill_keys($synced, true);

        $out = [];
        foreach ($articleIds as $id) {
            if (! isset($syncedSet[$id])) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, list<ArticleMediaState>>
     */
    private function featuredStatesByMediaId(array $articleIds): array
    {
        if ($articleIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('article_media_states')) {
            return [];
        }

        $map = [];
        $rows = ArticleMediaState::query()
            ->whereIn('article_id', $articleIds)
            ->whereNotNull('media_id')
            ->where('media_id', '>', 0)
            ->get();

        foreach ($rows as $row) {
            $mediaId = (int) ($row->media_id ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            $map[$mediaId][] = $row;
        }

        return $map;
    }

    /**
     * @param  list<int>  $insideArticleIds
     * @param  array<int, true>  $unsyncedArticleIds
     * @param  array<int, list<ArticleMediaState>>  $statesByMediaId
     */
    private function mustKeepForUnsyncedFeatured(
        int $mediaId,
        array $insideArticleIds,
        array $unsyncedArticleIds,
        array $statesByMediaId,
    ): bool {
        foreach ($statesByMediaId[$mediaId] ?? [] as $state) {
            $articleId = (int) ($state->article_id ?? 0);
            if ($articleId <= 0 || ! isset($unsyncedArticleIds[$articleId])) {
                continue;
            }
            if (! in_array($articleId, $insideArticleIds, true) && $insideArticleIds !== []) {
                continue;
            }

            $displayUrl = trim((string) ($state->display_url ?? ''));
            if ($displayUrl === '') {
                return true;
            }
        }

        return false;
    }

    private function isDeletableWorkspaceMedia(SeoMedia $media): bool
    {
        if ((int) ($media->wp_attachment_id ?? 0) > 0) {
            return false;
        }

        $source = strtolower(trim((string) ($media->source ?? '')));
        if (in_array($source, [
            'ai_prompt',
            'ai_video_prompt',
            'local',
            'generated',
            'editor_local',
            'seo_media',
            '',
        ], true)) {
            return true;
        }

        if (trim((string) ($media->ai_generator ?? '')) !== '') {
            return true;
        }

        if ((int) ($media->prompt_id ?? 0) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Keep featured display_url; clear dangling media_id after delete or when row already gone.
     *
     * @param  list<int>  $articleIds
     * @param  list<int>  $deletedMediaIds
     */
    private function nullOrphanStateMediaIds(array $articleIds, array $deletedMediaIds): void
    {
        if ($articleIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('article_media_states')) {
            return;
        }

        $deletedSet = array_fill_keys($deletedMediaIds, true);
        $states = ArticleMediaState::query()
            ->whereIn('article_id', $articleIds)
            ->whereNotNull('media_id')
            ->where('media_id', '>', 0)
            ->get(['id', 'media_id', 'display_url']);

        if ($states->isEmpty()) {
            return;
        }

        $referencedIds = $states->pluck('media_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $existing = $referencedIds === []
            ? []
            : SeoMedia::query()->whereIn('id', $referencedIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $existingSet = array_fill_keys($existing, true);

        $orphanStateIds = [];
        foreach ($states as $state) {
            $mediaId = (int) ($state->media_id ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            if (isset($deletedSet[$mediaId]) || ! isset($existingSet[$mediaId])) {
                // Always clear dangling FK; display_url (incl. WP CDN) stays intact.
                $orphanStateIds[] = (int) $state->id;
            }
        }

        if ($orphanStateIds !== []) {
            ArticleMediaState::query()
                ->whereIn('id', $orphanStateIds)
                ->update(['media_id' => null]);
        }
    }
}
