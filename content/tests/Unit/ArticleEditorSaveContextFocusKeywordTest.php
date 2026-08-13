<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Tests\TestCase;

/**
 * Laravel-only focus keyword must survive content/autosave bundles that omit or empty focus_keyword.
 * Explicit clear remains applySeoMetaOnly('') / SEO meta save — not fromBundle wipe.
 */
final class ArticleEditorSaveContextFocusKeywordTest extends TestCase
{
    public function test_from_bundle_falls_back_to_seo_focus_keyword_meta_when_missing(): void
    {
        $phrase = 'xuong may balo xuat khau';
        $article = $this->articleWithFocus($phrase);

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => [
                'title' => 'Laravel-only focus',
                'slug' => 'laravel-only-focus',
            ],
            'publish_box' => [
                'post_type' => 'article',
                'status' => 'draft',
                'visibility' => 'public',
            ],
        ]);

        self::assertSame($phrase, $context->focusKeyword);
    }

    public function test_from_bundle_empty_article_meta_focus_does_not_wipe_existing_meta(): void
    {
        $phrase = 'focus keep';
        $article = $this->articleWithFocus($phrase);

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => [
                'title' => 'Keep focus on empty wire',
                'slug' => 'keep-focus',
                'focus_keyword' => '',
                'seo_meta_description' => '',
            ],
            'publish_box' => [
                'post_type' => 'article',
                'status' => 'draft',
            ],
        ]);

        self::assertSame($phrase, $context->focusKeyword);
    }

    public function test_from_bundle_reads_top_level_focus_keyword_from_seo_owner(): void
    {
        $article = $this->articleWithFocus('');
        $phrase = 'top level phrase';

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => [
                'title' => 'Top level focus',
                'slug' => 'top-level-focus',
            ],
            'focus_keyword' => $phrase,
            'publish_box' => [
                'post_type' => 'article',
                'status' => 'draft',
            ],
        ]);

        self::assertSame($phrase, $context->focusKeyword);
    }

    public function test_from_bundle_prefers_non_empty_article_meta_over_db(): void
    {
        $article = $this->articleWithFocus('old keyword');

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => [
                'title' => 'Prefer meta',
                'slug' => 'prefer-meta',
                'focus_keyword' => 'new keyword',
            ],
            'publish_box' => [
                'post_type' => 'article',
                'status' => 'draft',
            ],
        ]);

        self::assertSame('new keyword', $context->focusKeyword);
    }

    public function test_from_bundle_does_not_require_wp_post_id_and_keeps_payload(): void
    {
        $phrase = 'local only keyword';
        $article = $this->articleWithFocus($phrase);

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => null,
            'publish_box' => [
                'post_type' => 'article',
                'status' => 'draft',
            ],
        ]);

        self::assertSame($phrase, $context->focusKeyword);
        self::assertSame($phrase, $context->seoPayloadForWordPress()['focus_keyword']);
    }

    private function articleWithFocus(string $phrase): SeoArticle
    {
        $article = new SeoArticle([
            'id' => 9670,
            'site_id' => 2,
            'title' => 'Laravel-only focus',
            'slug' => 'laravel-only-focus',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $metas = [];
        if ($phrase !== '') {
            $metas[] = (new ArticleMeta)->forceFill([
                'meta_key' => 'seo_focus_keyword',
                'meta_value' => $phrase,
            ]);
        }

        $article->setRelation('articleMetas', collect($metas));
        $article->setRelation('wordpressLink', null);
        $article->setRelation('publishingState', null);

        return $article;
    }
}
