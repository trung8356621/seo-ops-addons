<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\WorkflowExistingAiOutputService;
use PHPUnit\Framework\TestCase;

final class WorkflowExistingAiOutputServiceTest extends TestCase
{
    public function test_it_reuses_an_existing_outline(): void
    {
        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([
            (new ArticleMeta)->forceFill([
                'meta_key' => 'seo_article_outline',
                'meta_value' => '# Dàn ý đã có',
            ]),
        ]));
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve(
            ['data' => ['execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value]],
            $prompt,
            $article,
        );

        self::assertSame(WorkflowExistingAiOutputService::TYPE_OUTLINE, $reuse['type']);
        self::assertSame('# Dàn ý đã có', $reuse['output']);
    }

    public function test_it_reuses_existing_content_for_the_article_writer_node(): void
    {
        $article = (new SeoArticle)->forceFill(['body' => '<p>Nội dung đã có</p>']);
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve([
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'mergeOutlineToSave' => true,
            ],
        ], $prompt, $article);

        self::assertSame(WorkflowExistingAiOutputService::TYPE_CONTENT, $reuse['type']);
        self::assertSame('<p>Nội dung đã có</p>', $reuse['output']);
    }

    public function test_it_does_not_reuse_content_when_body_has_outline_markers(): void
    {
        $article = (new SeoArticle)->forceFill([
            'body' => '<p>[START_TASK_1_OUTLINE]</p><h2>1. Giới thiệu</h2>',
        ]);
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve([
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'mergeOutlineToSave' => true,
            ],
        ], $prompt, $article);

        self::assertNull($reuse);
    }

    public function test_allow_reuse_false_always_null(): void
    {
        $article = (new SeoArticle)->forceFill(['body' => '<p>Nội dung đã có</p>']);
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Anything']);

        $reuse = (new WorkflowExistingAiOutputService)->resolve(
            ['data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value]],
            $prompt,
            $article,
            allowReuse: false,
        );

        self::assertNull($reuse);
    }

    public function test_prompt_name_alone_does_not_detect_outline(): void
    {
        $prompt = (new SeoPrompt)->forceFill(['name' => 'Dàn ý bài viết outline']);
        self::assertNull((new WorkflowExistingAiOutputService)->outputType([], $prompt));
    }
}
