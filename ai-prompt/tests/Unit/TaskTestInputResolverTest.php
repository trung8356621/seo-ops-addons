<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Illuminate\Database\Eloquent\Collection;
use ReflectionMethod;
use Tests\TestCase;

final class TaskTestInputResolverTest extends TestCase
{
    public function test_resolve_from_raw_input_sets_input_variables(): void
    {
        $resolver = app(TaskTestInputResolver::class);

        $context = $resolver->resolveFromRawInput('  Mô tả ảnh sản phẩm  ');

        $this->assertSame('Mô tả ảnh sản phẩm', $context->variables['input']);
        $this->assertSame('Mô tả ảnh sản phẩm', $context->variables['user_brief']);
        $this->assertStringContainsString('Mô tả ảnh sản phẩm', $context->summary);
    }

    public function test_resolve_from_raw_input_rejects_empty_string(): void
    {
        $resolver = app(TaskTestInputResolver::class);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromRawInput('   ');
    }

    public function test_article_matches_post_type_rejects_product_when_task_wants_article(): void
    {
        $resolver = app(TaskTestInputResolver::class);
        $method = new ReflectionMethod(TaskTestInputResolver::class, 'articleMatchesPostType');
        $method->setAccessible(true);

        $product = new SeoArticle;
        $product->type = SeoProjectTask::POST_TYPE_PRODUCT;
        $product->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        $this->assertFalse($method->invoke(
            $resolver,
            $product,
            SeoProjectTask::POST_TYPE_ARTICLE,
        ));
        $this->assertTrue($method->invoke(
            $resolver,
            $product,
            SeoProjectTask::POST_TYPE_PRODUCT,
        ));
        $this->assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($product),
        );
    }

    public function test_apply_project_post_type_forces_task_post_type_into_context(): void
    {
        $resolver = app(TaskTestInputResolver::class);
        $method = new ReflectionMethod(TaskTestInputResolver::class, 'applyProjectPostType');
        $method->setAccessible(true);

        $context = new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: [
                'focus_keyword' => 'seo keyword',
                'post_title' => 'seo keyword',
            ],
            summary: 'matched product by mistake',
            siteId: 1,
            postType: SeoProjectTask::POST_TYPE_PRODUCT,
            projectTaskType: SeoProjectTask::TYPE_NEW_KEYWORD,
        );

        /** @var TaskTestContext $forced */
        $forced = $method->invoke($resolver, $context, SeoProjectTask::POST_TYPE_ARTICLE);

        $this->assertSame(SeoProjectTask::POST_TYPE_ARTICLE, $forced->postType);
        $this->assertSame(SeoProjectTask::POST_TYPE_ARTICLE, $forced->variables['_project_post_type']);
    }
}
