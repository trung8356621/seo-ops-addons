<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Contract + in-memory classification boundary tests (no MySQL required).
 */
final class ArticlePostTypeBoundaryRegressionTest extends TestCase
{
    public function test_edit_classification_post_to_page_keeps_raw_and_content_type(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);
        $article->setAttribute('wp_post_id', 101);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'page');

        self::assertSame(ContentType::Page, $classification['content_type']);
        self::assertSame('page', $classification['wp_post_type']);
    }

    public function test_edit_classification_page_to_post(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'page',
        ]);
        $article->setAttribute('wp_post_id', 102);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'post');

        self::assertSame(ContentType::Post, $classification['content_type']);
        self::assertSame('post', $classification['wp_post_type']);
    }

    public function test_edit_classification_post_to_product(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);
        $article->setAttribute('wp_post_id', 103);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'product');

        self::assertSame(ContentType::Product, $classification['content_type']);
        self::assertSame('product', $classification['wp_post_type']);
    }

    public function test_local_unsynced_article_uses_canonical_classification(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
        ]);

        $classification = ArticleWordPressPostType::classificationForEditor($article, 'product');

        self::assertSame(ContentType::Product, $classification['content_type']);
        self::assertSame('product', $classification['wp_post_type']);
    }

    public function test_legacy_article_without_wp_post_type_resolves_via_fallback(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
        ]);

        self::assertSame('page', ArticleWordPressPostType::resolve($article));
    }

    public function test_save_context_does_not_collapse_page_via_task_normalize(): void
    {
        $article = $this->article([
            'content_type' => 'page',
            'wp_is_term' => '0',
            'wp_post_type' => 'page',
        ]);

        $context = ArticleEditorSaveContext::fromBundle($article, [
            'article_meta' => ['title' => 'T', 'slug' => 't'],
            'publish_box' => ['post_type' => 'page', 'status' => 'draft'],
        ]);

        self::assertSame('page', $context->postType);
    }

    public function test_persist_and_filter_scope_source_keep_raw_wp_boundary(): void
    {
        $persist = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorPersistService.php',
        );
        $saveContext = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleEditorSaveContext.php',
        );
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );
        $linkReconciler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/site-sync/src/Services/Reconciliation/SiteLinkCatalogReconciler.php',
        );

        self::assertStringContainsString('ArticleWordPressPostType::classificationForEditor', $persist);
        self::assertStringNotContainsString('SeoProjectTask::normalizePostType($context->postType)', $persist);
        self::assertStringContainsString('ArticleWordPressPostType::normalizeEditorInput', $saveContext);
        self::assertStringContainsString('applyPostTypeFilterScope', $resource);
        self::assertStringContainsString("\$link['wp_post_type']", $linkReconciler);
        self::assertStringNotContainsString("\$link['type'] ?? 'article'", $linkReconciler);
    }

    public function test_link_catalog_prefers_wp_post_type_over_legacy_type(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/site-sync/src/Services/Reconciliation/SiteLinkCatalogReconciler.php',
        );

        self::assertStringContainsString("\$link['wp_post_type']", $source);
        self::assertStringContainsString("?? 'post'", $source);
        self::assertStringNotContainsString("\$link['type'] ?? 'article'", $source);
    }

    public function test_content_project_normalize_post_type_still_canonical_only(): void
    {
        // Boundary: Content Project task vocabulary remains separate SSOT.
        self::assertSame('post', \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::normalizePostType('page'));
        self::assertSame('page', ArticleWordPressPostType::normalizeEditorInput('page'));
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
