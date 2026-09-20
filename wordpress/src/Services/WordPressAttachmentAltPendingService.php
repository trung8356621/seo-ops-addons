<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Support\MediaAltOwnership;
use Illuminate\Support\Facades\Log;

/**
 * Stage desired WordPress attachment ALT until article WP sync succeeds.
 *
 * Never mutates WordPress immediately — apply only after successful sync.
 */
final class WordPressAttachmentAltPendingService
{
    public const META_KEY = 'wp_attachment_alt_pending';

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, staged_count: int, skipped: array<int, array<string, mixed>>}
     */
    public function stage(SeoArticle $article, array $items): array
    {
        $existing = $this->readPending($article);
        $byAttachment = [];
        foreach ($existing as $row) {
            $id = (int) ($row['attachment_id'] ?? 0);
            if ($id > 0) {
                $byAttachment[$id] = $row;
            }
        }

        $stagedCount = 0;
        $skipped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = $this->normalizeStageItem($item);
            if ($normalized === null) {
                $skipped[] = [
                    'reason' => 'invalid_or_forbidden_role',
                    'item' => $item,
                ];

                continue;
            }

            $attachmentId = $normalized['attachment_id'];
            $byAttachment[$attachmentId] = $normalized;
            $stagedCount++;
        }

        $this->writePending($article, array_values($byAttachment));

        return [
            'success' => true,
            'message' => $stagedCount > 0
                ? sprintf('Đã xếp hàng %d ALT WordPress (áp dụng sau khi sync thành công).', $stagedCount)
                : 'Không có ALT WordPress hợp lệ để xếp hàng.',
            'staged_count' => $stagedCount,
            'skipped' => $skipped,
        ];
    }

    /**
     * Apply staged ALT updates only after article WordPress sync succeeded.
     *
     * @return array{attempted: bool, success: bool, message: string, updated_count: int, skipped: array<int, mixed>}
     */
    public function applyAfterSuccessfulSync(SeoArticle $article): array
    {
        $pending = $this->readPending($article);
        if ($pending === []) {
            return [
                'attempted' => false,
                'success' => true,
                'message' => '',
                'updated_count' => 0,
                'skipped' => [],
            ];
        }

        $eligible = [];
        $skipped = [];

        foreach ($pending as $item) {
            $role = MediaAltOwnership::normalizeRole($item['media_role'] ?? null);
            if ($role === null || ! MediaAltOwnership::isWpAltMutationRole($role)) {
                $skipped[] = [
                    'attachment_id' => (int) ($item['attachment_id'] ?? 0),
                    'reason' => 'forbidden_role',
                    'media_role' => $role,
                ];

                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? 0);
            $desiredAlt = trim((string) ($item['desired_alt'] ?? ''));
            if ($attachmentId <= 0 || $desiredAlt === '') {
                $skipped[] = [
                    'attachment_id' => $attachmentId,
                    'reason' => 'missing_identity_or_alt',
                ];

                continue;
            }

            $eligible[] = [
                'attachment_id' => $attachmentId,
                'media_role' => $role,
                'alt_text' => $desiredAlt,
                'title' => $desiredAlt,
                'fill_only_if_empty' => (bool) ($item['fill_only_if_empty'] ?? true),
            ];
        }

        if ($eligible === []) {
            // Drop forbidden/invalid leftovers so they cannot apply later.
            $this->clearPending($article);

            return [
                'attempted' => true,
                'success' => true,
                'message' => 'Không có ALT WordPress đủ điều kiện để áp dụng.',
                'updated_count' => 0,
                'skipped' => $skipped,
            ];
        }

        $result = app(WordPressAttachmentMetaUpdateService::class)->updateBatch($article, $eligible, [
            'require_media_role' => true,
        ]);

        if (! ($result['success'] ?? false)) {
            Log::warning('WordPress pending attachment ALT apply failed; leaving pending intact', [
                'article_id' => (int) $article->id,
                'message' => (string) ($result['message'] ?? ''),
            ]);

            return [
                'attempted' => true,
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Không áp dụng được ALT WordPress sau sync.'),
                'updated_count' => 0,
                'skipped' => $skipped,
            ];
        }

        $this->clearPending($article);

        return [
            'attempted' => true,
            'success' => true,
            'message' => (string) ($result['message'] ?? ''),
            'updated_count' => (int) ($result['updated_count'] ?? 0),
            'skipped' => array_merge($skipped, is_array($result['errors'] ?? null) ? $result['errors'] : []),
        ];
    }

    /**
     * @return list<array{attachment_id: int, media_role: string, desired_alt: string, fill_only_if_empty: bool, alt_owner: string}>
     */
    public function readPending(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = trim((string) ($article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeStageItem($row);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    public function clearPending(SeoArticle $article): void
    {
        $article->articleMetas()->where('meta_key', self::META_KEY)->delete();
        $article->unsetRelation('articleMetas');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writePending(SeoArticle $article, array $items): void
    {
        if ($items === []) {
            $this->clearPending($article);

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        $article->unsetRelation('articleMetas');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{attachment_id: int, media_role: string, desired_alt: string, fill_only_if_empty: bool, alt_owner: string}|null
     */
    private function normalizeStageItem(array $item): ?array
    {
        $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
        $role = MediaAltOwnership::normalizeRole($item['media_role'] ?? $item['mediaRole'] ?? null);
        $desiredAlt = trim((string) ($item['desired_alt'] ?? $item['alt_text'] ?? $item['alt'] ?? ''));

        if ($attachmentId <= 0 || $desiredAlt === '' || $role === null) {
            return null;
        }

        if (! MediaAltOwnership::isWpAltMutationRole($role)) {
            return null;
        }

        return [
            'attachment_id' => $attachmentId,
            'media_role' => $role,
            'desired_alt' => $desiredAlt,
            'fill_only_if_empty' => array_key_exists('fill_only_if_empty', $item)
                ? (bool) $item['fill_only_if_empty']
                : true,
            'alt_owner' => MediaAltOwnership::altOwnerForRole($role),
        ];
    }
}
