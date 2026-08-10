<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Support\AssistantWidgetHealthRules;
use Omnichannel\Addons\Seo\Support\SeoReasonPresentation;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Canonical analysis policy for Article Editor immediate client analysis (Phase 2B).
 * Laravel owns thresholds / reason registry; React computes immediate results.
 */
final class ArticleEditorAnalysisPolicyService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly SeoPromptSettingsService $promptSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $postType = ArticlePostTypeResolver::resolve($article);
        $isProduct = $postType === SeoProjectTask::POST_TYPE_PRODUCT
            || strtolower(trim((string) ($article->articleMetas->firstWhere('meta_key', 'canary_type')?->meta_value ?? ''))) === 'product_gallery';

        $lengthProduct = $this->promptSettings->resolveArticleLengthTarget('product');
        $lengthDefault = $this->promptSettings->resolveArticleLengthTarget('article');
        $minimumWords = $isProduct ? $lengthProduct : $lengthDefault;

        return [
            'version' => self::SCHEMA_VERSION,
            'content' => [
                'minimum_words' => $minimumWords,
                'target_words' => $minimumWords,
                'article_length_product' => $lengthProduct,
                'article_length_default' => $lengthDefault,
                'count_headings' => true,
                'count_faq_answers' => false,
                'count_captions' => false,
                'count_media_metadata' => false,
            ],
            'links' => [
                'minimum_valid_links' => AssistantWidgetHealthRules::MIN_VALID_HTTP_LINKS,
                'count_tel' => false,
                'count_mailto' => false,
                'count_cta_contact' => false,
            ],
            'images' => [
                'words_per_image' => SeoReasonPresentation::TARGET_WORDS_PER_IMAGE,
                'minimum_images' => 1,
                'count_featured' => false,
                'count_gallery' => false,
                'ratio_severity' => 'info',
            ],
            'featured' => [
                'required' => true,
                'alt_required' => true,
            ],
            'gallery' => [
                'required' => $isProduct,
                'required_for_article_types' => [SeoProjectTask::POST_TYPE_PRODUCT],
            ],
            'keywords' => [
                'focus_keyword_required' => true,
            ],
            'featured_snippet_thresholds' => $this->promptSettings->getFeaturedSnippetThresholds(),
            'reason_codes' => $this->reasonRegistry(),
            'reason_aliases' => $this->reasonAliases(),
            'seo_scoring_rules' => SeoScoringRulesRegistry::publicRulesForClient(),
            'article_type' => $postType,
        ];
    }

    /**
     * Lightweight external facts for reasons React cannot compute alone.
     *
     * @return array<string, mixed>
     */
    public function externalFacts(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $wikiMeta = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wiki_trust_checked')?->meta_value ?? ''));

        return [
            'trust' => [
                'has_trusted_source' => in_array(strtolower($wikiMeta), ['1', 'true', 'yes'], true),
                'checked_at' => null,
                'source' => 'server',
                'refresh_required' => $wikiMeta === '',
            ],
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reasonRegistry(): array
    {
        return [
            'content_length_low' => [
                'default_severity' => 'warning',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.content_length_low',
            ],
            'image_ratio_low' => [
                'default_severity' => 'info',
                'widget' => 'images',
                'locale_key' => 'seo_rules.image_ratio_low',
            ],
            'image_ratio_missing' => [
                'default_severity' => 'warning',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.image_ratio_missing',
            ],
            'image_ratio_poor' => [
                'default_severity' => 'warning',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.image_ratio_poor',
            ],
            'image_ratio_suboptimal' => [
                'default_severity' => 'info',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.image_ratio_suboptimal',
            ],
            'links_below_minimum' => [
                'default_severity' => 'error',
                'widget' => 'links',
                'locale_key' => 'seo_rules.links_below_minimum',
            ],
            'missing_focus_keyword' => [
                'default_severity' => 'error',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.missing_focus_keyword',
            ],
            'focus_keyword_missing' => [
                'default_severity' => 'error',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.missing_focus_keyword',
            ],
            'featured_missing' => [
                'default_severity' => 'error',
                'widget' => 'featured',
                'locale_key' => 'seo_rules.featured_missing',
            ],
            'gallery_missing' => [
                'default_severity' => 'warning',
                'widget' => 'gallery',
                'locale_key' => 'seo_rules.gallery_missing',
            ],
            'wiki_trust_missing' => [
                'default_severity' => 'warning',
                'widget' => 'seo',
                'locale_key' => 'seo_rules.wiki_trust_missing',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function reasonAliases(): array
    {
        return [
            'seo.length' => 'content_length_low',
            'seo_rules.content_length_low' => 'content_length_low',
            'seo.image_ratio' => 'image_ratio_missing',
            'focus_keyword_missing' => 'missing_focus_keyword',
            // Widget info alias stays image_ratio_low; SEO score still uses tiered codes.
        ];
    }
}
