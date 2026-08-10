<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Http\Controllers;

use Omnichannel\Addons\Media\Services\MediaLibraryAccessScope;
use Omnichannel\Addons\Media\Services\MediaLibraryArticleResolver;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryService;
use Omnichannel\Addons\WordPress\Services\WordPressMediaLibraryService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceMediaPickerController extends Controller
{
    public function __invoke(
        Request $request,
        SeoMediaLibraryService $localLibrary,
        WordPressMediaLibraryService $wordPressLibrary,
        MediaLibraryArticleResolver $articleResolver,
    ): JsonResponse {
        $siteId = SeoAccessControl::globalSiteId();
        if ($siteId === null || $siteId <= 0) {
            return response()->json([
                'images' => [],
                'catalog' => null,
                'page' => 1,
                'totalPages' => 1,
                'error' => 'Chọn domain trước khi mở thư viện ảnh.',
                'tab' => $request->string('tab')->toString(),
            ], 422);
        }

        $site = Site::query()->findOrFail($siteId);
        $tab = $request->string('tab')->toString() === 'local' ? 'local' : 'original';
        $page = max(1, $request->integer('page', 1));
        $search = trim($request->string('search')->toString());
        $accessScope = app(MediaLibraryAccessScope::class);
        $restrictArticleIds = $accessScope->restrictedArticleIdsForSite((int) $site->id);
        $restrictWpAttachmentIds = $accessScope->pickerWordPressAttachmentRestrictions((int) $site->id, $search);

        if ($tab === 'local') {
            $result = $localLibrary->fetch(
                $site,
                null,
                $page,
                $search !== '' ? $search : null,
                28,
                restrictToArticleIds: $restrictArticleIds,
            );
            $images = $articleResolver->enrichImages(
                (int) $site->id,
                is_array($result['images'] ?? null) ? $result['images'] : [],
            );
        } else {
            $result = $wordPressLibrary->fetch(
                $site,
                null,
                $page,
                28,
                $search !== '' ? $search : null,
                includeAttachmentIds: $restrictWpAttachmentIds,
            );
            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
        }

        return response()->json([
            'images' => $this->normalizeImages($images, $tab),
            'catalog' => null,
            'page' => max(1, (int) ($result['page'] ?? $page)),
            'totalPages' => max(1, (int) ($result['total_pages'] ?? 1)),
            'error' => filled($result['error'] ?? null) ? (string) $result['error'] : null,
            'tab' => $tab,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function normalizeImages(array $images, string $tab): array
    {
        return array_values(array_map(static function (array $image) use ($tab): array {
            $wpId = (int) ($image['wp_attachment_id'] ?? ($tab === 'original' ? ($image['id'] ?? 0) : 0));
            $seoId = (int) ($image['seo_media_id'] ?? ($tab === 'local' ? ($image['id'] ?? 0) : 0));
            $url = trim((string) ($image['url'] ?? ''));
            $thumbUrl = trim((string) ($image['thumb_url'] ?? $url));

            return [
                'picker_key' => $tab.'-'.($seoId > 0 ? 'seo-'.$seoId : 'wp-'.$wpId).'-'.md5($url),
                'id' => (int) ($image['id'] ?? ($wpId > 0 ? $wpId : $seoId)),
                'wp_attachment_id' => $wpId,
                'seo_media_id' => $seoId,
                'url' => $url,
                'thumb_url' => $thumbUrl !== '' ? $thumbUrl : $url,
                'slug' => trim((string) ($image['slug'] ?? '')),
                'alt' => trim((string) ($image['alt'] ?? '')),
                'media_type' => strtolower(trim((string) ($image['media_type'] ?? 'image'))) === 'video'
                    ? 'video'
                    : 'image',
            ];
        }, $images));
    }
}
