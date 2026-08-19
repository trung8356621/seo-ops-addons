<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;


use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
/**
 * Canonical inventory of article_meta keys known to SeoContentAi code.
 *
 * Classification:
 * - canonical: active SoT (or primary source feeding projection columns)
 * - compatibility: dual-read/write or WP mirror still needed
 * - cache: rebuildable projection / WP mirror
 * - runtime: ephemeral flags/fingerprints
 * - legacy: superseded; migrate before delete
 * - debug: audit/canary only
 * - orphan: zero active readers (cleanup candidate) or writer-missing planned keys
 */
final class ArticleMetaKeyCatalog
{
    public const CLASS_CANONICAL = 'canonical';

    public const CLASS_COMPATIBILITY = 'compatibility';

    public const CLASS_CACHE = 'cache';

    public const CLASS_RUNTIME = 'runtime';

    public const CLASS_LEGACY = 'legacy';

    public const CLASS_DEBUG = 'debug';

    public const CLASS_ORPHAN = 'orphan';

    /**
     * Keys safe to delete when audit proves zero remaining readers and writers cut over.
     *
     * @return list<string>
     */
    public static function cleanupCandidates(): array
    {
        $keys = [];
        foreach (self::definitions() as $key => $meta) {
            if (($meta['class'] ?? '') === self::CLASS_ORPHAN && ($meta['cleanup'] ?? false) === true) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, array{
     *   purpose: string,
     *   class: string,
     *   cleanup: bool,
     *   canonical_replacement: ?string,
     *   writers: list<string>,
     *   readers: list<string>
     * }>
     */
    public static function definitions(): array
    {
        return [
            // Featured / media (distinct from body images + wp_post_images + thumb projection)
            'wp_featured_image_url' => [
                'purpose' => 'Featured image URL source for projection',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleMediaLocalService', 'SyncDomainContentService', 'WordPressArticleContentService'],
                'readers' => ['ArticleFeaturedImageResolver', 'ArticleResource', 'EditArticle'],
            ],
            'wp_featured_attachment_id' => [
                'purpose' => 'Local SeoMedia id for featured image',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleMediaLocalService'],
                'readers' => ['ArticleFeaturedImageResolver', 'MediaLibraryArticleResolver'],
            ],
            'wp_post_images' => [
                'purpose' => 'Cached body/post image inventory JSON (not featured)',
                'class' => self::CLASS_CACHE,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticlePostImagesService'],
                'readers' => ['ArticlePostImagesService', 'ArticleResource'],
            ],
            'wp_product_gallery' => [
                'purpose' => 'Product gallery URL list',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleMediaLocalService', 'SyncDomainContentService'],
                'readers' => ['WordPressArticleSyncService'],
            ],
            'wp_product_gallery_attachment_ids' => [
                'purpose' => 'Local gallery attachment ids',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleMediaLocalService'],
                'readers' => ['WordPressArticleSyncService'],
            ],
            'wp_media_pending_sync' => [
                'purpose' => 'Local media not yet pushed to WP',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleMediaLocalService'],
                'readers' => ['ArticleMediaLocalService'],
            ],

            // SEO score / audit
            'seo_rule_violations' => [
                'purpose' => 'Canonical flat violation keys for persisted score',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoAnalyzerService'],
                'readers' => ['SeoRuleViolationsResolver', 'ArticleListSeoSummary', 'ArticleResource'],
            ],
            SeoScoringRulesRegistry::META_KEY_ANALYZED_CONTENT_HASH => [
                'purpose' => 'Content hash bound to last persisted SEO score',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoAnalyzerService'],
                'readers' => ['ArticleEditorSeoPayloadService', 'ArticleListSeoSummary'],
            ],
            SeoScoringRulesRegistry::META_KEY_SCORE_VERSION => [
                'purpose' => 'Scoring contract version for persisted score',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoAnalyzerService'],
                'readers' => ['ArticleEditorSeoPayloadService'],
            ],
            SeoScoringRulesRegistry::META_KEY_SCORE_CALCULATED_AT => [
                'purpose' => 'ISO timestamp of last persisted SEO score',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoAnalyzerService'],
                'readers' => ['ArticleEditorSeoPayloadService'],
            ],
            'seo_scoring_status' => [
                'purpose' => 'Queue scoring status',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoScoringStatus'],
                'readers' => ['SeoAuditScanService', 'SeoArticleScoringQueueService'],
            ],
            'seo_scoring_fingerprint' => [
                'purpose' => 'Queue scoring fingerprint',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SeoScoringStatus'],
                'readers' => ['SeoScoringStatus'],
            ],
            'seo_rank_math_score' => [
                'purpose' => 'Legacy Rank Math score blob fallback',
                'class' => self::CLASS_LEGACY,
                'cleanup' => false,
                'canonical_replacement' => 'seo_rule_violations',
                'writers' => [],
                'readers' => ['SeoRuleViolationsResolver'],
            ],
            'seo_scoring_details' => [
                'purpose' => 'Legacy bonus/details blob — deleted Task 7 §N',
                'class' => self::CLASS_LEGACY,
                'cleanup' => true,
                'canonical_replacement' => 'seo_rule_violations',
                'writers' => [],
                'readers' => [],
            ],
            'seo_extracted_links' => [
                'purpose' => 'Legacy cached extracted links (keyword_link is SoT)',
                'class' => self::CLASS_LEGACY,
                'cleanup' => false,
                'canonical_replacement' => 'keyword_link',
                'writers' => [],
                'readers' => ['SeoArticle::resolveExtractedLinksFromLegacyMeta'],
            ],
            'skip_seo_audit' => [
                'purpose' => 'Soft-exclude from SEO audit tabs',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleResource', 'ArticlesOptimal'],
                'readers' => ['ArticleResource', 'ArticlesOptimal'],
            ],

            // SEO fields
            'seo_focus_keyword' => [
                'purpose' => 'Focus keyword phrase',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['KeywordFocusAttach', 'CreateArticlesFromTaskService'],
                'readers' => ['SeoAnalyzerService', 'ArticleListSeoSummary'],
            ],
            'seo_meta_description' => [
                'purpose' => 'Canonical meta description',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['EditArticle', 'ArticleEditorBundleApplyService'],
                'readers' => ['ArticleEditorSeoPayloadService', 'SeoAnalyzerService'],
            ],
            'meta_description' => [
                'purpose' => 'Alias dual-write of meta description',
                'class' => self::CLASS_COMPATIBILITY,
                'cleanup' => false,
                'canonical_replacement' => 'seo_meta_description',
                'writers' => ['EditArticle', 'ArticleEditorBundleApplyService'],
                'readers' => ['ArticleEditorSeoPayloadService'],
            ],
            'seo_title' => [
                'purpose' => 'WP SEO title mirror — deleted Task 7 §N; use articles.title',
                'class' => self::CLASS_COMPATIBILITY,
                'cleanup' => true,
                'canonical_replacement' => 'articles.title',
                'writers' => [],
                'readers' => [],
            ],

            // Content / sync
            'wp_post_content' => [
                'purpose' => 'Cached WP/HTML body when articles.body empty (COMPAT one release)',
                'class' => self::CLASS_CACHE,
                'cleanup' => false,
                'canonical_replacement' => 'articles.body',
                'writers' => ['WordPressArticleContentService', 'SyncDomainContentService'],
                'readers' => ['SeoAnalyzerService', 'ArticleEditorPersistService', 'WordPressArticleContentService'],
            ],
            'wp_post_type' => [
                'purpose' => 'Raw WordPress post_type slug (post, page, product, custom CPT)',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['SyncDomainContentService'],
                'readers' => ['McpEligibleContentScope', 'DomainOverviewService'],
            ],
            'wp_entity' => [
                'purpose' => 'post vs term entity',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['WordPressArticleSyncService', 'ArticleEditorBundleApplyService'],
                'readers' => ['WordPressArticleSyncService'],
            ],
            'wp_taxonomy' => [
                'purpose' => 'Taxonomy when entity=term',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['WordPressArticleSyncService', 'ArticleEditorBundleApplyService'],
                'readers' => ['WordPressArticleSyncService'],
            ],
            'wp_slug' => [
                'purpose' => 'Cached WP slug — deleted Task 7 §N; use articles.slug',
                'class' => self::CLASS_CACHE,
                'cleanup' => true,
                'canonical_replacement' => 'articles.slug',
                'writers' => [],
                'readers' => [],
            ],
            'wp_permalink' => [
                'purpose' => 'Cached public URL',
                'class' => self::CLASS_CACHE,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['WordPressArticleContentService'],
                'readers' => ['ArticleEditorSeoPayloadService', 'SeoAnalyzerService'],
            ],
            'category_ids' => [
                'purpose' => 'Local category article ids',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleEditorBundleApplyService', 'SyncDomainContentService'],
                'readers' => ['ArticleResource'],
            ],
            'wp_category_ids' => [
                'purpose' => 'WP category ids mirror',
                'class' => self::CLASS_COMPATIBILITY,
                'cleanup' => false,
                'canonical_replacement' => 'category_ids',
                'writers' => ['SyncDomainContentService'],
                'readers' => ['EditArticle'],
            ],
            'seo_local_content_hash' => [
                'purpose' => 'Local body hash after editor save',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleWordPressSyncFlagService'],
                'readers' => ['ArticleWordPressSyncFlagService'],
            ],
            'seo_published_content_hash' => [
                'purpose' => 'Hash after successful WP publish',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleWordPressSyncFlagService'],
                'readers' => ['ArticleWordPressSyncFlagService'],
            ],
            'seo_local_edit_pending' => [
                'purpose' => 'Local edits not reconciled with WP',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleWordPressSyncFlagService'],
                'readers' => ['ArticleEditorSavePatchService'],
            ],
            'wp_sync_queue' => [
                'purpose' => 'Queue projection JSON',
                'class' => self::CLASS_CANONICAL,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleWpSyncQueueService'],
                'readers' => ['ArticleResource'],
            ],
            'wp_data_out_of_sync' => [
                'purpose' => 'Local vs published mismatch flag',
                'class' => self::CLASS_RUNTIME,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => ['ArticleWordPressSyncFlagService'],
                'readers' => ['ArticleResource'],
            ],

            // Orphan cleanup candidates (zero readers in code)
            'seo_outline_json' => [
                'purpose' => 'Outline JSON from prompt test publish only',
                'class' => self::CLASS_ORPHAN,
                'cleanup' => true,
                'canonical_replacement' => 'seo_article_outline',
                'writers' => ['PromptTestPublishService'],
                'readers' => [],
            ],
            'seo_semantic_keywords' => [
                'purpose' => 'Semantic keywords from prompt test publish only',
                'class' => self::CLASS_ORPHAN,
                'cleanup' => true,
                'canonical_replacement' => 'seo_article_keywords',
                'writers' => ['PromptTestPublishService'],
                'readers' => [],
            ],
            'create_article_task_run' => [
                'purpose' => 'Stamp from create-from-task; no readers',
                'class' => self::CLASS_ORPHAN,
                'cleanup' => true,
                'canonical_replacement' => null,
                'writers' => ['CreateArticlesFromTaskService'],
                'readers' => [],
            ],
            'wp_post_title' => [
                'purpose' => 'Cached WP title; articles.title is SoT',
                'class' => self::CLASS_ORPHAN,
                'cleanup' => true,
                'canonical_replacement' => 'articles.title',
                'writers' => ['SyncDomainContentService', 'WordPressArticleContentService'],
                'readers' => [],
            ],

            // Planned / writer-missing — keep
            'seo_editor_unsaved_draft' => [
                'purpose' => 'Dirty-draft flag for inbound Site Sync',
                'class' => self::CLASS_ORPHAN,
                'cleanup' => false,
                'canonical_replacement' => null,
                'writers' => [],
                'readers' => ['SiteSyncDeltaEventIngestor'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_keys(self::definitions());
    }
}
