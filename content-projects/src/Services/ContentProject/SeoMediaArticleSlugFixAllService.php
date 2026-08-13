<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use Illuminate\Support\Str;

/**
 * Local-only "fix slug all" parity with editor quick-fix (no WordPress rename).
 */
final class SeoMediaArticleSlugFixAllService
{
    public function __construct(
        private readonly SeoMediaArticleSlugFixService $slugFix,
        private readonly SeoMediaUrlReplacementService $urlReplacement,
    ) {}

    /**
     * @return array{
     *     skipped: bool,
     *     success: bool,
     *     message: string,
     *     applied: int,
     *     replacements: list<array<string, mixed>>
     * }
     */
    public function fixAllForArticle(SeoArticle $article): array
    {
        $images = $this->collectLocalImages($article);
        if ($images === []) {
            return [
                'skipped' => true,
                'success' => true,
                'message' => 'Không có ảnh local cần chuẩn hóa.',
                'applied' => 0,
                'replacements' => [],
            ];
        }

        $keyword = $this->resolveKeyword($article);
        if ($keyword === '') {
            return [
                'skipped' => true,
                'success' => true,
                'message' => 'Không có từ khóa để chuẩn hóa slug ảnh.',
                'applied' => 0,
                'replacements' => [],
            ];
        }

        $items = [];
        $ordinal = 0;
        $usedSlugs = [];

        foreach ($images as $image) {
            $ordinal++;
            $targetSlug = $this->imageSlugFromKeyword($keyword, $ordinal);
            if ($targetSlug === '') {
                continue;
            }

            while (isset($usedSlugs[$targetSlug])) {
                $targetSlug = $this->imageSlugFromKeyword($keyword, $ordinal).'-'.count($usedSlugs);
            }

            $currentSlug = Str::slug((string) ($image['old_slug'] ?? ''));
            if ($currentSlug === $targetSlug) {
                $usedSlugs[$targetSlug] = true;

                continue;
            }

            $usedSlugs[$targetSlug] = true;
            $items[] = [
                'seo_media_id' => $image['seo_media_id'],
                'url' => $image['url'],
                'new_slug' => $targetSlug,
                'old_slug' => (string) ($image['old_slug'] ?? ''),
            ];
        }

        if ($items === []) {
            return [
                'skipped' => false,
                'success' => true,
                'message' => 'Slug ảnh đã chuẩn.',
                'applied' => 0,
                'replacements' => [],
            ];
        }

        $result = $this->slugFix->fixSlugs($article, $items);

        return [
            'skipped' => false,
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'applied' => count($result['replacements'] ?? []),
            'replacements' => is_array($result['replacements'] ?? null) ? $result['replacements'] : [],
        ];
    }

    public function articleHasLocalImages(SeoArticle $article): bool
    {
        $body = (string) ($article->body ?? '');
        if ($body === '' || ! preg_match_all('/<img[^>]+>/iu', $body, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            $url = $this->extractAttribute($tag, 'src');
            if ($url === '') {
                continue;
            }

            if ($this->urlReplacement->storagePathFromUrl($url) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic local-media slug normalization for publishing preflight.
     * Reuses SeoMediaArticleSlugFixService — covers readiness-pending IDs even when
     * the media is article-linked but not currently rendered as a body <img>.
     *
     * @param  list<int>  $pendingMediaIds
     * @return array{
     *     skipped: bool,
     *     success: bool,
     *     message: string,
     *     applied: int,
     *     replacements: list<array<string, mixed>>,
     *     not_auto_fixable_ids: list<int>,
     *     failed_ids: list<int>
     * }
     */
    public function fixPendingMediaForPublish(SeoArticle $article, array $pendingMediaIds): array
    {
        $pendingMediaIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pendingMediaIds,
        ), static fn (int $id): bool => $id > 0)));

        if ($pendingMediaIds === []) {
            return [
                'skipped' => true,
                'success' => true,
                'message' => 'Không có media pending cần chuẩn hóa.',
                'applied' => 0,
                'replacements' => [],
                'not_auto_fixable_ids' => [],
                'failed_ids' => [],
            ];
        }

        $keyword = $this->resolveKeyword($article);
        if ($keyword === '') {
            return [
                'skipped' => false,
                'success' => false,
                'message' => 'Không có từ khóa/tiêu đề để chuẩn hóa slug ảnh trước khi xuất bản.',
                'applied' => 0,
                'replacements' => [],
                'not_auto_fixable_ids' => $pendingMediaIds,
                'failed_ids' => $pendingMediaIds,
            ];
        }

        $usedSlugs = $this->collectUsedSlugs($article, $pendingMediaIds);
        $items = [];
        $notAutoFixable = [];
        $ordinal = 0;

        foreach ($pendingMediaIds as $mediaId) {
            $media = SeoMedia::query()->find($mediaId);
            if (! $media instanceof SeoMedia) {
                $notAutoFixable[] = $mediaId;
                continue;
            }

            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            $isLocalPath = str_starts_with($path, 'uploads/seo_media/');
            $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
            if (! $isLocalPath || ($wpAttachmentId > 0 && ! $isLocalPath)) {
                $notAutoFixable[] = $mediaId;
                continue;
            }

            $ordinal++;
            $targetSlug = $this->allocateUniqueSlug($keyword, $ordinal, $usedSlugs);
            if ($targetSlug === '') {
                $notAutoFixable[] = $mediaId;
                continue;
            }

            $oldSlug = trim((string) ($media->slug ?? ''));
            if ($oldSlug === '') {
                $oldSlug = pathinfo($path, PATHINFO_FILENAME);
            }

            $usedSlugs[$targetSlug] = true;
            $items[] = [
                'seo_media_id' => $mediaId,
                'url' => (string) ($media->url ?: '/storage/'.$path),
                'new_slug' => $targetSlug,
                'old_slug' => $oldSlug,
            ];
        }

        if ($items === []) {
            return [
                'skipped' => false,
                'success' => false,
                'message' => 'Media pending không thể chuẩn hóa tự động (cần xử lý thủ công).',
                'applied' => 0,
                'replacements' => [],
                'not_auto_fixable_ids' => array_values(array_unique($notAutoFixable)),
                'failed_ids' => array_values(array_unique($notAutoFixable)),
            ];
        }

        $result = $this->slugFix->fixSlugs($article, $items, [
            'system_publish_preflight' => true,
        ]);
        $failedFromSkip = [];
        foreach (is_array($result['skipped'] ?? null) ? $result['skipped'] : [] as $skip) {
            if (! is_array($skip)) {
                continue;
            }
            $id = (int) ($skip['seo_media_id'] ?? 0);
            if ($id > 0) {
                $failedFromSkip[] = $id;
                if (($skip['reason'] ?? '') === 'wordpress_media_requires_explicit_rename') {
                    $notAutoFixable[] = $id;
                }
            }
        }

        $success = (bool) ($result['success'] ?? false) && $failedFromSkip === [] && $notAutoFixable === [];

        return [
            'skipped' => false,
            'success' => $success,
            'message' => (string) ($result['message'] ?? ($success
                ? 'Đã chuẩn hóa slug media local trước khi xuất bản.'
                : 'Chuẩn hóa slug media không hoàn tất.')),
            'applied' => count($result['replacements'] ?? []),
            'replacements' => is_array($result['replacements'] ?? null) ? $result['replacements'] : [],
            'not_auto_fixable_ids' => array_values(array_unique($notAutoFixable)),
            'failed_ids' => array_values(array_unique(array_merge($failedFromSkip, $notAutoFixable))),
        ];
    }

    /**
     * @param  list<int>  $pendingMediaIds
     * @return array<string, true>
     */
    private function collectUsedSlugs(SeoArticle $article, array $pendingMediaIds): array
    {
        $used = [];
        foreach ($this->collectLocalImages($article) as $image) {
            $slug = Str::slug((string) ($image['old_slug'] ?? ''));
            if ($slug !== '') {
                $used[$slug] = true;
            }
        }

        $articleId = (int) $article->getKey();
        $siteId = (int) ($article->site_id ?? 0);
        $linked = SeoMedia::query()
            ->when($siteId > 0, static function ($query) use ($siteId): void {
                $query->where(function ($siteQuery) use ($siteId): void {
                    $siteQuery->where('site_id', $siteId)->orWhereNull('site_id');
                });
            })
            ->limit(500)
            ->get();

        foreach ($linked as $media) {
            if (! $media instanceof SeoMedia) {
                continue;
            }
            $id = (int) $media->getKey();
            if (in_array($id, $pendingMediaIds, true)) {
                continue;
            }
            $linkedToArticle = in_array($articleId, SeoMedia::normalizeArticleIds($media->article_id), true)
                || (int) ($media->primary_article_id ?? 0) === $articleId;
            if (! $linkedToArticle) {
                continue;
            }
            $slug = Str::slug(trim((string) ($media->slug ?? '')));
            if ($slug !== '') {
                $used[$slug] = true;
            }
        }

        return $used;
    }

    /**
     * @param  array<string, true>  $usedSlugs
     */
    private function allocateUniqueSlug(string $keyword, int $ordinal, array &$usedSlugs): string
    {
        $candidate = $this->imageSlugFromKeyword($keyword, max(1, $ordinal));
        if ($candidate === '') {
            return '';
        }

        $n = 0;
        $slug = $candidate;
        while (isset($usedSlugs[$slug])) {
            $n++;
            $slug = $candidate.'-'.$n;
        }

        return $slug;
    }

    /**
     * @return list<array{seo_media_id: int|null, url: string, old_slug: string}>
     */
    private function collectLocalImages(SeoArticle $article): array
    {
        $body = (string) ($article->body ?? '');
        if ($body === '') {
            return [];
        }

        if (! preg_match_all('/<img[^>]+>/iu', $body, $matches)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach ($matches[0] as $tag) {
            $url = $this->extractAttribute($tag, 'src');
            if ($url === '') {
                continue;
            }

            $path = $this->urlReplacement->storagePathFromUrl($url);
            if ($path === '') {
                continue;
            }

            $mediaId = (int) ($this->extractAttribute($tag, 'data-seo-media-id') ?: 0);
            $key = $mediaId > 0 ? 'id:'.$mediaId : 'url:'.$url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $media = $this->resolveLocalMedia($article, $mediaId, $path);
            $oldSlug = $media instanceof SeoMedia
                ? trim((string) ($media->slug ?? ''))
                : Str::slug(pathinfo($path, PATHINFO_FILENAME));

            $out[] = [
                'seo_media_id' => $media instanceof SeoMedia
                    ? (int) $media->id
                    : ($mediaId > 0 ? $mediaId : null),
                'url' => $url,
                'old_slug' => $oldSlug,
            ];
        }

        return $out;
    }

    private function resolveLocalMedia(SeoArticle $article, int $mediaId, string $path): ?SeoMedia
    {
        try {
            if ($mediaId > 0) {
                $media = SeoMedia::query()->find($mediaId);
                if ($media instanceof SeoMedia) {
                    return $media;
                }
            }

            $siteId = (int) ($article->site_id ?? 0);
            $query = SeoMedia::query()->where('path', $path);
            if ($siteId > 0) {
                $query->where(function ($q) use ($siteId): void {
                    $q->where('site_id', $siteId)->orWhereNull('site_id');
                });
            }

            $media = $query->first();

            return $media instanceof SeoMedia ? $media : null;
        } catch (\Throwable) {
            // Unit tests / missing DB resolver — fall back to path-derived slug.
            return null;
        }
    }

    private function resolveKeyword(SeoArticle $article): string
    {
        if ($article->relationLoaded('articleMetas')) {
            $fromMeta = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
            if ($fromMeta !== '') {
                return $fromMeta;
            }

            return trim((string) ($article->title ?? ''));
        }

        try {
            $article->loadMissing('articleMetas');
            $fromMeta = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
            if ($fromMeta !== '') {
                return $fromMeta;
            }
        } catch (\Throwable) {
            // Ignore — use title fallback.
        }

        return trim((string) ($article->title ?? ''));
    }

    public function keywordToImageSlugBase(string $keyword): string
    {
        $text = trim(mb_strtolower($keyword));
        if ($text === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $text = (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized);
            }
        }

        $text = str_replace('đ', 'd', $text);
        $text = (string) preg_replace('/[^a-z0-9\s-]/u', ' ', $text);
        $text = trim(preg_replace('/\s+/u', '-', $text) ?? '');
        $text = (string) preg_replace('/-+/', '-', $text);

        return trim($text, '-');
    }

    public function imageSlugFromKeyword(string $keyword, int $index): string
    {
        $base = $this->keywordToImageSlugBase($keyword);
        if ($base === '') {
            return '';
        }

        if ($index < 1) {
            return $base;
        }

        return $base.'-'.$index;
    }

    private function extractAttribute(string $tag, string $name): string
    {
        $pattern = '/'.preg_quote($name, '/').'\s*=\s*("|\')(.*?)\1/iu';
        if (preg_match($pattern, $tag, $match) !== 1) {
            return '';
        }

        return trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
