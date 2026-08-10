<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;

/**
 * Lưu bản nháp «apply» (outline/content) vào article_meta — KHÔNG viết trực tiếp vào
 * body/outline chính thức của bài viết. Editor tự đọc pending draft này để hiển thị/lưu.
 *
 * Payload shape:
 * article_id, artifact_ref, artifact_type, run_id, run_item_id, attempt, target
 * (outline|content), apply_mode=manual_debug_apply, payload, previous_payload,
 * applied_by, applied_at, provenance.
 */
final class ArticleAiHistoryPendingDraftStore
{
    public const META_KEY = 'seo_ai_history_pending_draft';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(SeoArticle $article, array $payload): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );

        $article->unsetRelation('articleMetas');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(SeoArticle $article): ?array
    {
        $raw = $article->articleMetas()
            ->where('meta_key', self::META_KEY)
            ->value('meta_value');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function clear(SeoArticle $article): void
    {
        $article->articleMetas()
            ->where('meta_key', self::META_KEY)
            ->delete();

        $article->unsetRelation('articleMetas');
    }

    /**
     * @return array{previous_outline: ?string, previous_body: ?string}
     */
    public function snapshotPrevious(SeoArticle $article, string $target): array
    {
        if ($target === 'outline') {
            $previousOutline = trim((string) ($article->articleMetas()
                ->where('meta_key', ArticleOutlineResolver::META_KEY)
                ->value('meta_value') ?? ''));

            return [
                'previous_outline' => $previousOutline,
                'previous_body' => null,
            ];
        }

        return [
            'previous_outline' => null,
            'previous_body' => trim((string) ($article->body ?? '')),
        ];
    }
}
