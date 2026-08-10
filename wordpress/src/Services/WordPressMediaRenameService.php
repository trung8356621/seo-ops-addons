<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Explicit single-item WordPress media rename with usage scan, strong confirmation, audit.
 * Bulk Fix Slug All must never call this for unprotected batch WP renames.
 */
final class WordPressMediaRenameService
{
    public const CONFIRMATION_PHRASE = 'RENAME';

    public const ERROR_BULK_FORBIDDEN = 'wordpress_media_requires_explicit_rename';

    public function __construct(
        private readonly WordPressAttachmentRenameService $attachmentRename,
        private readonly SeoMediaUrlReplacementService $urlReplacement,
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    public function canRenameWordPressMedia(?User $actor = null): bool
    {
        $actor ??= auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        if (SeoAccessControl::isContentManager()) {
            return false;
        }

        return SeoAccessControl::canMutateInSeoPanel()
            && SeoAccessControl::canAccessManagerFeatures();
    }

    /**
     * Fail-closed gate for legacy bulk Livewire rename path.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, skipped: list<array<string, mixed>>, skipped_count: int, renamed: list<mixed>}
     */
    public function rejectBulkWordPressRename(array $items): array
    {
        $skipped = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
            $skipped[] = [
                'index' => $index,
                'attachment_id' => $attachmentId,
                'reason' => self::ERROR_BULK_FORBIDDEN,
            ];
        }

        return [
            'success' => false,
            'message' => 'Ảnh WordPress cần đổi tên riêng (không dùng Fix Slug All).',
            'error_code' => self::ERROR_BULK_FORBIDDEN,
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
            'renamed' => [],
            'renamed_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Site $site, int $attachmentId, string $oldUrl = '', string $proposedSlug = ''): array
    {
        abort_unless($this->canRenameWordPressMedia(), 403);
        abort_unless(SeoAccessControl::canAccessSite((int) $site->id), 403);

        $attachmentId = max(0, $attachmentId);
        if ($attachmentId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu attachment ID.',
            ];
        }

        $wpUsage = $this->fetchWordPressUsage($site, $attachmentId, $oldUrl);
        $currentUrl = trim((string) ($wpUsage['old_url'] ?? $oldUrl));
        $laravelUsage = $this->scanLaravelUsage($site, $attachmentId, $currentUrl);

        $filename = trim((string) ($wpUsage['filename'] ?? ''));
        if ($filename === '' && $currentUrl !== '') {
            $path = (string) (parse_url($currentUrl, PHP_URL_PATH) ?: $currentUrl);
            $filename = basename(str_replace('\\', '/', $path));
        }

        $proposed = $this->sanitizeSlug($proposedSlug);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $proposedFilename = $proposed !== ''
            ? ($extension !== '' ? $proposed.'.'.strtolower($extension) : $proposed)
            : '';

        $usageSummary = [
            'wordpress_posts' => (int) ($wpUsage['wordpress_posts'] ?? 0),
            'featured_references' => (int) ($wpUsage['featured_references'] ?? 0),
            'laravel_articles' => (int) ($laravelUsage['laravel_articles'] ?? 0),
            'galleries' => (int) ($laravelUsage['galleries'] ?? 0),
            'unknown_references' => 0,
        ];
        $usageCount = array_sum($usageSummary);
        $samples = array_values(array_merge(
            is_array($wpUsage['samples'] ?? null) ? $wpUsage['samples'] : [],
            is_array($laravelUsage['samples'] ?? null) ? $laravelUsage['samples'] : [],
        ));

        $wpOk = (bool) ($wpUsage['success'] ?? false);
        $identityReady = $attachmentId > 0 && $currentUrl !== '' && $filename !== '';
        // WP usage endpoint may fail even when attachment identity is known (Laravel samples still useful).
        // Allow rename confirm when identity is resolvable; WP rename API still validates attachment.
        $scanOk = $wpOk || $identityReady;
        $scanMessage = $wpOk
            ? 'Đã quét usage WordPress.'
            : trim((string) ($wpUsage['message'] ?? ''));
        if (! $wpOk) {
            if ($scanMessage === '') {
                $scanMessage = 'Không quét được usage WordPress.';
            }
            if ($identityReady) {
                $scanMessage .= ' Đã có URL/filename + tham chiếu Laravel — vẫn cho phép đổi tên (rủi ro cao hơn).';
            } else {
                $scanMessage .= ' Cập nhật plugin TVH SEO AI Bridge ≥ 1.0.71 rồi thử lại.';
            }
        }

        $coverageNote = 'Cập nhật mọi tham chiếu mà hệ thống phát hiện được. Không đảm bảo theme options, page builder hoặc liên kết ngoài site.';
        if (! $wpOk && $identityReady) {
            $coverageNote = 'Usage WordPress chưa quét được. Chỉ thấy tham chiếu Laravel/local. '.$coverageNote;
        }

        return [
            'success' => true,
            'scan_complete' => $scanOk,
            'wp_scan_ok' => $wpOk,
            'attachment_id' => $attachmentId,
            'old_url' => $currentUrl,
            'filename' => $filename,
            'proposed_slug' => $proposed,
            'proposed_filename' => $proposedFilename,
            'usage_summary' => $usageSummary,
            'usage_count' => $usageCount,
            'samples' => array_slice($samples, 0, 20),
            'supports_redirect' => (bool) ($wpUsage['supports_redirect'] ?? false),
            'warning' => 'Đây là ảnh WordPress đã được public. Đổi tên file sẽ thay đổi URL ảnh và có thể ảnh hưởng bài viết, banner, widget, cache hoặc liên kết bên ngoài.',
            'coverage_note' => $coverageNote,
            'message' => $scanMessage,
            'block_reason' => $scanOk ? null : $scanMessage,
        ];
    }

    /**
     * @param  array{
     *     attachment_id: int,
     *     new_slug: string,
     *     old_url?: string,
     *     acknowledge_url_change: bool,
     *     confirmation_phrase: string,
     *     source_action?: string,
     *     article_id?: int|null
     * }  $payload
     * @return array<string, mixed>
     */
    public function renameExplicit(Site $site, array $payload, User $actor): array
    {
        abort_unless($this->canRenameWordPressMedia($actor), 403);
        abort_unless(SeoAccessControl::canAccessSite((int) $site->id), 403);

        $attachmentId = (int) ($payload['attachment_id'] ?? 0);
        $newSlug = $this->sanitizeSlug((string) ($payload['new_slug'] ?? ''));
        $oldUrl = trim((string) ($payload['old_url'] ?? ''));
        $ack = filter_var($payload['acknowledge_url_change'] ?? false, FILTER_VALIDATE_BOOL);
        $phrase = trim((string) ($payload['confirmation_phrase'] ?? $payload['confirmation_token'] ?? ''));
        $sourceAction = trim((string) ($payload['source_action'] ?? 'unknown'));
        $articleId = (int) ($payload['article_id'] ?? 0);

        if ($attachmentId <= 0 || $newSlug === '') {
            return $this->fail('validation_failed', 'Thiếu attachment_id hoặc new_slug.');
        }

        if (! $ack || $phrase !== self::CONFIRMATION_PHRASE) {
            return $this->fail('confirmation_required', 'Cần checkbox xác nhận và nhập chính xác RENAME.');
        }

        $preview = $this->preview($site, $attachmentId, $oldUrl, $newSlug);
        if (! ($preview['scan_complete'] ?? false)) {
            return $this->fail(
                'usage_scan_incomplete',
                'Usage scan chưa hoàn thành — không đổi tên. Thử lại hoặc kiểm tra kết nối WordPress.',
                ['preview' => $preview],
            );
        }

        $lockKey = 'wp-media-rename:'.(int) $site->id.':'.$attachmentId;
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            return $this->fail('lock_busy', 'Đang có lượt đổi tên cho ảnh này.');
        }

        $rollback = [
            'old_url' => (string) ($preview['old_url'] ?? $oldUrl),
            'old_filename' => (string) ($preview['filename'] ?? ''),
            'attachment_id' => $attachmentId,
            'new_slug' => $newSlug,
        ];

        try {
            $wpResult = $this->attachmentRename->renameExplicitSingle($site, [
                'attachment_id' => $attachmentId,
                'new_slug' => $newSlug,
                'old_url' => $rollback['old_url'],
            ]);

            if (! ($wpResult['success'] ?? false)) {
                $this->audit($actor, $site, $rollback, $wpResult, $sourceAction, 'failed');

                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => (string) ($wpResult['message'] ?? 'Đổi tên WordPress thất bại.'),
                    'error_code' => (string) ($wpResult['error_code'] ?? 'wp_rename_failed'),
                    'data' => $wpResult,
                ];
            }

            $renamedRow = is_array($wpResult['renamed'][0] ?? null) ? $wpResult['renamed'][0] : [];
            $newUrl = trim((string) ($renamedRow['new_url'] ?? ''));
            $finalOldUrl = trim((string) ($renamedRow['old_url'] ?? $rollback['old_url']));
            $finalSlug = trim((string) ($renamedRow['new_slug'] ?? $newSlug));

            $laravelRewrite = ['updated_articles' => 0, 'failures' => []];
            if ($finalOldUrl !== '' && $newUrl !== '') {
                $laravelRewrite = $this->rewriteLaravelReferences(
                    $site,
                    $finalOldUrl,
                    $newUrl,
                    $attachmentId,
                    $articleId > 0 ? $articleId : null,
                );
            }

            $failures = is_array($laravelRewrite['failures'] ?? null) ? $laravelRewrite['failures'] : [];
            if ($failures !== []) {
                $partial = [
                    'success' => false,
                    'status' => 'partial_failure',
                    'message' => 'Đã đổi tên file WordPress nhưng một số tham chiếu Laravel chưa cập nhật.',
                    'error_code' => 'partial_failure',
                    'old_url' => $finalOldUrl,
                    'new_url' => $newUrl,
                    'new_slug' => $finalSlug,
                    'wp_result' => $wpResult,
                    'laravel_rewrite' => $laravelRewrite,
                    'retry_payload' => [
                        'attachment_id' => $attachmentId,
                        'old_url' => $finalOldUrl,
                        'new_url' => $newUrl,
                    ],
                ];
                $this->audit($actor, $site, array_merge($rollback, [
                    'new_url' => $newUrl,
                    'new_slug' => $finalSlug,
                ]), $partial, $sourceAction, 'partial_failure');

                return $partial;
            }

            $ok = [
                'success' => true,
                'status' => 'renamed',
                'message' => sprintf(
                    'Đã đổi tên ảnh WordPress và cập nhật %d tham chiếu phát hiện được.',
                    (int) ($preview['usage_count'] ?? 0),
                ),
                'attachment_id' => $attachmentId,
                'old_url' => $finalOldUrl,
                'new_url' => $newUrl,
                'new_slug' => $finalSlug,
                'usage_count' => (int) ($preview['usage_count'] ?? 0),
                'references_updated' => [
                    'wordpress_posts' => (int) ($wpResult['posts_updated'] ?? 0),
                    'laravel_articles' => (int) ($laravelRewrite['updated_articles'] ?? 0),
                ],
                'renamed' => [$renamedRow],
                'supports_redirect' => false,
                'redirect_note' => 'URL cũ có thể không còn hoạt động (plugin chưa hỗ trợ redirect mapping).',
            ];
            $this->audit($actor, $site, array_merge($rollback, [
                'new_url' => $newUrl,
                'new_slug' => $finalSlug,
                'usage_count' => $ok['usage_count'],
            ]), $ok, $sourceAction, 'success');

            return $ok;
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'wordpress_media_rename',
                'site_id' => (int) $site->id,
                'attachment_id' => $attachmentId,
            ]);

            return $this->fail('exception', 'Lỗi đổi tên: '.$e->getMessage());
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWordPressUsage(Site $site, int $attachmentId, string $oldUrl): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        $base = $this->wpContent->getPermalinkBase($site);
        if ($token === '' || $base === '') {
            return [
                'success' => false,
                'message' => 'Thiếu token hoặc URL WordPress.',
                'wordpress_posts' => 0,
                'featured_references' => 0,
                'samples' => [],
                'supports_redirect' => false,
            ];
        }

        try {
            $endpoint = $base.'/wp-json/omi-seo-ai/v1/attachments/usage';
            $query = [
                'attachment_id' => $attachmentId,
                'old_url' => $oldUrl,
            ];

            $response = Http::timeout(60)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->get($endpoint, $query);

            if (! $response->successful()) {
                $response = Http::timeout(60)
                    ->acceptJson()
                    ->asJson()
                    ->withToken($token)
                    ->post($endpoint, $query);
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];

            if (! $response->successful()) {
                $remoteMessage = trim((string) ($payload['message'] ?? ''));
                $hint = $response->status() === 404
                    ? ' Endpoint /attachments/usage thiếu — cập nhật plugin TVH SEO AI Bridge ≥ 1.0.71.'
                    : '';

                return [
                    'success' => false,
                    'message' => ($remoteMessage !== '' ? $remoteMessage : 'WordPress usage HTTP '.$response->status()).$hint,
                    'filename' => (string) ($payload['filename'] ?? ''),
                    'old_url' => (string) ($payload['old_url'] ?? $oldUrl),
                    'wordpress_posts' => (int) ($payload['wordpress_posts'] ?? 0),
                    'featured_references' => (int) ($payload['featured_references'] ?? 0),
                    'samples' => is_array($payload['samples'] ?? null) ? $payload['samples'] : [],
                    'supports_redirect' => false,
                ];
            }

            if ($payload === []) {
                return [
                    'success' => false,
                    'message' => 'Usage response invalid.',
                    'wordpress_posts' => 0,
                    'featured_references' => 0,
                    'samples' => [],
                    'supports_redirect' => false,
                ];
            }

            return array_merge($payload, ['success' => (bool) ($payload['success'] ?? true)]);
        } catch (Throwable $e) {
            RuntimeLogger::warning('wordpress_media_usage_scan_failed', [
                'site_id' => (int) $site->id,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'wordpress_posts' => 0,
                'featured_references' => 0,
                'samples' => [],
                'supports_redirect' => false,
            ];
        }
    }

    /**
     * @return array{laravel_articles: int, galleries: int, samples: list<array<string, mixed>>}
     */
    private function scanLaravelUsage(Site $site, int $attachmentId, string $oldUrl): array
    {
        $siteId = (int) $site->id;
        $samples = [];
        $articleCount = 0;
        $galleryCount = 0;

        if ($oldUrl !== '') {
            $needle = $oldUrl;
            $articles = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where('body', 'like', '%'.$needle.'%')
                ->limit(50)
                ->get(['id', 'title']);

            foreach ($articles as $article) {
                $articleCount++;
                $samples[] = [
                    'post_id' => null,
                    'article_id' => (int) $article->id,
                    'title' => (string) ($article->title ?? ''),
                    'post_type' => 'seo_article',
                    'reference_type' => 'laravel_article',
                ];
            }
        }

        if ($attachmentId > 0) {
            $mediaHits = SeoMedia::query()
                ->where('site_id', $siteId)
                ->where('wp_attachment_id', $attachmentId)
                ->limit(20)
                ->get(['id', 'slug', 'path']);
            foreach ($mediaHits as $media) {
                $galleryCount++;
                $samples[] = [
                    'post_id' => null,
                    'article_id' => null,
                    'title' => (string) ($media->slug ?? $media->path ?? ''),
                    'post_type' => 'seo_media',
                    'reference_type' => 'local_media_metadata',
                    'seo_media_id' => (int) $media->id,
                ];
            }
        }

        return [
            'laravel_articles' => $articleCount,
            'galleries' => $galleryCount,
            'samples' => $samples,
        ];
    }

    /**
     * @return array{updated_articles: int, failures: list<array<string, mixed>>}
     */
    private function rewriteLaravelReferences(
        Site $site,
        string $oldUrl,
        string $newUrl,
        int $attachmentId,
        ?int $preferArticleId,
    ): array {
        $updated = 0;
        $failures = [];
        $urlMap = [$oldUrl => $newUrl];

        $query = SeoArticle::query()->where('site_id', (int) $site->id);
        if ($preferArticleId !== null && $preferArticleId > 0) {
            $query->where(function ($q) use ($preferArticleId, $oldUrl): void {
                $q->where('id', $preferArticleId)
                    ->orWhere('body', 'like', '%'.$oldUrl.'%');
            });
        } else {
            $query->where('body', 'like', '%'.$oldUrl.'%');
        }

        foreach ($query->limit(100)->get() as $article) {
            try {
                $rewrite = $this->urlReplacement->rewriteArticleReferences($article, $urlMap);
                if (($rewrite['updated'] ?? false) || ($rewrite['article_updated'] ?? false) || ! empty($rewrite['changed'])) {
                    $updated++;
                }
                $remaining = is_array($rewrite['remaining_old_refs'] ?? null) ? $rewrite['remaining_old_refs'] : [];
                if ($remaining !== []) {
                    $failures[] = [
                        'article_id' => (int) $article->id,
                        'remaining_old_refs' => $remaining,
                    ];
                }
            } catch (Throwable $e) {
                $failures[] = [
                    'article_id' => (int) $article->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $reconcileSlug = pathinfo((string) (parse_url($newUrl, PHP_URL_PATH) ?: ''), PATHINFO_FILENAME);
        if (is_string($reconcileSlug) && $reconcileSlug !== '') {
            SeoMedia::query()
                ->where('site_id', (int) $site->id)
                ->where('wp_attachment_id', $attachmentId)
                ->update(['slug' => $reconcileSlug]);
        }

        return [
            'updated_articles' => $updated,
            'failures' => $failures,
        ];
    }

    private function sanitizeSlug(string $value): string
    {
        $value = Str::slug(trim($value), '-');
        if ($value === '' || str_contains($value, '..') || str_contains($value, '/') || str_contains($value, '\\')) {
            return '';
        }

        return mb_substr($value, 0, 180);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fail(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'status' => 'blocked',
            'error_code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $result
     */
    private function audit(
        User $actor,
        Site $site,
        array $context,
        array $result,
        string $sourceAction,
        string $outcome,
    ): void {
        RuntimeLogger::info('wordpress_media_rename_audit', [
            'actor_id' => (int) $actor->id,
            'site_id' => (int) $site->id,
            'attachment_id' => (int) ($context['attachment_id'] ?? 0),
            'old_filename' => (string) ($context['old_filename'] ?? ''),
            'new_filename' => (string) ($context['new_slug'] ?? ''),
            'old_url' => (string) ($context['old_url'] ?? ''),
            'new_url' => (string) ($context['new_url'] ?? $result['new_url'] ?? ''),
            'usage_count' => (int) ($context['usage_count'] ?? $result['usage_count'] ?? 0),
            'references_updated' => $result['references_updated'] ?? null,
            'failures' => $result['laravel_rewrite']['failures'] ?? $result['failures'] ?? null,
            'source_action' => $sourceAction,
            'confirmation_method' => 'checkbox+RENAME',
            'outcome' => $outcome,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
