<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * WordPress platform post_type identity (raw slug) for synced + editor surfaces.
 *
 * Distinct from {@see SeoProjectTask::normalizePostType()} which collapses unknowns
 * (including `page`) into Content Project planning vocabulary.
 *
 * - Raw WP identity: post | page | product | CPT slug | taxonomy slug
 * - Canonical content_type: derived via {@see ArticleContentClassification} / mapper
 */
final class ArticleWordPressPostType
{
    /**
     * Authoritative raw WordPress post_type for an article.
     * Prefer article_meta.wp_post_type; fall back to legacy type / content_type buckets.
     */
    public static function resolve(SeoArticle $article): string
    {
        $classification = ArticleContentClassification::for($article);
        $native = strtolower(trim((string) ($classification->wpPostType() ?? '')));
        if ($native !== '') {
            return $native;
        }

        $legacyRaw = strtolower(trim((string) ($article->type ?? '')));
        if ($legacyRaw !== '' && $legacyRaw !== SeoProjectTask::POST_TYPE_ARTICLE) {
            return self::normalizeEditorInput($legacyRaw);
        }

        return match ($classification->contentType()) {
            ContentType::Product => $classification->isTerm() ? 'product_cat' : 'product',
            ContentType::Page => 'page',
            ContentType::Post => $classification->isTerm() ? 'category' : 'post',
        };
    }

    /**
     * Normalize editor / publish-box input without collapsing `page` → `post`.
     * Accepts task labels (`article`) and raw WP slugs / CPT / taxonomy keys.
     */
    public static function normalizeEditorInput(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || $normalized === SeoProjectTask::POST_TYPE_ARTICLE) {
            return 'post';
        }

        if ($normalized === 'e-commerce') {
            return 'product';
        }

        if ($normalized === SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY || $normalized === 'product_cat') {
            return 'product_cat';
        }

        return $normalized;
    }

    /**
     * Build classification for Edit Article persist from editor selection.
     *
     * @return array{content_type: ContentType, wp_is_term: bool, wp_post_type: string, parent_id: int|null}
     */
    public static function classificationForEditor(SeoArticle $article, mixed $editorPostType): array
    {
        $raw = self::normalizeEditorInput($editorPostType);
        $current = ArticleContentClassification::for($article);
        $hasWpCounterpart = (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0
            || (int) ($article->wp_post_id ?? 0) > 0;

        // Task / editor vocabulary → classification seed (may overwrite CPT when type switches).
        $seed = match ($raw) {
            'product' => ArticleContentClassification::fromTaskPostType(SeoProjectTask::POST_TYPE_PRODUCT),
            'page' => ArticleContentClassification::fromTaskPostType('page'),
            'category' => ArticleContentClassification::fromTaskPostType(SeoProjectTask::POST_TYPE_CATEGORY),
            'product_cat', 'product_category' => ArticleContentClassification::fromTaskPostType(
                SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
            ),
            'post' => ArticleContentClassification::fromTaskPostType(SeoProjectTask::POST_TYPE_POST),
            default => ArticleContentClassification::fromSyncItem([
                'wp_post_type' => $raw,
                'wp_is_term' => $current->isTerm(),
            ]),
        };

        // Synced article: preserve raw CPT slug when semantic bucket + term flag unchanged.
        if (
            $hasWpCounterpart
            && $current->wpPostType() !== null
            && $current->wpPostType() !== ''
            && $current->contentType() === $seed['content_type']
            && $current->isTerm() === $seed['wp_is_term']
        ) {
            $seed['wp_post_type'] = $current->wpPostType();
        }

        // Local/unsynced: keep existing wp_post_type only when still matching; otherwise write seed.
        if (
            ! $hasWpCounterpart
            && $current->wpPostType() !== null
            && $current->wpPostType() !== ''
            && $current->contentType() === $seed['content_type']
            && $current->isTerm() === $seed['wp_is_term']
        ) {
            $seed['wp_post_type'] = $current->wpPostType();
        }

        // Editor never re-parents a term.
        if ($seed['wp_is_term'] && $article->parent_id !== null) {
            unset($seed['parent_id']);
        }

        return $seed;
    }

    public static function isProductLike(string $rawOrEditorType): bool
    {
        $raw = self::normalizeEditorInput($rawOrEditorType);

        if (in_array($raw, ['product', 'e-commerce'], true)) {
            return true;
        }

        if (in_array($raw, ['product_cat', 'product_category', 'product_tag', 'category'], true)) {
            return false;
        }

        return NativeContentTypeMapper::map($raw) === ContentType::Product;
    }
}
