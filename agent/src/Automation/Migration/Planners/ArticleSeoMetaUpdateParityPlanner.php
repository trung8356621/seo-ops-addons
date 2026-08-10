<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration\Planners;

use Illuminate\Support\Str;

/**
 * Shadow planner cho article.seo_meta.update — không ghi DB / queue / event.
 *
 * @phpstan-type MetaState array{
 *   article_id: int,
 *   status?: string,
 *   slug?: string,
 *   focus_keyword?: string,
 *   meta_description?: string,
 *   updated_at?: string|null
 * }
 */
final class ArticleSeoMetaUpdateParityPlanner
{
    /**
     * @param  array<string, mixed>  $input
     * @param  MetaState  $metaState
     * @return array<string, mixed>
     */
    public function plan(array $input, array $metaState): array
    {
        $articleId = (int) ($metaState['article_id'] ?? $input['article_id'] ?? 0);
        $focus = trim((string) ($input['focus_keyword'] ?? ''));
        $metaDescription = trim((string) ($input['meta_description'] ?? ''));
        $slugIn = trim((string) ($input['slug'] ?? ''));
        $normalizedSlug = $slugIn !== '' ? Str::slug($slugIn) : '';

        $currentFocus = trim((string) ($metaState['focus_keyword'] ?? ''));
        $currentMeta = trim((string) ($metaState['meta_description'] ?? ''));
        $currentSlug = trim((string) ($metaState['slug'] ?? ''));

        $fields = [];
        if ($focus !== '' && $focus !== $currentFocus) {
            $fields[] = 'focus_keyword';
        }
        if (array_key_exists('meta_description', $input) && $metaDescription !== $currentMeta) {
            $fields[] = 'meta_description';
        }
        if ($normalizedSlug !== '' && $normalizedSlug !== $currentSlug) {
            $fields[] = 'slug';
        }

        $noop = $fields === [];

        return [
            'article_id' => $articleId,
            'status' => (string) ($metaState['status'] ?? 'draft'),
            'focus_keyword' => $focus !== '' ? $focus : $currentFocus,
            'meta_description' => array_key_exists('meta_description', $input) ? $metaDescription : $currentMeta,
            'slug' => $normalizedSlug !== '' ? $normalizedSlug : $currentSlug,
            'noop' => $noop,
            'changed_fields' => $fields,
            'seo_analysis_pending' => ! $noop,
            'updated_at' => $metaState['updated_at'] ?? null,
            'would_persist' => ! $noop,
            'would_dispatch_scoring' => ! $noop,
            'would_mark_sync_pending_on_slug' => in_array('slug', $fields, true),
        ];
    }
}
