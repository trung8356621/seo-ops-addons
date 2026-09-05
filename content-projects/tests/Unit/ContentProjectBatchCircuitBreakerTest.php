<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchFailureSignature;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic circuit-breaker regression — no production prompt mutation.
 */
final class ContentProjectBatchCircuitBreakerTest extends TestCase
{
    public function test_cross_node_routes_exhausted_trips_before_item_d(): void
    {
        $engine = [];
        $itemStates = [
            'A' => 'pending',
            'B' => 'pending',
            'C' => 'pending',
            'D' => 'pending',
        ];
        $executorCalls = 0;

        $failures = [
            'A' => new ArticleExecutionResult(
                runId: 1,
                taskId: 10825,
                runItemId: 1,
                status: ContentProjectArticleSemanticStatus::Failed,
                message: 'Vocabulary generation failed: AI_ROUTES_EXHAUSTED: 1 AI attempt(s) failed.',
                errorCode: 'external_workflow_failed',
                payload: ['failed_node' => 'vocabulary'],
            ),
            'B' => new ArticleExecutionResult(
                runId: 1,
                taskId: 10950,
                runItemId: 2,
                status: ContentProjectArticleSemanticStatus::Failed,
                message: 'Outline generation failed: AI_ROUTES_EXHAUSTED: No eligible AI route was attempted',
                errorCode: 'external_workflow_failed',
                payload: ['failed_node' => 'outline'],
            ),
            'C' => new ArticleExecutionResult(
                runId: 1,
                taskId: 10993,
                runItemId: 3,
                status: ContentProjectArticleSemanticStatus::Failed,
                message: 'Outline generation failed: AI_ROUTES_EXHAUSTED: 0 AI attempts failed.',
                errorCode: 'external_workflow_failed',
                payload: ['failed_node' => 'outline'],
            ),
        ];

        $sigA = ContentProjectBatchFailureSignature::fromResult($failures['A']);
        $sigB = ContentProjectBatchFailureSignature::fromResult($failures['B']);
        $sigC = ContentProjectBatchFailureSignature::fromResult($failures['C']);
        self::assertSame(ContentProjectBatchFailureSignature::SYSTEMIC_ROUTING, $sigA);
        self::assertSame($sigA, $sigB);
        self::assertSame($sigA, $sigC);

        foreach (['A', 'B', 'C', 'D'] as $itemId) {
            if (ContentProjectBatchCircuitBreakerState::isStopped($engine)) {
                break;
            }

            if (! isset($failures[$itemId])) {
                // Item D would execute only if breaker still open — must not reach here after C.
                $executorCalls++;
                $itemStates[$itemId] = 'error';
                break;
            }

            $executorCalls++;
            $itemStates[$itemId] = 'error';
            $sig = ContentProjectBatchFailureSignature::fromResult($failures[$itemId]);
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $sig);
            $engine = $recorded['engine'];
            if ($recorded['tripped']) {
                break;
            }
        }

        self::assertSame(3, $executorCalls);
        self::assertSame('error', $itemStates['A']);
        self::assertSame('error', $itemStates['B']);
        self::assertSame('error', $itemStates['C']);
        self::assertSame('pending', $itemStates['D']);
        self::assertTrue(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(ContentProjectBatchFailureSignature::SYSTEMIC_ROUTING, (string) ($engine['circuit_breaker']['signature'] ?? ''));
        // Item D: not executed, no AI call, remains pending/not-run (not Running).
        self::assertNotSame('processing', $itemStates['D']);
        self::assertNotSame('running', $itemStates['D']);
    }

    public function test_content_validation_failures_keep_node_in_signature(): void
    {
        $outline = new ArticleExecutionResult(
            runId: 1,
            taskId: 1,
            runItemId: 1,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: output shorter than minimum_length (40 chars < 100).',
            errorCode: \Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode::ExternalWorkflowFailed->value,
            payload: ['failed_node' => 'outline'],
        );
        $vocab = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 2,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Vocabulary generation failed: Section shorter than min_length',
            errorCode: 'content_project_external_workflow_failed',
            payload: ['failed_node' => 'vocabulary'],
        );

        $sigOutline = ContentProjectBatchFailureSignature::fromResult($outline);
        $sigVocab = ContentProjectBatchFailureSignature::fromResult($vocab);
        self::assertSame('outline|min_length', $sigOutline);
        self::assertStringContainsString('vocabulary', $sigVocab);
        self::assertStringContainsString('min_length', $sigVocab);
        self::assertStringNotContainsString('content_project_external_workflow_failed', $sigOutline);
        self::assertNotSame($sigOutline, $sigVocab);
        self::assertNotSame(ContentProjectBatchFailureSignature::SYSTEMIC_ROUTING, $sigOutline);
    }

    public function test_external_workflow_wrapper_classifies_from_message(): void
    {
        $result = new ArticleExecutionResult(
            runId: 1,
            taskId: 1,
            runItemId: 1,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: output shorter than minimum_length (40 chars < 100).',
            errorCode: 'CONTENT_PROJECT_EXTERNAL_WORKFLOW_FAILED',
            payload: ['failed_node' => 'outline'],
        );

        self::assertTrue(ContentProjectBatchFailureSignature::isExternalWorkflowWrapperCode(
            'CONTENT_PROJECT_EXTERNAL_WORKFLOW_FAILED',
        ));
        self::assertSame('outline|min_length', ContentProjectBatchFailureSignature::fromResult($result));
    }

    public function test_three_identical_failures_trip_before_fourth_item(): void
    {
        $signature = 'outline|empty_response|test-model';
        $engine = [];
        $executorCalls = 0;
        $itemStates = [
            1 => 'pending',
            2 => 'pending',
            3 => 'pending',
            4 => 'pending',
        ];

        foreach ([1, 2, 3, 4] as $itemId) {
            if (ContentProjectBatchCircuitBreakerState::isStopped($engine)) {
                break;
            }

            $executorCalls++;
            $itemStates[$itemId] = 'error';

            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $signature);
            $engine = $recorded['engine'];

            if ($recorded['tripped']) {
                break;
            }
        }

        self::assertSame(3, $executorCalls);
        self::assertSame('error', $itemStates[1]);
        self::assertSame('error', $itemStates[2]);
        self::assertSame('error', $itemStates[3]);
        self::assertSame('pending', $itemStates[4]);
        self::assertTrue(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(3, (int) ($engine['circuit_breaker']['count'] ?? 0));
        self::assertSame($signature, (string) ($engine['circuit_breaker']['signature'] ?? ''));
    }

    public function test_different_signatures_reset_consecutive_counter(): void
    {
        $engine = [];
        $seq = [
            'outline|empty_response',
            'outline|empty_response',
            'writer|timeout',
            'outline|empty_response',
        ];

        $trippedAt = null;
        foreach ($seq as $index => $signature) {
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $signature);
            $engine = $recorded['engine'];
            if ($recorded['tripped']) {
                $trippedAt = $index + 1;
                break;
            }
        }

        self::assertNull($trippedAt);
        self::assertFalse(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(1, (int) ($engine['consecutive_failure']['count'] ?? 0));
        self::assertSame('outline|empty_response', (string) ($engine['consecutive_failure']['signature'] ?? ''));
    }

    public function test_resume_clears_breaker_and_allows_pending_item(): void
    {
        $signature = 'outline|empty_response|test-model';
        $engine = [];
        foreach ([1, 2, 3] as $_) {
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $signature);
            $engine = $recorded['engine'];
        }
        self::assertTrue(ContentProjectBatchCircuitBreakerState::isStopped($engine));

        $engine = ContentProjectBatchCircuitBreakerState::clearForResume($engine);
        self::assertFalse(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(0, (int) ($engine['consecutive_failure']['count'] ?? -1));

        $executorCalls = 0;
        // item 4 after resume
        if (! ContentProjectBatchCircuitBreakerState::isStopped($engine)) {
            $executorCalls++;
            $engine = ContentProjectBatchCircuitBreakerState::recordSuccess($engine);
        }

        self::assertSame(1, $executorCalls);
        self::assertFalse(ContentProjectBatchCircuitBreakerState::isStopped($engine));
    }

    public function test_failure_signature_matches_required_format(): void
    {
        $result = new ArticleExecutionResult(
            runId: 900,
            taskId: 8793,
            runItemId: 555,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: empty output / no_content. provider=test-model uuid=550e8400-e29b-41d4-a716-446655440000',
            errorCode: 'external_workflow_failed',
            payload: [
                'failed_node' => 'outline',
                'provider' => 'test-model',
            ],
        );

        $sig = ContentProjectBatchFailureSignature::fromResult($result);
        self::assertSame('outline|empty_response|test-model', $sig);
        self::assertStringNotContainsString('8793', $sig);
        self::assertStringNotContainsString('550e8400', $sig);
        self::assertStringNotContainsString('900', $sig);
    }

    public function test_engine_and_job_wire_breaker_without_prompt_mutation(): void
    {
        $engine = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/RunEngine/ContentProjectRunEngine.php',
        );
        $job = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Jobs/RunContentProjectArticleJob.php',
        );

        self::assertStringContainsString('ContentProjectBatchCircuitBreakerState::recordFailure', $engine);
        self::assertStringContainsString('tryResumeAfterCircuitBreaker', $engine);
        self::assertStringContainsString('isCircuitBreakerStopped', $job);
        self::assertStringContainsString('releaseSkippedDispatch', $job);
        self::assertStringNotContainsString('OUTLINE_MARKDOWN', $engine);
        self::assertStringNotContainsString('markdown_content', $job);
    }
}
