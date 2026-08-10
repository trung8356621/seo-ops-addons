<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WordPressAttachmentRenameService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, renamed_count?: int, posts_updated?: int, renamed?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function renameBatch(SeoArticle $article, array $items): array
    {
        // Bulk WP rename from editor Fix Slug All is forbidden — use WordPressMediaRenameService.
        return [
            'success' => false,
            'message' => 'Ảnh WordPress cần đổi tên riêng (không dùng Fix Slug All).',
            'error_code' => 'wordpress_media_requires_explicit_rename',
            'renamed_count' => 0,
            'renamed' => [],
            'skipped_count' => count($items),
        ];
    }

    /**
     * Explicit single rename via WP plugin mode=explicit_single + strict collision.
     *
     * @param  array{attachment_id: int, new_slug: string, old_url?: string}  $item
     * @return array<string, mixed>
     */
    public function renameExplicitSingle(Site $site, array $item): array
    {
        if ($blocked = $this->blockWhenSlugFixRequired($site, 'attachment.rename_explicit_single')) {
            return $blocked;
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
                'error_code' => 'missing_token',
            ];
        }

        $url = $this->buildRenameUrl($site);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
                'error_code' => 'missing_wp_url',
            ];
        }

        $normalized = $this->normalizeItems([$item]);
        if ($normalized === []) {
            return [
                'success' => false,
                'message' => 'Không có ảnh WordPress hợp lệ để đổi tên.',
                'error_code' => 'invalid_item',
            ];
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, [
                    'mode' => 'explicit_single',
                    'acknowledge_url_change' => true,
                    'confirmation_phrase' => 'RENAME',
                    'items' => $normalized,
                ]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 400),
                    'error_code' => str_contains(mb_strtolower($message), 'already exists')
                        ? 'filename_collision'
                        : 'wp_http_error',
                ];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                    'error_code' => 'invalid_response',
                ];
            }

            $renamed = is_array($payload['renamed'] ?? null) ? $payload['renamed'] : [];
            $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
            $renamedCount = (int) ($payload['renamed_count'] ?? count($renamed));

            if ($renamedCount <= 0) {
                $firstError = is_array($errors[0] ?? null)
                    ? (string) ($errors[0]['message'] ?? 'Không đổi tên được.')
                    : 'Không đổi tên được.';

                return [
                    'success' => false,
                    'message' => $firstError,
                    'error_code' => str_contains(mb_strtolower($firstError), 'already exists')
                        ? 'filename_collision'
                        : 'wp_rename_failed',
                    'errors' => $errors,
                ];
            }

            return [
                'success' => true,
                'message' => (string) ($payload['message'] ?? 'Đã đổi tên ảnh WordPress.'),
                'renamed_count' => $renamedCount,
                'posts_updated' => (int) ($payload['posts_updated'] ?? 0),
                'renamed' => $renamed,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('wordpress_attachment_explicit_rename_failed', [
                'site_id' => (int) $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
                'error_code' => 'connection_failed',
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, renamed_count?: int, posts_updated?: int, renamed?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function renameForSite(Site $site, array $items): array
    {
        if ($blocked = $this->blockWhenSlugFixRequired($site, 'attachment.rename')) {
            return $blocked;
        }

        $normalized = $this->normalizeItems($items);
        if ($normalized === []) {
            return [
                'success' => false,
                'message' => 'Không có ảnh WordPress hợp lệ để đổi tên.',
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

        $url = $this->buildRenameUrl($site);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, ['items' => $normalized]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 400),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $renamedCount = (int) ($payload['renamed_count'] ?? 0);
            $postsUpdated = (int) ($payload['posts_updated'] ?? 0);
            $errorCount = (int) ($payload['error_count'] ?? 0);

            if ($renamedCount === 0 && $errorCount > 0) {
                $firstError = is_array($payload['errors'][0] ?? null)
                    ? (string) ($payload['errors'][0]['message'] ?? 'Không đổi tên được.')
                    : 'Không đổi tên được.';

                return [
                    'success' => false,
                    'message' => $firstError,
                    'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    'Đã đổi tên %d ảnh trên WordPress · cập nhật URL trong %d bài/trang.',
                    $renamedCount,
                    $postsUpdated,
                ),
                'renamed_count' => $renamedCount,
                'posts_updated' => $postsUpdated,
                'renamed' => is_array($payload['renamed'] ?? null) ? $payload['renamed'] : [],
                'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('wordpress_attachment_rename_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{attachment_id: int, new_slug: string, old_url: string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
            $newSlug = trim((string) ($item['new_slug'] ?? $item['slug'] ?? ''));
            $oldUrl = trim((string) ($item['old_url'] ?? $item['old_src'] ?? $item['src'] ?? ''));
            $blockId = trim((string) ($item['block_id'] ?? $item['blockId'] ?? ''));

            if ($attachmentId <= 0 || $newSlug === '') {
                continue;
            }

            $normalized[] = [
                'attachment_id' => $attachmentId,
                'new_slug' => $newSlug,
                'old_url' => $oldUrl,
                'block_id' => $blockId,
            ];
        }

        return $normalized;
    }

    private function buildRenameUrl(Site $site): string
    {
        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/attachments/rename';
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
