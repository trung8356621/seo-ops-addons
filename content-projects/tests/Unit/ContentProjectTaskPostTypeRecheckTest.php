<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTaskPostTypeRecheckService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentLengthPresetResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicyResolver;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Illuminate\Database\Eloquent\Collection;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase;

final class ContentProjectTaskPostTypeRecheckTest extends TestCase
{
    public function test_pre_generation_hooks_call_recheck_before_policy(): void
    {
        $src = $this->source(TaskTestInputResolver::class);

        self::assertStringContainsString('ContentProjectTaskPostTypeRecheckService', $src);
        self::assertStringContainsString('recheckAndPersist', $src);

        $resolvePos = strpos($src, 'function resolveForProjectTask');
        $freshPos = strpos($src, 'function resolveFreshKeywordRestartForProjectTask');
        self::assertNotFalse($resolvePos);
        self::assertNotFalse($freshPos);

        $resolveBody = substr($src, $resolvePos, 900);
        self::assertLessThan(
            (int) strpos($resolveBody, 'normalizeType($task->type)'),
            (int) strpos($resolveBody, 'recheckAndPersist'),
        );

        $freshBody = substr($src, $freshPos, 1200);
        self::assertStringContainsString('recheckAndPersist', $freshBody);
        self::assertLessThan(
            (int) strpos($freshBody, 'resolveCanonicalPostType'),
            (int) strpos($freshBody, 'recheckAndPersist'),
        );
    }

    public function test_display_uses_effective_post_type_not_raw_only(): void
    {
        $src = $this->source(ContentProjectItemOperationsReadModel::class);
        self::assertStringContainsString('effectivePostType', $src);
        self::assertStringContainsString('ContentProjectTaskPostTypeRecheckService', $src);
        self::assertStringContainsString('META_CONTENT_TYPE', $src);
        self::assertStringContainsString('META_WP_POST_TYPE', $src);

        $meta = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-meta.blade.php'),
        );
        self::assertStringContainsString("row['post_type']", $meta);
        self::assertStringContainsString('post_type:', $meta);
    }

    public function test_display_create_keeps_planned_type(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService;
        $task = new SeoProjectTask;
        $task->forceFill([
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_PRODUCT,
            'article_id' => null,
        ]);

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            $service->resolveEffectiveForDisplay($task),
        );
    }

    public function test_display_rewrite_uses_article_not_stale_task(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService;
        $task = new SeoProjectTask;
        $task->forceFill([
            'type' => SeoProjectTask::TYPE_REWRITE,
            'post_type' => SeoProjectTask::POST_TYPE_POST,
            'site_id' => 2,
            'article_id' => 99,
        ]);

        $article = $this->article(2, [
            'content_type' => 'product',
            'wp_is_term' => '0',
            'wp_post_type' => 'product',
        ]);

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            $service->resolveEffectiveForDisplay($task, $article),
        );
        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_display_rewrite_null_when_article_unclassified(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService;
        $task = new SeoProjectTask;
        $task->forceFill([
            'type' => SeoProjectTask::TYPE_REWRITE,
            'post_type' => null,
            'site_id' => 2,
            'article_id' => 99,
        ]);

        $article = $this->article(2, []);

        self::assertNull($service->resolveEffectiveForDisplay($task, $article));
    }

    public function test_display_respects_site_isolation(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService;
        $task = new SeoProjectTask;
        $task->forceFill([
            'type' => SeoProjectTask::TYPE_REWRITE,
            'post_type' => null,
            'site_id' => 7,
            'article_id' => 99,
        ]);

        $article = $this->article(2, [
            'content_type' => 'product',
            'wp_post_type' => 'product',
            'wp_is_term' => '0',
        ]);

        self::assertNull($service->resolveEffectiveForDisplay($task, $article));
    }

    public function test_create_recheck_is_noop_without_db(): void
    {
        $service = new ContentProjectTaskPostTypeRecheckService;
        $task = new SeoProjectTask;
        $task->forceFill([
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_PRODUCT,
        ]);

        $out = $service->recheckAndPersist($task);
        self::assertSame(SeoProjectTask::POST_TYPE_PRODUCT, $out->post_type);
    }

    public function test_product_length_preset_uses_task_post_type_not_blog_default(): void
    {
        self::assertTrue(SeoPromptSettingsService::isProductPostType('product'));
        self::assertFalse(SeoPromptSettingsService::isProductPostType('post'));
        self::assertFalse(SeoPromptSettingsService::isProductPostType(null));
        self::assertFalse(SeoPromptSettingsService::isProductPostType(''));

        $src = $this->source(ContentLengthPresetResolver::class);
        self::assertStringContainsString('resolveArticleLengthTarget($postType)', $src);
        self::assertStringContainsString('MULTIPLIER_STANDARD', $src);
    }

    public function test_policy_resolver_reads_raw_task_post_type_for_length(): void
    {
        $src = $this->source(ContentProjectItemGenerationPolicyResolver::class);
        self::assertStringContainsString("rawString(\$task, 'post_type')", $src);
        self::assertStringContainsString('resolveTargetWords', $src);
    }

    public function test_error_code_for_unresolved_post_type_exists(): void
    {
        self::assertSame(
            'CONTENT_PROJECT_ARTICLE_POST_TYPE_UNRESOLVED',
            ContentProjectErrorCode::ArticlePostTypeUnresolved->value,
        );
    }

    public function test_recheck_service_does_not_write_article_meta(): void
    {
        $src = $this->source(ContentProjectTaskPostTypeRecheckService::class);
        self::assertStringNotContainsString('ArticleContentClassification::persist', $src);
        self::assertStringNotContainsString('updateOrCreate', $src);
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('LocalArticleAssociationGuard', $src);
        self::assertStringContainsString('ArticlePostTypeResolver::resolve', $src);
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function article(int $siteId, array $metas): SeoArticle
    {
        $article = new SeoArticle;
        $article->forceFill(['id' => 99, 'site_id' => $siteId]);
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

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
