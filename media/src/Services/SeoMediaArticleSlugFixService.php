<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;


use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fix local media slugs for an article: disk + media row + article URL references.
 *
 * Canonical entry for "Fix slug all" (local). See docs/article-editor/image-slug-rename.md
 * — không vá riêng rename PHP; luôn trả exact rename map cho frontend update editor state.
 */
final class SeoMediaArticleSlugFixService
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
        private readonly SeoMediaUrlReplacementService $urlReplacement,
        private readonly WordPressAttachmentRenameService $wpRename,
    ) {}

    /**
     * @param  list<array{seo_media_id?: int|null, url?: string|null, new_slug: string, old_slug?: string|null}>  $items
     * @param  array{editor_session_id?: string|null, user?: \App\Models\User|null}  $context
     * @return array{
     *     success: bool,
     *     message: string,
     *     renamed: list<array<string, mixed>>,
     *     failed: list<array<string, mixed>>,
     *     replacements: list<array<string, mixed>>,
     *     article_updated: bool,
     *     media_updated: bool,
     *     skipped_count?: int,
     *     skipped?: list<array<string, mixed>>,
     *     remaining_old_refs?: list<string>
     * }
     */
    public function fixSlugs(SeoArticle $article, array $items, array $context = []): array
    {
        // Request mới sau save: refresh để rewrite đúng body/meta mới nhất.
        $article->refresh();

        $queue = $this->normalizeItems($items);
        if ($queue === []) {
            return [
                'success' => false,
                'message' => 'Không có ảnh local hợp lệ để đổi slug.',
                'renamed' => [],
                'failed' => [],
                'replacements' => [],
                'article_updated' => false,
                'media_updated' => false,
                'skipped_count' => 0,
                'skipped' => [],
                'eligible_count' => 0,
                'renamed_count' => 0,
            ];
        }

        $replacements = [];
        $urlMap = [];
        $pendingDeletes = [];
        $skipped = [];
        $wpRenameQueue = [];

        try {
            DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $queue,
                $context,
                &$replacements,
                &$urlMap,
                &$pendingDeletes,
                &$skipped,
                &$wpRenameQueue,
            ): void {
                $tempToken = 'seo-ren-'.Str::lower(Str::random(8));
                $interim = [];

                foreach ($queue as $index => $item) {
                    $media = $this->resolveMedia($article, $item);
                    if (! $media instanceof SeoMedia) {
                        $skipped[] = [
                            'index' => $index,
                            'seo_media_id' => $item['seo_media_id'],
                            'url' => $item['url'],
                            'new_slug' => $item['new_slug'],
                            'reason' => 'not_found',
                        ];
                        continue;
                    }

                    // WordPress-linked media never bulk-renamed.
                    // Fail-closed only for true WordPress media. Local /storage evidence wins over
                    // stale wp_attachment_id, matching editor media classification.
                    if ((int) ($media->wp_attachment_id ?? 0) > 0 && ! $this->isLocalMediaRequest($media, $item)) {
                        $skipped[] = [
                            'index' => $index,
                            'seo_media_id' => (int) $media->id,
                            'url' => $item['url'],
                            'new_slug' => $item['new_slug'],
                            'reason' => 'wordpress_media_requires_explicit_rename',
                        ];
                        continue;
                    }

                    try {
                        $oldUrl = $media->publicUrl();
                        $oldPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
                        $oldSlug = (string) ($media->slug ?? '');
                        $tempSlug = $tempToken.'-'.($index + 1);

                        $tempMedia = $this->storage->renameBySlug($media, $tempSlug, copyThenDelete: true);
                        $interim[] = [
                            'media' => $tempMedia,
                            'final_slug' => $item['new_slug'],
                            'old_url' => $oldUrl,
                            'old_path' => $oldPath,
                            'old_slug' => $oldSlug,
                            'temp_path' => ltrim(str_replace('\\', '/', (string) $tempMedia->path), '/'),
                            'item' => $item,
                            'index' => $index,
                        ];
                    } catch (Throwable $e) {
                        $skipped[] = [
                            'index' => $index,
                            'seo_media_id' => $item['seo_media_id'],
                            'url' => $item['url'],
                            'new_slug' => $item['new_slug'],
                            'reason' => $e->getMessage() !== '' ? $e->getMessage() : 'rename_phase1_failed',
                        ];
                    }
                }

                foreach ($interim as $state) {
                    /** @var SeoMedia $media */
                    $media = $state['media'];

                    try {
                        $beforeFinalPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
                        $renamed = $this->storage->renameBySlug($media, $state['final_slug'], copyThenDelete: true);
                        $newUrl = $renamed->publicUrl();
                        $newPath = ltrim(str_replace('\\', '/', (string) $renamed->path), '/');

                        $replacements[] = [
                            'media_id' => (int) $renamed->id,
                            'image_id' => (int) $renamed->id,
                            'old_filename' => basename($state['old_path']),
                            'new_filename' => basename($newPath),
                            'old_url' => $state['old_url'],
                            'new_url' => $newUrl,
                            'old_path' => $state['old_path'],
                            'new_path' => $newPath,
                            'old_slug' => $state['old_slug'],
                            'new_slug' => (string) $renamed->slug,
                        ];

                        $wpAttachmentId = (int) ($renamed->wp_attachment_id ?? 0);
                        $siteId = (int) ($renamed->site_id ?? $article->site_id ?? 0);
                        if ($wpAttachmentId > 0 && $siteId > 0) {
                            $wpRenameQueue[$siteId][] = [
                                'attachment_id' => $wpAttachmentId,
                                'new_slug' => (string) $renamed->slug,
                                'old_url' => $this->resolveWordPressRenameOldUrl($state['item'], $renamed),
                                'seo_media_id' => (int) $renamed->id,
                                'old_slug' => $state['old_slug'],
                            ];
                        }

                        if ($state['old_url'] !== '' && $newUrl !== '') {
                            $urlMap[$state['old_url']] = $newUrl;
                        }
                        if ($state['old_path'] !== '' && $newPath !== '') {
                            $urlMap['/storage/'.$state['old_path']] = '/storage/'.$newPath;
                        }

                        foreach ([$state['old_path'], $beforeFinalPath] as $stalePath) {
                            if ($stalePath !== '' && $stalePath !== $newPath) {
                                $pendingDeletes[$stalePath] = true;
                            }
                        }
                    } catch (Throwable $e) {
                        $item = is_array($state['item'] ?? null) ? $state['item'] : [];
                        $skipped[] = [
                            'index' => (int) ($state['index'] ?? -1),
                            'seo_media_id' => $item['seo_media_id'] ?? null,
                            'url' => $item['url'] ?? $state['old_url'] ?? '',
                            'new_slug' => $state['final_slug'] ?? ($item['new_slug'] ?? ''),
                            'reason' => $e->getMessage() !== '' ? $e->getMessage() : 'rename_phase2_failed',
                        ];
                    }
                }

                if ($urlMap === []) {
                    return;
                }

                $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap, $context);
                if ($rewrite['remaining_old_refs'] !== []) {
                    throw new \RuntimeException(
                        'Article vẫn còn URL ảnh cũ sau khi đổi slug: '
                        .implode(', ', array_slice($rewrite['remaining_old_refs'], 0, 3))
                    );
                }
            });
        } catch (Throwable $e) {
            if ($e instanceof \Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException) {
                throw $e;
            }

            RuntimeLogger::error('seo_media_article_slug_fix.failed', [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'error' => $e->getMessage(),
                'items' => array_map(static fn (array $row): array => [
                    'seo_media_id' => $row['seo_media_id'] ?? null,
                    'url' => $row['url'] ?? null,
                    'new_slug' => $row['new_slug'] ?? null,
                ], $queue),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Không đổi được slug ảnh.',
                'renamed' => [],
                'failed' => $skipped,
                'replacements' => [],
                'article_updated' => false,
                'media_updated' => false,
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
                'eligible_count' => count($queue),
                'renamed_count' => 0,
            ];
        }

        foreach ($wpRenameQueue as $siteId => $wpItems) {
            $site = Site::query()->find((int) $siteId);
            if (! $site instanceof Site) {
                foreach ($wpItems as $item) {
                    $skipped[] = [
                        'seo_media_id' => (int) ($item['seo_media_id'] ?? 0),
                        'new_slug' => (string) ($item['new_slug'] ?? ''),
                        'reason' => 'wordpress_site_not_found',
                    ];
                }

                continue;
            }

            $wpContextByAttachmentId = [];
            foreach ($wpItems as $item) {
                $attachmentId = (int) ($item['attachment_id'] ?? 0);
                if ($attachmentId > 0) {
                    $wpContextByAttachmentId[$attachmentId] = $item;
                }
            }

            $wpResult = $this->wpRename->renameForSite($site, $wpItems);
            foreach ((array) ($wpResult['renamed'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $attachmentId = (int) ($row['attachment_id'] ?? 0);
                $contextRow = $wpContextByAttachmentId[$attachmentId] ?? [];

                $wpOldUrl = trim((string) ($row['old_url'] ?? ''));
                $wpNewUrl = trim((string) ($row['new_url'] ?? ''));
                if ($wpOldUrl !== '' && $wpNewUrl !== '') {
                    $urlMap[$wpOldUrl] = $wpNewUrl;
                }

                $replacements[] = [
                    'media_id' => (int) ($contextRow['seo_media_id'] ?? 0),
                    'image_id' => (int) ($contextRow['seo_media_id'] ?? 0),
                    'attachment_id' => $attachmentId,
                    'old_filename' => (string) ($row['old_filename'] ?? basename($wpOldUrl)),
                    'new_filename' => (string) ($row['new_filename'] ?? basename($wpNewUrl)),
                    'old_url' => $wpOldUrl,
                    'new_url' => $wpNewUrl,
                    'old_path' => '',
                    'new_path' => '',
                    'old_slug' => (string) ($row['old_slug'] ?? $contextRow['old_slug'] ?? ''),
                    'new_slug' => (string) ($row['new_slug'] ?? ''),
                    'source' => 'wordpress',
                ];
            }

            foreach ((array) ($wpResult['errors'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $attachmentId = (int) ($row['attachment_id'] ?? 0);
                $contextRow = $wpContextByAttachmentId[$attachmentId] ?? [];

                $skipped[] = [
                    'seo_media_id' => (int) ($contextRow['seo_media_id'] ?? 0),
                    'attachment_id' => $attachmentId,
                    'new_slug' => (string) ($row['new_slug'] ?? ''),
                    'reason' => (string) ($row['message'] ?? $wpResult['message'] ?? 'wordpress_rename_failed'),
                ];
            }
        }

        if ($urlMap !== []) {
            $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap, $context);
            if ($rewrite['remaining_old_refs'] !== []) {
                RuntimeLogger::warning('seo_media_article_slug_fix.wp_remaining_old_refs', [
                    'article_id' => (int) $article->id,
                    'remaining_old_refs' => $rewrite['remaining_old_refs'],
                ]);
            }
        }

        $disk = Storage::disk('public');
        foreach (array_keys($pendingDeletes) as $path) {
            if ($path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $fresh = $article->fresh() ?? $article;
        $remaining = $urlMap === []
            ? []
            : $this->urlReplacement->findRemainingOldRefs((string) ($fresh->body ?? ''), $urlMap);

        $skippedCount = count($skipped);
        $message = 'Đã cập nhật slug cho '.count($replacements).' ảnh.';
        if ($skippedCount > 0) {
            $message .= ' Bỏ qua '.$skippedCount.' ảnh thiếu/lỗi.';
        }

        RuntimeLogger::info('seo_media_article_slug_fix.completed', [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0),
            'replacement_count' => count($replacements),
            'skipped_count' => $skippedCount,
            'remaining_old_refs' => $remaining,
        ]);

        $renamed = array_map(static function (array $row): array {
            return [
                'image_id' => (int) ($row['image_id'] ?? $row['media_id'] ?? 0),
                'old_filename' => (string) ($row['old_filename'] ?? basename((string) ($row['old_path'] ?? ''))),
                'new_filename' => (string) ($row['new_filename'] ?? basename((string) ($row['new_path'] ?? ''))),
                'old_url' => (string) ($row['old_url'] ?? ''),
                'new_url' => (string) ($row['new_url'] ?? ''),
                'old_path' => (string) ($row['old_path'] ?? ''),
                'new_path' => (string) ($row['new_path'] ?? ''),
                'old_slug' => (string) ($row['old_slug'] ?? ''),
                'new_slug' => (string) ($row['new_slug'] ?? ''),
                'media_id' => (int) ($row['media_id'] ?? $row['image_id'] ?? 0),
            ];
        }, $replacements);

        // Tất cả bị skip vẫn success — client tiếp tục, không dừng cả batch.
        return [
            'success' => true,
            'message' => $message,
            'renamed' => $renamed,
            'failed' => $skipped,
            'replacements' => $replacements,
            'article_updated' => $urlMap !== [],
            'media_updated' => $urlMap !== [],
            'skipped_count' => $skippedCount,
            'skipped' => $skipped,
            'eligible_count' => count($queue),
            'renamed_count' => count($renamed),
            'failed_count' => $skippedCount,
            'remaining_old_refs' => $remaining,
            'document_version' => max(1, (int) ($fresh->document_version ?? 1)),
            'content_hash' => app(ArticleContentConflictGuard::class)
                ->contentHash((string) ($fresh->body ?? '')),
            'editor_document_hash' => (string) ($fresh->editor_document_hash ?? ''),
            'updated_at' => $fresh->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Rename one media and rewrite references for a single article when provided.
     *
     * @param  array{editor_session_id?: string|null, user?: \App\Models\User|null}  $context
     * @return array{media: SeoMedia, replacement: array<string, mixed>, article_updated: bool}
     */
    public function renameOne(SeoMedia $media, string $newSlug, ?SeoArticle $article = null, array $context = []): array
    {
        $oldUrl = $media->publicUrl();
        $oldPath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        $oldSlug = (string) ($media->slug ?? '');

        $renamed = $this->storage->renameBySlug($media, $newSlug, copyThenDelete: true);
        $newUrl = $renamed->publicUrl();
        $newPath = ltrim(str_replace('\\', '/', (string) $renamed->path), '/');

        $replacement = [
            'media_id' => (int) $renamed->id,
            'image_id' => (int) $renamed->id,
            'old_filename' => basename($oldPath),
            'new_filename' => basename($newPath),
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'old_path' => $oldPath,
            'new_path' => $newPath,
            'old_slug' => $oldSlug,
            'new_slug' => (string) $renamed->slug,
        ];

        $articleUpdated = false;
        if ($article instanceof SeoArticle) {
            $urlMap = [
                $oldUrl => $newUrl,
                '/storage/'.$oldPath => '/storage/'.$newPath,
            ];
            $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap, $context);
            $articleUpdated = $rewrite['article_updated'];
            if ($rewrite['remaining_old_refs'] !== []) {
                throw new \RuntimeException(
                    'Article vẫn còn URL ảnh cũ sau khi đổi slug.'
                );
            }
        }

        if ($oldPath !== '' && $oldPath !== $newPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return [
            'media' => $renamed,
            'replacement' => $replacement,
            'article_updated' => $articleUpdated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{seo_media_id: int|null, url: string, new_slug: string, old_slug: string}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $newSlug = Str::slug((string) ($item['new_slug'] ?? ''));
            if ($newSlug === '') {
                continue;
            }

            $out[] = [
                'seo_media_id' => ((int) ($item['seo_media_id'] ?? 0)) > 0
                    ? (int) $item['seo_media_id']
                    : null,
                'url' => trim((string) ($item['url'] ?? $item['src'] ?? '')),
                'new_slug' => $newSlug,
                'old_slug' => trim((string) ($item['old_slug'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  array{seo_media_id: int|null, url: string, new_slug: string, old_slug: string}  $item
     */
    private function resolveMedia(SeoArticle $article, array $item): ?SeoMedia
    {
        $id = (int) ($item['seo_media_id'] ?? 0);
        if ($id > 0) {
            $media = SeoMedia::query()->find($id);
            if ($media instanceof SeoMedia) {
                return $media;
            }
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $path = $this->urlReplacement->storagePathFromUrl($url);
        if ($path === '') {
            return null;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $query = SeoMedia::query()->where('path', $path);
        if ($siteId > 0) {
            $query->where(function ($q) use ($siteId): void {
                $q->where('site_id', $siteId)->orWhereNull('site_id');
            });
        }

        $media = $query->first();
        if ($media instanceof SeoMedia) {
            return $media;
        }

        $media = $this->resolveSeoMediaForStoragePath($path, $siteId);
        if ($media instanceof SeoMedia) {
            return $media;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $filename = basename($path);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        return SeoMedia::query()->create([
            'site_id' => $siteId > 0 ? $siteId : null,
            'article_id' => (int) $article->getKey(),
            'filename' => $filename,
            'slug' => Str::slug((string) pathinfo($filename, PATHINFO_FILENAME)),
            'path' => $path,
            'url' => '/storage/'.$path,
            'source' => 'storage_adopt',
        ]);
    }

    private function resolveSeoMediaForStoragePath(string $relativePath, int $siteId): ?SeoMedia
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return null;
        }

        $filename = basename($relativePath);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        $candidates = array_values(array_unique([
            $relativePath,
            'uploads/seo_media/'.$filename,
        ]));

        foreach ($candidates as $candidate) {
            $query = SeoMedia::query()->where('path', $candidate);
            if ($siteId > 0) {
                $query->where(function ($q) use ($siteId): void {
                    $q->where('site_id', $siteId)->orWhereNull('site_id');
                });
            }

            $media = $query->first();
            if ($media instanceof SeoMedia) {
                return $media;
            }
        }

        $filenameQuery = SeoMedia::query()->where('filename', $filename);
        if ($siteId > 0) {
            $filenameQuery->where(function ($q) use ($siteId): void {
                $q->where('site_id', $siteId)->orWhereNull('site_id');
            });
        }

        $media = $filenameQuery->orderByDesc('id')->first();

        return $media instanceof SeoMedia ? $media : null;
    }

    /**
     * @param  array{seo_media_id: int|null, url: string, new_slug: string, old_slug: string}  $item
     */
    private function isLocalMediaRequest(SeoMedia $media, array $item): bool
    {
        $mediaPath = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        if (str_starts_with($mediaPath, 'uploads/seo_media/')) {
            return true;
        }

        $itemPath = $this->urlReplacement->storagePathFromUrl((string) ($item['url'] ?? ''));

        return $itemPath !== '' && str_starts_with($itemPath, 'uploads/seo_media/');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveWordPressRenameOldUrl(array $item, SeoMedia $media): string
    {
        foreach (['wp_url', 'wpUrl', 'wordpress_url', 'source_url'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '' && ! str_contains($value, '/storage/uploads/seo_media/')) {
                return $value;
            }
        }

        foreach (['wp_url', 'wordpress_url', 'source_url'] as $key) {
            $value = trim((string) ($media->getAttribute($key) ?? ''));
            if ($value !== '' && ! str_contains($value, '/storage/uploads/seo_media/')) {
                return $value;
            }
        }

        return '';
    }
}
