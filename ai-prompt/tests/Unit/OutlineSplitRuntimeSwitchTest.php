<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\SplitOutlineContentSemanticBinder;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\Content\Support\WritingSplitPreference;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Outline orchestration is always Structure + Vocabulary — independent of generation_shape.
 */
final class OutlineSplitRuntimeSwitchTest extends TestCase
{
    public function test_k_outline_role_always_invokes_split_executor_without_shape_gate(): void
    {
        $runnerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );

        $this->assertStringContainsString('outlineSplitExecutor->execute', $runnerSrc);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->isOutlineRoleNode\([^)]+\)\s*\)\s*\{/u',
            $runnerSrc,
        );
        $this->assertStringNotContainsString('isOutlineSplitEnabled', $runnerSrc);
        $this->assertStringNotContainsString('ensureRouteCostGenerationShapeSnapshot', $runnerSrc);
        $this->assertStringNotContainsString('resolveOutlineGenerationShape', $runnerSrc);
        $this->assertStringContainsString(
            'generation_shape must NOT gate Outline orchestration',
            $runnerSrc,
        );
    }

    public function test_l_outline_split_on_uses_structure_and_vocabulary_hooks(): void
    {
        $this->assertSame('article.outline.structure.generate', ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $this->assertSame('article.vocabulary.generate', ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);
        $executorSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))->getFileName(),
        );
        $this->assertStringContainsString('OUTLINE_STRUCTURE_HOOK', $executorSrc);
        $this->assertStringContainsString('VOCABULARY_HOOK', $executorSrc);
        $this->assertStringContainsString("execution_source' => 'split_outline_vocabulary'", $executorSrc);
        $this->assertStringContainsString('function assemblePorts', $executorSrc);
    }

    public function test_m_settings_outline_split_is_legacy_not_runtime_authority(): void
    {
        $this->assertSame('writing_split_enabled', WritingSplitPreference::META_KEY);
        $this->assertSame('outline_split_enabled', SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);
        $this->assertNotSame(WritingSplitPreference::META_KEY, SeoCreateArticleSettingsService::KEY_OUTLINE_SPLIT_ENABLED);

        $runnerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertStringNotContainsString('createArticleSettings->isOutlineSplitEnabled', $runnerSrc);
        $this->assertStringNotContainsString('isOutlineSplitEnabled(', $runnerSrc);
    }

    public function test_paid_single_pass_and_free_sectioned_both_assemble_combined_transport(): void
    {
        $shapes = [
            ArticleGenerationShape::SinglePass, // paid content shape — Outline still splits
            ArticleGenerationShape::Sectioned,  // free content shape — Outline still splits
        ];

        $executor = (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();
        self::assertInstanceOf(ArticleOutlineVocabularySplitExecutor::class, $executor);

        $outlineBody = "## H2 Paid Or Free Outline\n### Detail\n- point one with enough length";
        $vocabBody = "### Holonymy\n- Capsule Wardrobe\n### Synonyms\n- Understated Elegance";

        foreach ($shapes as $shape) {
            // generation_shape must not change Outline assembly contract.
            $this->assertInstanceOf(ArticleGenerationShape::class, $shape);

            $ports = $executor->assemblePorts($outlineBody, $vocabBody);
            $this->assertArrayHasKey('total', $ports);
            $this->assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, $ports['total']);
            $this->assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $ports['total']);
            $this->assertStringContainsString($outlineBody, $ports['total']);
            $this->assertStringContainsString($vocabBody, $ports['total']);
            // Provider semantic ports stay markerless.
            $this->assertSame($outlineBody, $ports['task_1_outline']);
            $this->assertSame($vocabBody, $ports['task_2_vocabulary']);
            $this->assertStringNotContainsString(ArticleGenerationInputResolver::OUTLINE_START, $ports['task_1_outline']);
            $this->assertStringNotContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $ports['task_2_vocabulary']);
        }
    }

    public function test_paid_must_never_stop_after_structure_only_without_vocabulary(): void
    {
        $runnerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName(),
        );
        $this->assertStringContainsString('registerVocabularyArtifact', $runnerSrc);
        $this->assertStringContainsString('vocabulary_markdown', $runnerSrc);
        $this->assertStringContainsString('vocabularyOnly', $runnerSrc);

        $executorSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))->getFileName(),
        );
        $this->assertStringContainsString('bindVocabularyVariables', $executorSrc);
        $this->assertStringContainsString('VOCABULARY_HOOK', $executorSrc);
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(.*generation_shape.*\)\s*\{[^}]*return/su',
            $executorSrc,
        );
    }

    public function test_downstream_content_receives_both_semantic_outline_and_vocabulary(): void
    {
        $outline = "## H2 Content Handoff\n- a";
        $vocab = "### Synonyms\n- beta";
        $ports = ((new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor())
            ->assemblePorts($outline, $vocab);

        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            ['input' => $ports['total']],
            $outline,
            $vocab,
            $ports['total'],
        );

        $this->assertSame($outline, $bound['article_outline']);
        $this->assertSame($vocab, $bound['article_vocabulary']);
        $this->assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, (string) $bound['input']);
        $this->assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, (string) $bound['input']);
    }
}
