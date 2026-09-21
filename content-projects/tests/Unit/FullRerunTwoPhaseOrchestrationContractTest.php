<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\WorkflowPublishContentEvidence;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * FULL RERUN two-phase orchestration contracts.
 * Locks: Outline checkpoint before Writing; Writing is graph-independent.
 */
final class FullRerunTwoPhaseOrchestrationContractTest extends TestCase
{
    public function test_full_rerun_is_two_phase_not_graph_hope(): void
    {
        $src = $this->source();
        $methodPos = strpos($src, 'public function runOutlineThenArticleForContext');
        self::assertNotFalse($methodPos);
        $end = strpos($src, 'private function finalizeWorkflowGraphRun', $methodPos);
        self::assertNotFalse($end);
        $chunk = substr($src, $methodPos, $end - $methodPos);

        self::assertStringContainsString('WorkflowGraphScope::OutlineVocabulary', $chunk);
        self::assertStringContainsString('runPhase1OutlineVocabularySteps', $chunk);
        self::assertStringContainsString('articleOutlinePersist->persist', $chunk);
        self::assertStringContainsString('resolveMarkdown', $chunk);
        self::assertStringContainsString('runArticleWritingForContext', $chunk);
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $chunk);
        self::assertStringContainsString('ArticleWritingSourceType::Outline', $chunk);
        self::assertStringContainsString('full_rerun_writing_not_executed', $chunk);
        self::assertStringContainsString('direct_publish_outline_markdown', $chunk);
        self::assertStringContainsString('article_writing_raw_input', $chunk);
        self::assertStringContainsString('outline_artifact_hash', $chunk);

        // Must not rely on a single graph run that assumes Content is downstream.
        self::assertStringNotContainsString(
            "runFromNodeId(\n            \$task,\n            \$context,\n            \$outlineNodeId,\n            seedOutlineFromArticle: false,\n        );",
            $chunk,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$this->workflowRunner->runFromNodeId\s*\(/',
            $chunk,
        );
    }

    public function test_phase1_expected_content_skip_is_non_blocking(): void
    {
        $service = (new ReflectionClass(CreateArticlesFromTaskService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'summarizeBlockingPhase1Failure');
        $method->setAccessible(true);

        $block = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.outline.structure.generate',
                'title' => 'Outline',
            ],
            [
                'status' => 'skipped',
                'skip_reason' => WorkflowPublishContentEvidence::SKIP_REASON_OUTLINE_ONLY_SCOPE,
                'hook_key' => ArticleWritingExecutionService::HOOK_KEY,
                'title' => 'Content',
            ],
            [
                'status' => 'skipped',
                'skip_reason' => 'not_reachable',
                'title' => 'Image',
            ],
        ]);
        self::assertNull($block);

        $blockFail = $method->invoke($service, [
            [
                'status' => 'failed',
                'title' => 'Outline',
                'message' => 'provider refused',
                'prompt_name' => 'Outline',
            ],
        ]);
        self::assertNotNull($blockFail);
        self::assertStringContainsString('provider refused', (string) ($blockFail['message'] ?? ''));
    }

    public function test_writing_phase_executed_detection(): void
    {
        $service = (new ReflectionClass(CreateArticlesFromTaskService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'writingPhaseExecuted');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, ['success' => true], []));
        self::assertFalse($method->invoke($service, ['success' => true, 'persist_status' => ''], [
            ['status' => 'skipped', 'skip_reason' => 'outline_vocabulary_scope', 'hook_key' => ArticleWritingExecutionService::HOOK_KEY],
        ]));
        self::assertFalse($method->invoke($service, ['persist_status' => 'failed'], []));
        self::assertFalse($method->invoke($service, ['persist_status' => 'ignored_stale'], []));
        self::assertFalse($method->invoke($service, ['persist_status' => 'applied'], []));
        self::assertTrue($method->invoke($service, ['success' => false], [
            ['status' => 'failed', 'hook_key' => ArticleWritingExecutionService::HOOK_KEY],
        ]));
        self::assertTrue($method->invoke($service, ['persist_status' => 'ignored_stale'], [
            ['status' => 'completed', 'hook_key' => ArticleWritingExecutionService::HOOK_KEY, 'result_id' => 99],
        ]));
        self::assertTrue($method->invoke($service, ['persist_status' => 'applied'], [
            ['status' => 'completed', 'artifact_type' => 'article_content'],
        ]));
        self::assertTrue($method->invoke($service, [], [
            ['status' => 'completed', 'execution_role' => 'article.content.generate'],
        ]));
    }

    public function test_extract_canonical_outline_prefers_outline_markdown(): void
    {
        $resolver = $this->createMock(\Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver::class);
        $resolver->method('isValidArtifact')->willReturnCallback(
            static fn (string $raw): bool => strlen(trim($raw)) > 20,
        );
        $resolver->method('tryResolveFromRawArtifact')->willReturn(null);

        $ref = new ReflectionClass(CreateArticlesFromTaskService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('outlineResolver');
        $prop->setAccessible(true);
        $prop->setValue($service, $resolver);

        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'extractCanonicalOutlineFromSteps');
        $method->setAccessible(true);

        $artifact = $method->invoke($service, [
            [
                'status' => 'completed',
                'outline_markdown' => "## H2 New Outline Structure\n\n### H3 Detail for checkpoint",
                'vocabulary_markdown' => '',
                'output' => 'noise',
            ],
        ]);
        self::assertIsString($artifact);
        self::assertStringContainsString('H2 New Outline Structure', $artifact);
    }

    public function test_outline_checkpoint_does_not_depend_on_finalize_early_return(): void
    {
        $src = $this->source();
        $methodPos = strpos($src, 'public function runOutlineThenArticleForContext');
        $end = strpos($src, 'private function finalizeWorkflowGraphRun', (int) $methodPos);
        $chunk = substr($src, (int) $methodPos, (int) $end - (int) $methodPos);

        // Checkpoint persist happens inside two-phase method, before Writing.
        $persistPos = strpos($chunk, 'articleOutlinePersist->persist');
        $writingPos = strpos($chunk, 'runArticleWritingForContext');
        self::assertNotFalse($persistPos);
        self::assertNotFalse($writingPos);
        self::assertTrue($persistPos < $writingPos);

        // Must not call finalizeWorkflowGraphRun for the FULL path body.
        self::assertStringNotContainsString('finalizeWorkflowGraphRun(', $chunk);
    }

    public function test_article_outline_resolver_persist_api_stable(): void
    {
        self::assertTrue(method_exists(ArticleOutlineResolver::class, 'persist'));
        self::assertTrue(method_exists(ArticleOutlineResolver::class, 'resolveMarkdown'));
        self::assertTrue(method_exists(ArticleOutlineResolver::class, 'resolveStructuredRows'));
    }

    /**
     * DoD §14: Content may exist with role but be graph-unreachable from Outline.
     * FULL RERUN must still call Writing explicitly (not via reachableNodeIdsFrom).
     */
    public function test_disconnected_content_node_still_triggers_explicit_writing(): void
    {
        $edges = [
            ['sourceNode' => 'outline_a', 'targetNode' => 'vocab_save'],
            // Content exists but is NOT reachable from outline_a.
            ['sourceNode' => 'orphan_x', 'targetNode' => 'content_b'],
        ];
        $reachable = \Omnichannel\Addons\AiPrompt\Support\WorkflowGraphReachability::reachableNodeIdsFrom(
            'outline_a',
            $edges,
        );
        self::assertContains('vocab_save', $reachable);
        self::assertNotContains('content_b', $reachable);

        $src = $this->source();
        $methodPos = strpos($src, 'public function runOutlineThenArticleForContext');
        $end = strpos($src, 'private function finalizeWorkflowGraphRun', (int) $methodPos);
        $chunk = substr($src, (int) $methodPos, (int) $end - (int) $methodPos);

        // Writing is mandatory and graph-independent.
        self::assertStringContainsString('runArticleWritingForContext', $chunk);
        self::assertStringNotContainsString('reachableNodeIdsFrom', $chunk);
        self::assertStringContainsString('full_rerun_writing_not_executed', $chunk);
    }

    /**
     * DoD §15: Writing failure after Phase-1 must not undo Outline checkpoint ownership.
     */
    public function test_outline_checkpoint_survives_writing_failure_contract(): void
    {
        $src = $this->source();
        $methodPos = strpos($src, 'public function runOutlineThenArticleForContext');
        $end = strpos($src, 'private function finalizeWorkflowGraphRun', (int) $methodPos);
        $chunk = substr($src, (int) $methodPos, (int) $end - (int) $methodPos);

        $persistPos = strpos($chunk, 'articleOutlinePersist->persist');
        $writingPos = strpos($chunk, 'runArticleWritingForContext');
        self::assertNotFalse($persistPos);
        self::assertNotFalse($writingPos);
        self::assertTrue($persistPos < $writingPos);

        // Success of FULL run follows Writing result; Outline already checkpointed.
        self::assertStringContainsString("(bool) (\$writing['success'] ?? false)", $chunk);
        self::assertStringNotContainsString('articleOutlinePersist->persist($article, \'\')', $chunk);
    }

    private function source(): string
    {
        return (string) file_get_contents(
            (string) (new ReflectionClass(CreateArticlesFromTaskService::class))->getFileName(),
        );
    }
}
