<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners;

use Omnichannel\Addons\Media\Models\SeoGeneratedImage;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn toàn bộ local media Laravel của article trong archived Content Project.
 * WordPress attachment/post đã là nguồn giữ nội dung sau publish/sync.
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

        $localMedia = SeoMedia::query()
            ->whereIn('article_id', $articleIds)
            ->get(['id', 'path']);

        $mediaIds = [];
        foreach ($localMedia as $media) {
            $mediaIds[] = (int) $media->id;
            $context->queueDiskPath((string) ($media->path ?? ''));
        }

        if ($mediaIds !== []) {
            $deletedHistory = SeoMediaProcessingHistory::query()
                ->whereIn('media_ref_id', $mediaIds)
                ->delete();
            $context->bumpStat('media_processing_histories_deleted', (int) $deletedHistory);

            $deletedMedia = SeoMedia::query()->whereIn('id', $mediaIds)->delete();
            $context->bumpStat('local_media_deleted', (int) $deletedMedia);
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_generated_images')) {
            $deletedLegacy = SeoGeneratedImage::query()
                ->whereIn('article_id', $articleIds)
                ->delete();
            $context->bumpStat('generated_images_deleted', (int) $deletedLegacy);
        }
    }
}
