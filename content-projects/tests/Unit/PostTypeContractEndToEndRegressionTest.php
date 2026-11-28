<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Omnichannel\Addons\Agent\Automation\Actions\Project\CreateProjectTaskAction;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTaskPostTypeRecheckService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class PostTypeContractEndToEndRegressionTest extends TestCase
{
    public function test_resolve_canonical_for_article_covers_all_types_and_returns_null_when_unclassified(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService();

        $product = $this->makeArticleWithMetas(['content_type' => 'product', 'wp_post_type' => 'product', 'wp_is_term' => '0']);
        $post = $this->makeArticleWithMetas(['content_type' => 'post', 'wp_post_type' => 'post', 'wp_is_term' => '0']);
        $page = $this->makeArticleWithMetas(['content_type' => 'page', 'wp_post_type' => 'page', 'wp_is_term' => '0']);
        $category = $this->makeArticleWithMetas(['content_type' => 'post', 'wp_post_type' => 'category', 'wp_is_term' => '1']);
        $productCat = $this->makeArticleWithMetas(['content_type' => 'product', 'wp_post_type' => 'product_cat', 'wp_is_term' => '1']);
        $customCptProduct = $this->makeArticleWithMetas(['content_type' => 'product', 'wp_post_type' => 'machine', 'wp_is_term' => '0']);
        $customCptPost = $this->makeArticleWithMetas(['content_type' => 'post', 'wp_post_type' => 'portfolio', 'wp_is_term' => '0']);
        $unclassified = $this->makeArticleWithMetas([]);

        self::assertSame('product', $service->resolveCanonicalForArticle($product));
        self::assertSame('post', $service->resolveCanonicalForArticle($post));
        self::assertSame('post', $service->resolveCanonicalForArticle($page));
        self::assertSame('category', $service->resolveCanonicalForArticle($category));
        self::assertSame('product_category', $service->resolveCanonicalForArticle($productCat));
        self::assertSame('product', $service->resolveCanonicalForArticle($customCptProduct));
        self::assertSame('post', $service->resolveCanonicalForArticle($customCptPost));
        self::assertNull($service->resolveCanonicalForArticle($unclassified));

        // Native WP post_type is never lost on the Article itself.
        self::assertSame('page', ArticleWordPressPostType::resolve($page));
        self::assertSame('product_cat', ArticleWordPressPostType::resolve($productCat));
        self::assertSame('machine', ArticleWordPressPostType::resolve($customCptProduct));
    }

    public function test_draft_read_model_resolves_legacy_null_rewrite_and_improve_from_article_without_defaulting_unknown_to_post(): void
    {
        $readModel = new ContentProjectDraftPlanningItemsReadModel();
        $mapRow = new ReflectionMethod(ContentProjectDraftPlanningItemsReadModel::class, 'mapRow');
        $mapRow->setAccessible(true);

        $productTask = $this->makeTaskWithArticle('rewrite', null, ['content_type' => 'product', 'wp_post_type' => 'product', 'wp_is_term' => '0']);
        $improveProductTask = $this->makeTaskWithArticle('improve', null, ['content_type' => 'product', 'wp_post_type' => 'product', 'wp_is_term' => '0']);
        $postTask = $this->makeTaskWithArticle('rewrite', null, ['content_type' => 'post', 'wp_post_type' => 'post', 'wp_is_term' => '0']);
        $unclassifiedTask = $this->makeTaskWithArticle('rewrite', null, []);

        $site = new \App\Models\Site(['domain' => 'maybalotuixachgiare.com']);
        $site->id = 2;

        $createProduct = new SeoProjectTask([
            'site_id' => 2,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => 'product',
            'source_content' => 'New product keyword',
        ]);
        $createProduct->id = 9001;
        $createProduct->setRelation('article', null);
        $createProduct->setRelation('itemOrigin', null);
        $createProduct->setRelation('site', $site);

        $createPost = new SeoProjectTask([
            'site_id' => 2,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => 'post',
            'source_content' => 'New post keyword',
        ]);
        $createPost->id = 9002;
        $createPost->setRelation('article', null);
        $createPost->setRelation('itemOrigin', null);
        $createPost->setRelation('site', $site);

        $productRow = $mapRow->invoke($readModel, $productTask, 81, false);
        $improveRow = $mapRow->invoke($readModel, $improveProductTask, 81, false);
        $postRow = $mapRow->invoke($readModel, $postTask, 81, false);
        $unknownRow = $mapRow->invoke($readModel, $unclassifiedTask, 81, false);
        $createProductRow = $mapRow->invoke($readModel, $createProduct, 81, false);
        $createPostRow = $mapRow->invoke($readModel, $createPost, 81, false);

        self::assertSame('product', $productRow['post_type']);
        self::assertSame((string) __('seo-content-ai::filament.article_list.post_type_product'), $productRow['post_type_label']);

        self::assertSame('product', $improveRow['post_type']);

        self::assertSame('post', $postRow['post_type']);
        self::assertSame((string) __('seo-content-ai::filament.article_list.post_type_post'), $postRow['post_type_label']);

        self::assertNull($unknownRow['post_type']);
        self::assertSame('—', $unknownRow['post_type_label']);

        self::assertSame('product', $createProductRow['post_type']);
        self::assertSame('post', $createPostRow['post_type']);
    }

    public function test_draft_read_model_eager_loads_classification_metas_to_prevent_n_plus_one(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('ArticleContentClassification::META_CONTENT_TYPE', $src);
        self::assertStringContainsString('ArticleContentClassification::META_WP_POST_TYPE', $src);
        self::assertStringContainsString('ArticleContentClassification::META_WP_IS_TERM', $src);
        self::assertStringContainsString('resolveEffectiveForDisplay', $src);
    }

    public function test_archived_workload_resolves_effective_post_type_and_does_not_default_missing_to_post(): void
    {
        $service = $this->app->make(ContentProjectArchivedMonthlyWorkloadService::class);
        $resolve = new ReflectionMethod(ContentProjectArchivedMonthlyWorkloadService::class, 'resolveEffectivePostType');
        $resolve->setAccessible(true);
        $label = new ReflectionMethod(ContentProjectArchivedMonthlyWorkloadService::class, 'postTypeLabel');
        $label->setAccessible(true);

        $product = $this->makeArticleWithMetas(['content_type' => 'product', 'wp_post_type' => 'product', 'wp_is_term' => '0']);
        $unclassified = $this->makeArticleWithMetas([]);

        self::assertSame('product', $resolve->invoke($service, 'rewrite', null, $product));
        self::assertNull($resolve->invoke($service, 'rewrite', null, $unclassified));
        self::assertSame('—', $label->invoke($service, null));
        self::assertSame((string) __('seo-content-ai::filament.projects.post_type_product'), $label->invoke($service, 'product'));
    }

    public function test_assignment_and_agent_action_resolve_post_type_for_rewrite_and_improve(): void
    {
        $assignSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName(),
        );
        self::assertStringContainsString('resolveCanonicalForArticle($record)', $assignSrc);

        $agentSrc = (string) file_get_contents(
            (string) (new ReflectionClass(CreateProjectTaskAction::class))->getFileName(),
        );
        self::assertStringContainsString('resolveCanonicalForArticle($linkedArticle)', $agentSrc);
    }

    public function test_ensure_article_post_type_protects_both_rewrite_and_improve_and_sync_preserves_existing(): void
    {
        $createArticlesSrc = (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );
        self::assertStringContainsString('! SeoProjectTask::isNewArticleType((string) $context->projectTaskType)', $createArticlesSrc);

        $syncSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskSyncService::class))->getFileName(),
        );
        self::assertStringContainsString("SeoProjectTask::isNewArticleType(\$row->type)\n                ? \$row->postType\n                : (\$task->post_type ?? \$row->postType)", str_replace("\r\n", "\n", $syncSrc));
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function makeArticleWithMetas(array $metas): SeoArticle
    {
        $article = new SeoArticle(['site_id' => 2, 'title' => 'Test Article']);
        $article->id = 5001;

        $collection = new EloquentCollection();
        foreach ($metas as $k => $v) {
            $meta = new ArticleMeta(['meta_key' => $k, 'meta_value' => $v]);
            $collection->push($meta);
        }
        $article->setRelation('articleMetas', $collection);
        $article->setRelation('seoProfile', null);
        $article->setRelation('site', null);
        $article->setRelation('wordpressLink', null);

        return $article;
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function makeTaskWithArticle(string $type, ?string $rawPostType, array $metas): SeoProjectTask
    {
        $article = $this->makeArticleWithMetas($metas);

        $site = new \App\Models\Site(['domain' => 'maybalotuixachgiare.com']);
        $site->id = 2;

        $task = new SeoProjectTask([
            'site_id' => 2,
            'type' => $type,
            'post_type' => $rawPostType,
            'article_id' => $article->id,
            'title' => 'Test Task',
            'source_content' => 'Test Task',
        ]);
        $task->id = 8001;
        $task->setRelation('article', $article);
        $task->setRelation('itemOrigin', null);
        $task->setRelation('site', $site);

        return $task;
    }
}
