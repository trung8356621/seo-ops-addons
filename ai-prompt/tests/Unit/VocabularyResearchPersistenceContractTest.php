<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SaveKeywordVocabularyAction;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Services\ArticleEditorVocabularyPayloadService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIntelligenceIngestionService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Vocabulary Research business path contracts — decoupled from Prompt History.
 */
final class VocabularyResearchPersistenceContractTest extends TestCase
{
    public function test_vocabulary_prompt_requires_post_title_and_outline_in_hook_spec(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/article.vocabulary.generate@0.1.0.json';
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertTrue((bool) ($spec['input_schema']['post_title']['required'] ?? false));
        self::assertTrue((bool) ($spec['input_schema']['outline']['required'] ?? false));
        self::assertSame(['post_title', 'outline'], $spec['metadata']['required_inputs'] ?? null);
    }

    public function test_default_vocabulary_markdown_binds_article_title_anchor(): void
    {
        $md = DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN;
        self::assertStringContainsString('{{post_title}}', $md);
        self::assertStringContainsString('{{outline}}', $md);
        self::assertStringNotContainsString('cho chủ đề trên', $md);
    }

    public function test_split_executor_bind_vocabulary_variables_guard(): void
    {
        $executor = (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();

        $missingTitle = $executor->bindVocabularyVariables(
            ['keyword' => 'balo'],
            [],
            '## Outline body',
        );
        self::assertArrayHasKey('__error', $missingTitle);
        self::assertStringContainsString('post_title', (string) $missingTitle['__error']);

        $missingOutline = $executor->bindVocabularyVariables(
            ['post_title' => 'May Túi Đeo Chéo Canvas Camel'],
            [],
            '  ',
        );
        self::assertArrayHasKey('__error', $missingOutline);
        self::assertStringContainsString('outline', (string) $missingOutline['__error']);

        $ok = $executor->bindVocabularyVariables(
            ['post_title' => 'May Túi Đeo Chéo Canvas Camel', 'focus_keyword' => 'túi đeo chéo'],
            [],
            '## H2 Intro',
        );
        self::assertArrayNotHasKey('__error', $ok);
        self::assertSame('May Túi Đeo Chéo Canvas Camel', $ok['post_title']);
        self::assertSame('## H2 Intro', $ok['outline']);
        self::assertSame('túi đeo chéo', $ok['keyword']);
    }

    public function test_canonical_save_action_and_persistence_targets(): void
    {
        $def = SaveKeywordVocabularyAction::definition();
        self::assertSame('keyword.vocabulary.save', $def->key);

        $actionSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SaveKeywordVocabularyAction::class))->getFileName(),
        );
        self::assertStringContainsString("meta_key' => 'seo_article_keywords'", $actionSrc);
        self::assertStringContainsString('ingestVocabularySuggestGroupsSafe', $actionSrc);
        self::assertStringContainsString("'prompt_result_id'", $actionSrc);
        self::assertStringContainsString("'required' => false", $actionSrc);

        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(WorkflowKeywordResearchService::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordPersistenceService', $svcSrc);
        self::assertStringContainsString('ingestVocabularySuggestGroupsSafe', $svcSrc);
    }

    public function test_content_project_outline_only_uses_graph_not_single_step(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );
        $outlineOnlyPos = strpos($src, 'Outline-only:');
        self::assertNotFalse($outlineOnlyPos);
        $chunk = substr($src, $outlineOnlyPos, 1800);
        self::assertStringContainsString('runFromNodeId', $chunk);
        self::assertStringContainsString('skipContentWriting: true', $chunk);
        self::assertStringNotContainsString('runSingleStep', $chunk);
    }

    public function test_runner_outline_vocabulary_scope_keeps_save_action(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        self::assertStringContainsString('skipContentWriting', $src);
        self::assertStringContainsString('shouldSkipForOutlineVocabularyScope', $src);
        self::assertStringContainsString('outline_vocabulary_scope', $src);
        self::assertStringContainsString("actionType === 'save_vocabulary_research'", $src);
        self::assertStringContainsString('không có dữ liệu từ khóa', $src);
    }

    public function test_suggest_ingestion_does_not_read_prompt_history(): void
    {
        $ingest = (string) file_get_contents(
            (string) (new ReflectionClass(VocabularyKeywordIntelligenceIngestionService::class))->getFileName(),
        );
        self::assertStringContainsString('article_vocabulary', $ingest);
        self::assertStringContainsString('Vocabulary Suggest', $ingest);
        self::assertStringNotContainsString('PromptResult::', $ingest);
        self::assertStringNotContainsString('latest prompt', $ingest);
        self::assertStringNotContainsString('article.outline.generate', $ingest);

        $editor = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleEditorVocabularyPayloadService::class))->getFileName(),
        );
        self::assertStringContainsString('seo_article_keywords', $editor);
        self::assertStringNotContainsString('prompt_results', $editor);
    }

    public function test_vocabulary_suggest_tag_distinct_from_mcp(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordItemPresenter::class))->getFileName(),
        );
        self::assertStringContainsString('keyword_item_tag_vocabulary_suggest', $src);
        self::assertStringContainsString('keyword_item_tag_mcp_suggest', $src);
        self::assertStringContainsString('KeywordSourceNormalizer::AI_GENERATED', $src);
        self::assertStringContainsString('KeywordSourceNormalizer::KEYWORD_DISCOVERY', $src);
        self::assertSame('ai_generated', KeywordSourceNormalizer::AI_GENERATED);
        self::assertSame('keyword_discovery', KeywordSourceNormalizer::KEYWORD_DISCOVERY);
    }

    public function test_persistence_survives_optional_prompt_audit_reference(): void
    {
        $actionSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SaveKeywordVocabularyAction::class))->getFileName(),
        );
        // prompt_result_id filtered out when null/0 — not required for identity.
        self::assertStringContainsString('array_filter', $actionSrc);
        self::assertDoesNotMatchRegularExpression(
            "/prompt_result_id.{0,80}required'\\s*=>\\s*true/s",
            $actionSrc,
        );
        self::assertStringNotContainsString('cascade', $actionSrc);
        self::assertStringNotContainsString('onDelete', $actionSrc);
    }
}
