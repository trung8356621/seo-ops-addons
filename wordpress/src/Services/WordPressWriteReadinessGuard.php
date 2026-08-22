<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use App\Models\Site;

final class WordPressWriteReadinessGuard
{
    /**
     * @param  SeoArticle|Site  $target
     */
    public function assertCanWriteToWordPress(SeoArticle|Site $target, string $operation): void
    {
        $operation = trim($operation);
        if ($this->isReadOnlyOperation($operation) || $this->isExemptFromMediaSlugFix($operation)) {
            return;
        }

        $article = $target instanceof SeoArticle ? ($target->fresh() ?? $target) : null;
        $siteId = $target instanceof Site
            ? (int) $target->getKey()
            : (int) ($article?->site_id ?? 0);

        $pending = $this->pendingLocalSlugFixIds($target);

        if ($pending === []) {
            return;
        }

        throw new WordPressSlugFixRequiredException([
            'operation' => $operation,
            'article_id' => $article instanceof SeoArticle ? (int) $article->getKey() : null,
            'site_id' => $siteId > 0 ? $siteId : null,
            'pending_media_ids' => array_slice($pending, 0, 20),
            'pending_count' => count($pending),
        ]);
    }

    /**
     * Local media IDs that still need deterministic slug normalization before WP writes.
     *
     * @return list<int>
     */
    public function pendingLocalSlugFixIds(SeoArticle|Site $target): array
    {
        if ($target instanceof SeoArticle) {
            $article = $target->fresh() ?? $target;

            return $this->pendingArticleLocalSlugFixes($article);
        }

        return $this->pendingSiteLocalSlugFixes((int) $target->getKey());
    }

    public function slugRequiresLocalFix(string $slug): bool
    {
        return $this->slugRequiresFix($slug);
    }

    public function isAutoFixableLocalMedia(SeoMedia $media): bool
    {
        if (! $this->isLocalWritableMedia($media)) {
            return false;
        }

        // Linked WP attachment without local storage evidence needs human / explicit WP rename.
        $wpAttachmentId = (int) ($media->wp_attachment_id ?? 0);
        if ($wpAttachmentId > 0) {
            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            if (! str_starts_with($path, 'uploads/seo_media/')) {
                return false;
            }
        }

        return true;
    }

    private function isReadOnlyOperation(string $operation): bool
    {
        return in_array($operation, [
            'article.find_post_by_meta',
            'article.get_post',
            'article.find_by_operation_key',
        ], true);
    }

    /**
     * Review sync writes virtual comments only — must not be blocked by media slug-fix
     * readiness that applies to article/media publishing.
     */
    private function isExemptFromMediaSlugFix(string $operation): bool
    {
        return $operation === 'wordpress.product_review.sync';
    }

    /**
     * @return list<int>
     */
    private function pendingArticleLocalSlugFixes(SeoArticle $article): array
    {
        $articleId = (int) $article->getKey();
        $siteId = (int) ($article->site_id ?? 0);
        if ($articleId <= 0) {
            return [];
        }

        $rows = SeoMedia::query()
            ->when($siteId > 0, static function ($query) use ($siteId): void {
                $query->where(function ($siteQuery) use ($siteId): void {
                    $siteQuery->where('site_id', $siteId)->orWhereNull('site_id');
                });
            })
            ->limit(500)
            ->get();

        $mediaIds = array_fill_keys($this->extractSeoMediaIds((string) ($article->body ?? '')), true);
        $rows = $rows->filter(static function (SeoMedia $media) use ($articleId, $mediaIds): bool {
            if (isset($mediaIds[(int) $media->getKey()])) {
                return true;
            }

            return in_array($articleId, SeoMedia::normalizeArticleIds($media->article_id), true)
                || (int) ($media->primary_article_id ?? 0) === $articleId;
        });

        return $this->pendingMediaIds($rows);
    }

    /**
     * @return list<int>
     */
    private function pendingSiteLocalSlugFixes(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $rows = SeoMedia::query()
            ->where('site_id', $siteId)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '')
                    ->orWhere('status', 'completed')
                    ->orWhere('status', 'processing')
                    ->orWhere('status', 'failed');
            })
            ->limit(500)
            ->get();

        return $this->pendingMediaIds($rows);
    }

    /**
     * @param  iterable<SeoMedia>  $rows
     * @return list<int>
     */
    private function pendingMediaIds(iterable $rows): array
    {
        $pending = [];
        foreach ($rows as $media) {
            if (! $media instanceof SeoMedia || ! $this->isLocalWritableMedia($media)) {
                continue;
            }

            $slug = trim((string) ($media->slug ?? ''));
            if ($slug === '') {
                $path = trim((string) ($media->path ?? $media->url ?? ''));
                $slug = pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_FILENAME);
            }

            if ($this->slugRequiresFix($slug)) {
                $pending[] = (int) $media->getKey();
            }
        }

        return array_values(array_unique(array_filter($pending)));
    }

    private function isLocalWritableMedia(SeoMedia $media): bool
    {
        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        $url = trim((string) ($media->url ?? ''));

        return str_starts_with($path, 'uploads/seo_media/')
            || str_contains($url, '/storage/uploads/seo_media/')
            || in_array(strtolower(trim((string) ($media->source ?? ''))), [
                'local',
                'uploaded',
                'generated',
                'ai_prompt',
                'storage_adopt',
                'clipboard',
                'url_import',
            ], true);
    }

    private function slugRequiresFix(string $slug): bool
    {
        $slug = trim($slug);

        return $slug === ''
            || preg_match('/^(image|img|photo|untitled|download|dsc|img_)[-_]?\d*$/i', $slug) === 1
            || preg_match('/^(paste|clipboard|import)-[a-f0-9]{8,}$/i', $slug) === 1
            || preg_match('/placeholder/i', $slug) === 1;
    }

    /**
     * @return list<int>
     */
    private function extractSeoMediaIds(string $html): array
    {
        if ($html === '' || preg_match_all('/\bdata-seo-media-id\s*=\s*["\']?(\d+)["\']?/i', $html, $matches) !== 1) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $matches[1] ?? [],
        ), static fn (int $id): bool => $id > 0)));
    }
}
