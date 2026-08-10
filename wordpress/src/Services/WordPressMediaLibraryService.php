<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\WordPress\Support\WordPressImageUrl;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WordPressMediaLibraryService
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
        private readonly SeoWpMediaEditedPendingService $editedPending,
    ) {}

    /**
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    public function fetch(
        Site $site,
        ?string $filterMonth = null,
        int $page = 1,
        int $perPage = 50,
        ?string $search = null,
        ?array $includeAttachmentIds = null,
    ): array {
        if ($includeAttachmentIds !== null) {
            return $this->fetchIncludedAttachments(
                $site,
                $includeAttachmentIds,
                $filterMonth,
                $page,
                $perPage,
                $search,
            );
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return $this->emptyResult($page, 'Thiếu Read token trên domain. Cấu hình tại Danh sách tên miền.');
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return $this->emptyResult($page, 'Không xác định được URL WordPress của domain.');
        }

        $filterMonth = trim((string) $filterMonth);
        $query = [
            'per_page' => max(1, min(100, $perPage)),
            'page' => max(1, $page),
            'orderby' => 'date',
            'order' => 'desc',
        ];

        if ($filterMonth !== '') {
            try {
                $date = Carbon::createFromFormat('Y-m', $filterMonth);
            } catch (Throwable) {
                return $this->emptyResult($page, 'Tháng lọc không hợp lệ.');
            }

            $query['after'] = $date->copy()->startOfMonth()->toIso8601String();
            $query['before'] = $date->copy()->endOfMonth()->toIso8601String();
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($readToken)
                ->get($base.'/wp-json/wp/v2/media', $query);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return $this->emptyResult($page, 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 300));
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->emptyResult($page, 'Phản hồi WordPress không hợp lệ.');
            }

            $images = [];
            foreach ($payload as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (int) ($item['id'] ?? 0);
                $url = trim((string) ($item['source_url'] ?? ''));
                $thumbUrl = $this->resolveWordPressThumbnailUrl($item, $url);
                if ($id <= 0 || $url === '') {
                    continue;
                }

                $title = $item['title'] ?? '';
                if (is_array($title)) {
                    $title = (string) ($title['rendered'] ?? '');
                }

                $alt = (string) ($item['alt_text'] ?? '');
                if ($alt === '' && is_array($item['meta'] ?? null)) {
                    $alt = (string) ($item['meta']['_wp_attachment_image_alt'] ?? '');
                }

                $images[] = [
                    'kind' => 'wordpress',
                    'id' => $id,
                    'wp_attachment_id' => $id,
                    'seo_media_id' => 0,
                    'url' => $url,
                    'thumb_url' => $thumbUrl,
                    'media_type' => $this->resolveWordPressMediaType($item),
                    'slug' => trim((string) ($item['slug'] ?? '')),
                    'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'alt' => $alt,
                    'date' => (string) ($item['date'] ?? ''),
                ];
            }

            $images = $this->editedPending->applyPendingEditsToWordPressImages((int) $site->id, $images);

            $total = (int) $response->header('X-WP-Total', count($images));
            $totalPages = max(1, (int) $response->header('X-WP-TotalPages', 1));

            return [
                'images' => $images,
                'total' => $total,
                'total_pages' => $totalPages,
                'page' => $page,
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress media library fetch failed', [
                'site_id' => $site->id,
                'month' => $filterMonth,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyResult($page, 'Không kết nối được WordPress: '.$e->getMessage());
        }
    }

    /**
     * @return array{kind: string, id: int, wp_attachment_id: int, url: string, slug: string, title: string, alt: string}|null
     */
    public function fetchAttachmentById(Site $site, int $attachmentId): ?array
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return null;
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($base.'/wp-json/wp/v2/media/'.$attachmentId);

            if (! $response->successful()) {
                return null;
            }

            $item = $response->json();
            if (! is_array($item)) {
                return null;
            }

            $id = (int) ($item['id'] ?? 0);
            $url = trim((string) ($item['source_url'] ?? ''));
            if ($id <= 0 || $url === '') {
                return null;
            }

            $title = $item['title'] ?? '';
            if (is_array($title)) {
                $title = (string) ($title['rendered'] ?? '');
            }

            $alt = (string) ($item['alt_text'] ?? '');
            if ($alt === '' && is_array($item['meta'] ?? null)) {
                $alt = (string) ($item['meta']['_wp_attachment_image_alt'] ?? '');
            }

            $row = [
                'kind' => 'wordpress',
                'id' => $id,
                'wp_attachment_id' => $id,
                'seo_media_id' => 0,
                'url' => $url,
                'media_type' => $this->resolveWordPressMediaType($item),
                'slug' => trim((string) ($item['slug'] ?? '')),
                'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'alt' => $alt,
                'date' => (string) ($item['date'] ?? ''),
            ];

            $merged = $this->editedPending->applyPendingEditsToWordPressImages((int) $site->id, [$row]);

            return $merged[0] ?? $row;
        } catch (Throwable $e) {
            Log::warning('WordPress media attachment fetch failed', [
                'site_id' => $site->id,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchAttachmentBySourceUrl(Site $site, string $sourceUrl): ?array
    {
        $sourceUrl = WordPressImageUrl::toFullSize(trim($sourceUrl));
        if ($sourceUrl === '') {
            return null;
        }

        $slug = WordPressImageUrl::slugFromUrl($sourceUrl);
        if ($slug === '') {
            return null;
        }

        $searchTerms = array_values(array_unique(array_filter([
            $slug,
            Str::slug($slug),
        ])));

        foreach ($searchTerms as $search) {
            $result = $this->fetch($site, null, 1, 50, $search);
            $images = is_array($result['images'] ?? null) ? $result['images'] : [];

            foreach ($images as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $candidateUrl = trim((string) ($image['url'] ?? ''));
                if ($candidateUrl === '') {
                    continue;
                }

                if ($this->wordPressUploadPathsMatch($candidateUrl, $sourceUrl)) {
                    return $image;
                }
            }
        }

        return null;
    }

    private function wordPressUploadPathsMatch(string $left, string $right): bool
    {
        $leftPath = strtolower(rtrim((string) parse_url(WordPressImageUrl::toFullSize($left), PHP_URL_PATH), '/'));
        $rightPath = strtolower(rtrim((string) parse_url(WordPressImageUrl::toFullSize($right), PHP_URL_PATH), '/'));

        return $leftPath !== '' && $leftPath === $rightPath;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateSlug(Site $site, int $attachmentId, string $newSlug, string $oldUrl = ''): array
    {
        $newSlug = Str::slug($newSlug);
        if ($attachmentId <= 0 || $newSlug === '') {
            return [
                'success' => false,
                'message' => 'Attachment ID hoặc slug không hợp lệ.',
            ];
        }

        $result = app(WordPressAttachmentRenameService::class)->renameForSite($site, [
            [
                'attachment_id' => $attachmentId,
                'new_slug' => $newSlug,
                'old_url' => $oldUrl,
            ],
        ]);

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? 'Không đổi tên được.'),
        ];
    }

    /**
     * @return array{success: bool, message: string, scope?: string}
     */
    public function deleteAttachment(Site $site, int $attachmentId): array
    {
        if ($blocked = $this->blockWhenSlugFixRequired($site, 'attachment.delete')) {
            return $blocked;
        }

        if ($attachmentId <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu ID ảnh WordPress.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress của domain.',
            ];
        }

        try {
            $response = $this->requestDeleteAttachment($base, $writeToken, $attachmentId);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());
                $code = strtolower(trim((string) ($response->json('code') ?? '')));
                if ($response->status() === 404 && (
                    $code === 'rest_no_route'
                    || str_contains(strtolower($message), 'no route')
                    || str_contains($message, 'đường dẫn nào phù hợp')
                )) {
                    $message = 'Plugin TVH SEO AI Bridge trên WordPress chưa có API xóa ảnh. '
                        .'Cập nhật plugin lên bản 1.0.12+ (WP Admin → TVH SEO AI → Kiểm tra cập nhật).';
                }

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $body = $response->json();
            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($body['message'] ?? 'WordPress từ chối xóa attachment.'),
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($body['message'] ?? 'Đã xóa ảnh trên WordPress.'),
                'scope' => 'wordpress',
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress media attachment delete failed', [
                'site_id' => $site->id,
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{images: list<array<string, mixed>>, total: int, total_pages: int, page: int, error: string|null}
     */
    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveWordPressThumbnailUrl(array $item, string $fallback): string
    {
        $sizes = $item['media_details']['sizes'] ?? null;
        if (! is_array($sizes)) {
            return \Omnichannel\Addons\WordPress\Support\WordPressImageUrl::toPreviewSize($fallback);
        }

        foreach (['medium', 'woocommerce_thumbnail', 'thumbnail', 'medium_large', 'large'] as $size) {
            $candidate = $sizes[$size]['source_url'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return \Omnichannel\Addons\WordPress\Support\WordPressImageUrl::toPreviewSize($fallback);
    }

    private function emptyResult(int $page, ?string $error): array
    {
        return [
            'images' => [],
            'total' => 0,
            'total_pages' => 1,
            'page' => max(1, $page),
            'error' => $error,
        ];
    }

    /**
     * @param  list<int>  $includeAttachmentIds
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    private function fetchIncludedAttachments(
        Site $site,
        array $includeAttachmentIds,
        ?string $filterMonth,
        int $page,
        int $perPage,
        ?string $search,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $includeAttachmentIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return $this->emptyResult($page, null);
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return $this->emptyResult($page, 'Thiếu Read token trên domain. Cấu hình tại Danh sách tên miền.');
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return $this->emptyResult($page, 'Không xác định được URL WordPress của domain.');
        }

        $images = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            try {
                $response = Http::timeout(60)
                    ->acceptJson()
                    ->withToken($readToken)
                    ->get($base.'/wp-json/wp/v2/media', [
                        'include' => implode(',', $chunk),
                        'per_page' => count($chunk),
                        'orderby' => 'include',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                if (! is_array($payload)) {
                    continue;
                }

                foreach ($payload as $item) {
                    $mapped = $this->mapWordPressMediaItem(is_array($item) ? $item : null);
                    if ($mapped !== null) {
                        $images[] = $mapped;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('WordPress restricted media fetch failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $images = $this->editedPending->applyPendingEditsToWordPressImages((int) $site->id, $images);
        $images = $this->filterWordPressImages($images, $filterMonth, $search);

        usort(
            $images,
            static fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')),
        );

        $total = count($images);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'images' => array_values(array_slice($images, $offset, $perPage)),
            'total' => $total,
            'total_pages' => $totalPages,
            'page' => $page,
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function filterWordPressImages(array $images, ?string $filterMonth, ?string $search): array
    {
        $filterMonth = trim((string) $filterMonth);
        $monthPrefix = '';
        if ($filterMonth !== '') {
            try {
                $monthPrefix = Carbon::createFromFormat('Y-m', $filterMonth)->format('Y-m');
            } catch (Throwable) {
                $monthPrefix = '';
            }
        }

        $search = mb_strtolower(trim((string) $search));

        return array_values(array_filter($images, function (array $image) use ($monthPrefix, $search): bool {
            if ($monthPrefix !== '') {
                $date = (string) ($image['date'] ?? '');
                if ($date === '' || ! str_starts_with($date, $monthPrefix)) {
                    return false;
                }
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                (string) ($image['slug'] ?? ''),
                (string) ($image['title'] ?? ''),
                (string) ($image['alt'] ?? ''),
            ])));

            return str_contains($haystack, $search);
        }));
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array<string, mixed>|null
     */
    private function mapWordPressMediaItem(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }

        $id = (int) ($item['id'] ?? 0);
        $url = trim((string) ($item['source_url'] ?? ''));
        $thumbUrl = $this->resolveWordPressThumbnailUrl($item, $url);
        if ($id <= 0 || $url === '') {
            return null;
        }

        $title = $item['title'] ?? '';
        if (is_array($title)) {
            $title = (string) ($title['rendered'] ?? '');
        }

        $alt = (string) ($item['alt_text'] ?? '');
        if ($alt === '' && is_array($item['meta'] ?? null)) {
            $alt = (string) ($item['meta']['_wp_attachment_image_alt'] ?? '');
        }

        return [
            'kind' => 'wordpress',
            'id' => $id,
            'wp_attachment_id' => $id,
            'seo_media_id' => 0,
            'url' => $url,
            'thumb_url' => $thumbUrl,
            'media_type' => $this->resolveWordPressMediaType($item),
            'slug' => trim((string) ($item['slug'] ?? '')),
            'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'alt' => $alt,
            'date' => (string) ($item['date'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveWordPressMediaType(array $item): string
    {
        $mediaType = strtolower(trim((string) ($item['media_type'] ?? '')));
        if ($mediaType !== '') {
            return $mediaType === 'video' ? 'video' : 'image';
        }

        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return 'image';
    }

    private function requestDeleteAttachment(string $base, string $writeToken, int $attachmentId): \Illuminate\Http\Client\Response
    {
        $client = Http::timeout(45)
            ->acceptJson()
            ->withToken($writeToken);

        $attempts = [
            fn () => $client->post($base.'/wp-json/omi-seo-ai/v1/attachments/'.$attachmentId.'/delete'),
            fn () => $client->post($base.'/wp-json/omi-seo-ai/v1/attachments/delete', [
                'attachment_id' => $attachmentId,
            ]),
            fn () => $client->delete($base.'/wp-json/omi-seo-ai/v1/attachments/'.$attachmentId),
        ];

        $lastResponse = null;
        foreach ($attempts as $attempt) {
            $response = $attempt();
            $lastResponse = $response;

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() !== 404) {
                return $response;
            }
        }

        return $lastResponse ?? $client->delete($base.'/wp-json/omi-seo-ai/v1/attachments/'.$attachmentId);
    }

    /**
     * @return array{success: false, message: string, error_code: string}|null
     */
    private function blockWhenSlugFixRequired(Site $site, string $operation): ?array
    {
        try {
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($site, $operation);

            return null;
        } catch (WordPressSlugFixRequiredException) {
            return [
                'success' => false,
                'message' => WordPressSlugFixRequiredException::MESSAGE,
                'error_code' => WordPressSlugFixRequiredException::ERROR_CODE,
            ];
        }
    }
}
