<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class ArticleWordPressPostTypeTest extends TestCase
{
    public function test_normalize_editor_input_keeps_page(): void
    {
        self::assertSame('page', ArticleWordPressPostType::normalizeEditorInput('page'));
        self::assertSame('post', ArticleWordPressPostType::normalizeEditorInput('article'));
        self::assertSame('post', ArticleWordPressPostType::normalizeEditorInput('post'));
        self::assertSame('product', ArticleWordPressPostType::normalizeEditorInput('product'));
        self::assertSame('portfolio', ArticleWordPressPostType::normalizeEditorInput('portfolio'));
    }

    public function test_normalize_post_type_task_helper_collapses_page_but_editor_helper_does_not(): void
    {
        self::assertSame('post', SeoProjectTask::normalizePostType('page'));
        self::assertSame('page', ArticleWordPressPostType::normalizeEditorInput('page'));
    }

    public function test_resolve_prefers_raw_wp_post_type(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'page',
        ]);

        self::assertSame('page', ArticleWordPressPostType::resolve($article));
    }

    public function test_resolve_falls_back_when_wp_post_type_missing(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
        ]);

        self::assertSame('page', ArticleWordPressPostType::resolve($article));
    }

    public function test_classification_for_editor_persists_page_as_page(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'page');

        self::assertSame(ContentType::Page, $classification['content_type']);
        self::assertSame('page', $classification['wp_post_type']);
        self::assertFalse($classification['wp_is_term']);
    }

    public function test_classification_preserves_cpt_when_semantic_bucket_unchanged(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'landing_page',
        ]);
        $article->setAttribute('wp_post_id', 42);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'page');

        self::assertSame(ContentType::Page, $classification['content_type']);
        self::assertSame('landing_page', $classification['wp_post_type']);
    }

    public function test_classification_switches_post_to_product(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'product');

        self::assertSame(ContentType::Product, $classification['content_type']);
        self::assertSame('product', $classification['wp_post_type']);
    }

    public function test_local_unsynced_article_uses_canonical_seed(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
        ]);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'product');

        self::assertSame(ContentType::Product, $classification['content_type']);
        self::assertSame('product', $classification['wp_post_type']);
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function article(array $metas): SeoArticle
    {
        $article = new SeoArticle;
        $article->type = '';
        $collection = new Collection;
        foreach ($metas as $key => $value) {
            $collection->push(new ArticleMeta([
                'meta_key' => $key,
                'meta_value' => $value,
            ]));
        }
        $article->setRelation('articleMetas', $collection);

        return $article;
    }
}
