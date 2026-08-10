<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

/**
 * Output contract ổn định cho Group 2 article actions (scalar only).
 */
final class ArticleActionOutputNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   entity_id: int|null,
     *   article_id: int|null,
     *   site_id: int|null,
     *   status: string|null,
     *   changed: bool,
     *   changed_fields: list<string>,
     *   deduplicated: bool,
     *   content_hash: string|null,
     *   updated_at: string|null
     * }
     */
    public function create(array $raw): array
    {
        $articleId = isset($raw['article_id']) ? (int) $raw['article_id'] : null;
        if ($articleId !== null && $articleId <= 0) {
            $articleId = null;
        }
        $dedup = (bool) ($raw['deduplicated'] ?? false);
        $wouldCreate = (bool) ($raw['would_create'] ?? (! $dedup));

        return [
            'entity_id' => $articleId,
            'article_id' => $articleId,
            'site_id' => isset($raw['site_id']) ? (int) $raw['site_id'] : null,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
            'post_type' => isset($raw['post_type']) ? (string) $raw['post_type'] : null,
            // Shadow plan chưa biết id mới: changed = sẽ/đã tạo (không phụ thuộc id).
            'changed' => ! $dedup && $wouldCreate,
            'changed_fields' => $dedup ? [] : ['article'],
            'deduplicated' => $dedup,
            'would_create' => ! $dedup && $wouldCreate,
            'content_hash' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   entity_id: int|null,
     *   article_id: int|null,
     *   status: string|null,
     *   changed: bool,
     *   changed_fields: list<string>,
     *   deduplicated: bool,
     *   content_hash: string|null,
     *   updated_at: string|null
     * }
     */
    public function content(array $raw): array
    {
        $articleId = isset($raw['article_id']) ? (int) $raw['article_id'] : null;
        $noop = (bool) ($raw['noop'] ?? false);
        $fields = is_array($raw['changed_fields'] ?? null)
            ? array_values(array_map('strval', $raw['changed_fields']))
            : ($noop ? [] : ['content', 'title']);

        return [
            'entity_id' => $articleId,
            'article_id' => $articleId,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
            'changed' => ! $noop && $fields !== [],
            'changed_fields' => $fields,
            'deduplicated' => $noop,
            'content_hash' => isset($raw['content_hash']) ? (string) $raw['content_hash'] : null,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   entity_id: int|null,
     *   article_id: int|null,
     *   status: string|null,
     *   changed: bool,
     *   changed_fields: list<string>,
     *   deduplicated: bool,
     *   content_hash: string|null,
     *   updated_at: string|null,
     *   seo_analysis_pending: bool
     * }
     */
    public function seoMeta(array $raw): array
    {
        $articleId = isset($raw['article_id']) ? (int) $raw['article_id'] : null;
        $fields = is_array($raw['changed_fields'] ?? null)
            ? array_values(array_map('strval', $raw['changed_fields']))
            : [];
        $noop = (bool) ($raw['noop'] ?? ($fields === []));

        return [
            'entity_id' => $articleId,
            'article_id' => $articleId,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
            'changed' => ! $noop && $fields !== [],
            'changed_fields' => $fields,
            'deduplicated' => $noop,
            'content_hash' => null,
            'updated_at' => isset($raw['updated_at']) ? (string) $raw['updated_at'] : null,
            'seo_analysis_pending' => (bool) ($raw['seo_analysis_pending'] ?? false),
            'slug' => isset($raw['slug']) ? (string) $raw['slug'] : null,
            'focus_keyword' => isset($raw['focus_keyword']) ? (string) $raw['focus_keyword'] : null,
        ];
    }
}
