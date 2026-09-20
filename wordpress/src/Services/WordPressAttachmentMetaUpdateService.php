<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Support\MediaAltOwnership;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WordPressAttachmentMetaUpdateService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{require_media_role?: bool}  $options
     * @return array{success: bool, message: string, updated_count?: int, updated?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function updateBatch(SeoArticle $article, array $items, array $options = []): array
    {
        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain.',
            ];
        }

        return $this->updateForSite($site, $items, $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{require_media_role?: bool}  $options
     * @return array{success: bool, message: string, updated_count?: int, updated?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function updateForSite(Site $site, array $items, array $options = []): array
    {
        if ($blocked = $this->blockWhenSlugFixRequired($site, 'attachment.update_meta')) {
            return $blocked;
        }

        $requireMediaRole = (bool) ($options['require_media_role'] ?? true);
        $normalized = $this->normalizeItems($items, $requireMediaRole);
        if ($normalized === []) {
            return [
                'success' => false,
                'message' => $requireMediaRole
                    ? 'Không có attachment WordPress hợp lệ với media_role được phép (featured_image / product_gallery).'
                    : 'Không có attachment WordPress hợp lệ để cập nhật alt/title.',
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

        $url = $this->buildUpdateUrl($site);
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

            $updatedCount = (int) ($payload['updated_count'] ?? 0);
            $errorCount = (int) ($payload['error_count'] ?? 0);

            if ($updatedCount === 0 && $errorCount > 0) {
                $firstError = is_array($payload['errors'][0] ?? null)
                    ? (string) ($payload['errors'][0]['message'] ?? 'Không cập nhật được alt/title.')
                    : 'Không cập nhật được alt/title.';

                return [
                    'success' => false,
                    'message' => $firstError,
                    'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
                ];
            }

            return [
                'success' => true,
                'message' => sprintf('Đã cập nhật alt/title cho %d ảnh trên WordPress.', $updatedCount),
                'updated_count' => $updatedCount,
                'updated' => is_array($payload['updated'] ?? null) ? $payload['updated'] : [],
                'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
            ];
        } catch (Throwable $e) {
            Log::error('WordPress attachment meta update failed', [
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
     * @return array<int, array{attachment_id: int, alt_text: string, title: string, media_role: string, fill_only_if_empty: bool}>
     */
    private function normalizeItems(array $items, bool $requireMediaRole): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
            $altText = trim((string) ($item['alt_text'] ?? $item['alt'] ?? $item['desired_alt'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $role = MediaAltOwnership::normalizeRole($item['media_role'] ?? $item['mediaRole'] ?? null);

            if ($attachmentId <= 0 || ($altText === '' && $title === '')) {
                continue;
            }

            // Hard safety: attachment_id alone is never enough.
            if ($requireMediaRole) {
                if ($role === null || ! MediaAltOwnership::isWpAltMutationRole($role)) {
                    Log::info('Skipped WordPress attachment ALT update — media_role not allowed', [
                        'attachment_id' => $attachmentId,
                        'media_role' => $role,
                    ]);

                    continue;
                }
            } elseif ($role !== null && $role === MediaAltOwnership::ROLE_ARTICLE_CONTENT) {
                // Even unscoped Media Library calls must never carry article_content.
                Log::info('Rejected WordPress attachment ALT update with article_content role', [
                    'attachment_id' => $attachmentId,
                ]);

                continue;
            }

            $normalized[] = [
                'attachment_id' => $attachmentId,
                'alt_text' => $altText,
                'title' => $title !== '' ? $title : $altText,
                'media_role' => $role ?? '',
                'fill_only_if_empty' => array_key_exists('fill_only_if_empty', $item)
                    ? (bool) $item['fill_only_if_empty']
                    : $requireMediaRole,
            ];
        }

        return $normalized;
    }

    private function buildUpdateUrl(Site $site): string
    {
        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/attachments/update-meta';
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
