<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryChildAttempt;
use Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryArtifactRole;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryCanaryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discard generated canary gallery artifacts — never delete originals / article / project.
 */
final class ProductGalleryCanaryCleanupService
{
    public function __construct(
        private readonly ArticleMediaLocalService $album,
    ) {}

    /**
     * @return array{
     *     discarded_media_ids: list<int>,
     *     archived_executions: int,
     *     original_media_ids: list<int>
     * }
     */
    public function discardGenerated(SeoArticle $article): array
    {
        if (! ProductGalleryCanaryAccess::isCanaryArticle($article)) {
            throw ValidationException::withMessages([
                'article' => 'Cleanup only allowed on is_canary product gallery fixtures.',
            ]);
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($article): array {
            $album = $this->album->resolveProductAlbum($article);
            $originalIds = [];
            $discarded = [];

            $kept = [];
            foreach ($album as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $media = SeoMedia::query()->find($id);
                $role = '';
                if ($media instanceof SeoMedia) {
                    $vars = is_array($media->prompt_variables) ? $media->prompt_variables : [];
                    $role = (string) ($vars[ProductGalleryArtifactRole::KEY] ?? '');
                }

                if (ProductGalleryArtifactRole::isKnown($role) && $role !== ProductGalleryArtifactRole::ORIGINAL) {
                    $discarded[] = $id;
                    if ($media instanceof SeoMedia) {
                        $vars = is_array($media->prompt_variables) ? $media->prompt_variables : [];
                        $vars['canary_discarded'] = true;
                        $vars['canary_discarded_at'] = now()->toIso8601String();
                        $media->forceFill([
                            'prompt_variables' => $vars,
                            'status' => 'discarded',
                        ])->save();
                    }

                    continue;
                }

                $originalIds[] = $id;
                $kept[] = $item;
            }

            $this->album->saveProductAlbumLocal($article, $kept);

            $archived = 0;
            $executions = SeoProductGalleryExecution::query()
                ->where('article_id', (int) $article->id)
                ->get();
            foreach ($executions as $execution) {
                if ((string) ($execution->status ?? '') === 'archived_canary') {
                    continue;
                }
                $execution->update([
                    'status' => 'archived_canary',
                    'failure_reason' => trim((string) ($execution->failure_reason ?? '')).'|canary_discarded',
                    'completed_at' => $execution->completed_at ?? now(),
                ]);
                SeoProductGalleryChildAttempt::query()
                    ->where('parent_execution_id', (int) $execution->id)
                    ->update(['status' => 'archived_canary']);
                $archived++;
            }

            return [
                'discarded_media_ids' => $discarded,
                'archived_executions' => $archived,
                'original_media_ids' => $originalIds,
            ];
        });
    }
}
