<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\LinkIntelligence\Models\LinkResource;
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

        // 'manual', 'comment', 'share', 'topic' are UI/topic metadata, not source_type
        if (in_array($raw, ['manual', 'comment', 'share', 'topic', 'seeding_v1', 'other'], true)) {
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
     * 2. Existing LinkResource matched by URL
     * 3. Existing seo_site_link_catalog matched by URL
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

        // Priority 2: Existing LinkResource database record
        if ($title === '' || $description === '') {
            $fromResource = $this->lookupLinkResource($url);
            if ($fromResource !== null) {
                if ($title === '' && ! empty($fromResource['title'])) {
                    $title = trim((string) $fromResource['title']);
                }
                if ($description === '' && ! empty($fromResource['description'])) {
                    $description = trim((string) $fromResource['description']);
                }
            }
        }

        // Priority 3: Existing seo_site_link_catalog database record
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
    private function lookupLinkResource(string $url): ?array
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return null;
            }

            $row = LinkResource::query()
                ->where('original_url', $url)
                ->orWhere('normalized_url', $url)
                ->first(['title', 'description']);

            if ($row instanceof LinkResource) {
                return [
                    'title' => $row->title,
                    'description' => $row->description,
                ];
            }
        } catch (Throwable) {
            // Non-blocking lookup
        }

        return null;
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
}
