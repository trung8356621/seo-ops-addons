<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves heterogeneous source inputs (text, url with preview, existing WP/SEO metadata)
 * into a single concise plain text context before calling Social AI.
 *
 * The AI task itself must NOT crawl pages, fetch remote URLs, or know WordPress internals.
 */
final class SeedingSocialContextResolver
{
    /**
     * Resolves raw payload into short plain text context.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array $payload): string
    {
        $content = trim((string) ($payload['content'] ?? $payload['full_text'] ?? ''));
        $url = trim((string) ($payload['url'] ?? $payload['social_url'] ?? ''));
        $sourceType = $this->determineCanonicalSourceType($payload, $content, $url);

        if ($sourceType === 'text') {
            if ($content === '') {
                throw new InvalidArgumentException('Thiếu nội dung gốc để tạo bình luận.');
            }

            return $content;
        }

        return $this->resolveUrlContext($url, $payload);
    }

    /**
     * Determines canonical source_type ('text' or 'url') at the boundary.
     * Prevents invalid values (e.g. 'threads', 'manual', 'comment') from leaking.
     *
     * @param  array<string, mixed>  $payload
     */
    public function determineCanonicalSourceType(array $payload, string $content = '', string $url = ''): string
    {
        if ($content === '') {
            $content = trim((string) ($payload['content'] ?? $payload['full_text'] ?? ''));
        }
        if ($url === '') {
            $url = trim((string) ($payload['url'] ?? $payload['social_url'] ?? ''));
        }

        $raw = strtolower(trim((string) ($payload['source_type'] ?? '')));

        // Known social platform strings should NOT be interpreted as source_type
        $knownPlatforms = ['threads', 'facebook', 'instagram', 'tiktok', 'twitter', 'x', 'youtube', 'linkedin'];
        if (in_array($raw, $knownPlatforms, true)) {
            $raw = '';
        }

        // 'manual', 'comment', 'share', 'topic', 'other' are UI/topic metadata, not source_type
        if (in_array($raw, ['manual', 'comment', 'share', 'topic', 'other'], true)) {
            $raw = '';
        }

        if ($raw === 'url') {
            return 'url';
        }

        if ($raw === 'text') {
            return 'text';
        }

        // Infer from actual payload presence:
        // If content is present, treat as text
        if ($content !== '') {
            return 'text';
        }

        // If only URL is present, treat as url
        if ($url !== '') {
            return 'url';
        }

        throw new InvalidArgumentException('Thiếu nội dung gốc hoặc liên kết để tạo bình luận.');
    }

    /**
     * Resolves short plain text context for a URL source without web crawling.
     *
     * Preferred lookup priority:
     * 1. Existing metadata attached to the Seeding topic / payload preview
     * 2. Existing seo_site_link_catalog matched by URL
     * 3. Existing article metadata matched by wp_permalink
     * 4. Controlled validation error if metadata cannot be found
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveUrlContext(string $url, array $payload): string
    {
        if ($url === '') {
            throw new InvalidArgumentException('Thiếu liên kết để tạo bình luận.');
        }

        // Priority 1: Payload / topic card preview metadata
        $title = trim((string) ($payload['title'] ?? $payload['preview_title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? $payload['preview_description'] ?? ''));

        // Priority 2: Existing seo_site_link_catalog database record
        if ($title === '' || $description === '') {
            $fromCatalog = $this->lookupSiteLinkCatalog($url);
            if ($fromCatalog !== null) {
                if ($title === '' && ! empty($fromCatalog['title'])) {
                    $title = trim((string) $fromCatalog['title']);
                }
                if ($description === '' && ! empty($fromCatalog['description'])) {
                    $description = trim((string) $fromCatalog['description']);
                }
            }
        }

        // Priority 3: Existing article metadata matched by wp_permalink (title + meta description only)
        if ($title === '' || $description === '') {
            $fromArticle = $this->lookupArticleByPermalink($url);
            if ($fromArticle !== null) {
                if ($title === '' && ! empty($fromArticle['title'])) {
                    $title = trim((string) $fromArticle['title']);
                }
                if ($description === '' && ! empty($fromArticle['description'])) {
                    $description = trim((string) $fromArticle['description']);
                }
            }
        }

        // Priority 4: Controlled validation error if metadata cannot be found
        if ($title === '' && $description === '') {
            throw new InvalidArgumentException(
                'Không tìm thấy tiêu đề hoặc mô tả cho liên kết này. Vui lòng nhập nội dung gợi ý.'
            );
        }

        $lines = [];
        if ($title !== '') {
            $lines[] = "Tiêu đề: {$title}";
        }
        if ($description !== '') {
            $lines[] = "Mô tả: {$description}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{title: string|null, description: string|null}|null
     */
    private function lookupSiteLinkCatalog(string $url): ?array
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return null;
            }

            $connection = 'omi_seo_ai';
            if (! Schema::connection($connection)->hasTable('seo_site_link_catalog')) {
                return null;
            }

            $row = DB::connection($connection)
                ->table('seo_site_link_catalog')
                ->where('url', $url)
                ->orWhere('canonical', $url)
                ->first(['title', 'meta']);

            if ($row !== null) {
                $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) ($row->meta ?? []);
                $description = is_array($meta)
                    ? ($meta['description'] ?? $meta['excerpt'] ?? null)
                    : null;

                return [
                    'title' => $row->title ?? null,
                    'description' => is_string($description) ? $description : null,
                ];
            }
        } catch (Throwable) {
            // Non-blocking lookup
        }

        return null;
    }

    /**
     * Soft read of existing article title + meta description by observed WordPress permalink.
     * Does not crawl; does not load article body/HTML.
     *
     * @return array{title: string|null, description: string|null}|null
     */
    private function lookupArticleByPermalink(string $url): ?array
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return null;
            }

            $connection = 'omi_seo_ai';
            if (! Schema::connection($connection)->hasTable('article_metas')
                || ! Schema::connection($connection)->hasTable('articles')) {
                return null;
            }

            $variants = $this->urlLookupVariants($url);
            if ($variants === []) {
                return null;
            }

            $meta = DB::connection($connection)
                ->table('article_metas')
                ->where('meta_key', 'wp_permalink')
                ->whereIn('meta_value', $variants)
                ->orderByDesc('id')
                ->first(['article_id', 'meta_value']);

            if ($meta === null || (int) ($meta->article_id ?? 0) <= 0) {
                return null;
            }

            $articleId = (int) $meta->article_id;
            $article = DB::connection($connection)
                ->table('articles')
                ->where('id', $articleId)
                ->first(['id', 'title']);

            if ($article === null) {
                return null;
            }

            $description = null;
            $descRow = DB::connection($connection)
                ->table('article_metas')
                ->where('article_id', $articleId)
                ->whereIn('meta_key', ['seo_meta_description', 'meta_description'])
                ->orderByRaw("CASE meta_key WHEN 'seo_meta_description' THEN 0 ELSE 1 END")
                ->first(['meta_value']);

            if ($descRow !== null && is_string($descRow->meta_value ?? null)) {
                $description = trim((string) $descRow->meta_value);
            }

            return [
                'title' => isset($article->title) ? trim((string) $article->title) : null,
                'description' => $description !== '' ? $description : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function urlLookupVariants(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $variants = [$url];
        $trimmed = rtrim($url, '/');
        if ($trimmed !== $url) {
            $variants[] = $trimmed;
        } else {
            $variants[] = $url.'/';
        }

        return array_values(array_unique($variants));
    }
}
