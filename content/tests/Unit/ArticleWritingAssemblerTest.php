<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingAssembler;
use Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleWritingAssemblerTest extends TestCase
{
    public function test_outline_path_formats_artifact_not_article_body(): void
    {
        $artifact = "[START_TASK_1_OUTLINE]\nout\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\nvoc\n[END_TASK_2_VOCABULARY]";

        $outline = new ArticleGenerationSourceResult(
            rawArtifact: $artifact,
            sourceType: ArticleGenerationSourceResult::SOURCE_WORKFLOW_EDGE,
            outlineSection: 'out',
            writingInstructionsSection: 'voc',
            outlineMarkerFound: true,
            writingInstructionsMarkerFound: true,
            artifactVersion: 'v1',
            sourceRunId: 10,
            sourceRunItemId: 20,
            sourcePromptResultId: 30,
        );

        $assembled = $this->assembler()->assembleForPrompt(
            [
                'article_writing_source_type' => ArticleWritingSourceType::Outline->value,
                'post_title' => 'Title',
                'focus_keyword' => 'Keyword',
                'article_length' => 1200,
            ],
            null,
            $outline,
        );

        self::assertNotNull($assembled);
        self::assertSame(ArticleWritingSourceType::Outline, $assembled['writing']->sourceType);
        self::assertStringContainsString($artifact, $assembled['variables']['input']);
        self::assertStringNotContainsString('SEO Title', $assembled['variables']['input']);
        self::assertSame(1200, $assembled['variables']['article_length']);
        self::assertSame('outline', $assembled['variables']['source_type']);
    }

    public function test_existing_article_does_not_require_outline(): void
    {
        $assembled = $this->assembler()->assembleForPrompt([
            'article_writing_source_type' => ArticleWritingSourceType::ExistingArticle->value,
            'post_content' => "# Existing\n\nParagraph",
            'post_title' => 'T',
            'focus_keyword' => 'K',
        ]);

        self::assertNotNull($assembled);
        self::assertSame(ArticleWritingSourceType::ExistingArticle, $assembled['writing']->sourceType);
        self::assertStringContainsString("# Existing\n\nParagraph", $assembled['variables']['input']);
        self::assertStringContainsString('BÃ i viáº¿t hiá»‡n cÃ³', $assembled['variables']['input']);
    }

    public function test_brief_renders_title_keyword_description(): void
    {
        $assembled = $this->assembler()->assembleForPrompt([
            'article_writing_source_type' => ArticleWritingSourceType::Brief->value,
            'post_title' => 'Title only',
            'focus_keyword' => 'kw',
            'secondary_description' => 'desc',
            'gallery_description' => 'DO NOT USE',
        ]);

        self::assertNotNull($assembled);
        $input = (string) $assembled['variables']['input'];
        self::assertStringContainsString('TiÃªu Ä‘á»: Title only', $input);
        self::assertStringContainsString('Tá»« khÃ³a chÃ­nh: kw', $input);
        self::assertStringContainsString('MÃ´ táº£ bá»• sung: desc', $input);
        self::assertStringNotContainsString('DO NOT USE', $input);
    }

    public function test_missing_outline_rejects(): void
    {
        $result = $this->assembler()->assembleForPrompt([
            'article_writing_source_type' => ArticleWritingSourceType::Outline->value,
            'input' => 'not an outline artifact',
        ]);

        self::assertNull($result);
    }

    public function test_retry_keeps_explicit_source_type(): void
    {
        $assembled = $this->assembler()->assembleForPrompt([
            'article_writing_source_type' => ArticleWritingSourceType::ExistingArticle->value,
            'source_type' => ArticleWritingSourceType::ExistingArticle->value,
            'article_writing_raw_input' => 'Body from snapshot',
            'article_writing_formatted' => true,
            'source_hash' => 'abc',
        ]);

        self::assertNotNull($assembled);
        self::assertSame(ArticleWritingSourceType::ExistingArticle, $assembled['writing']->sourceType);
        self::assertStringContainsString('Body from snapshot', $assembled['variables']['input']);
    }

    public function test_settings_workflows_hides_rewrite_field(): void
    {
        $source = file_get_contents(ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsWorkflows.php');
        self::assertIsString($source);
        self::assertStringContainsString('KEY_REWRITE_ARTICLE: legacy DB field', $source);
        self::assertStringNotContainsString(
            'settings_workflows.rewrite_article',
            $source,
        );
    }

    public function test_catalog_and_create_service_do_not_read_rewrite_task_id(): void
    {
        $catalog = file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php');
        $create = file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php');
        self::assertIsString($catalog);
        self::assertIsString($create);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $catalog);
        self::assertStringNotContainsString('getRewriteArticleTaskId', $create);
    }

    private function assembler(): ArticleWritingAssembler
    {
        $parser = (new ReflectionClass(WorkflowParserService::class))->newInstanceWithoutConstructor();
        $outline = new ArticleOutlineResolver($parser);
        $generation = new ArticleGenerationInputResolver($outline);
        $wp = (new ReflectionClass(WordPressArticleContentService::class))->newInstanceWithoutConstructor();

        return new ArticleWritingAssembler(
            $generation,
            new ArticleWritingInputFormatter,
            $wp,
            $parser,
        );
    }
}
