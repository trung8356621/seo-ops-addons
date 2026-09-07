<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAiFailureRepairService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use PHPUnit\Framework\TestCase;

/**
 * Pure classification + apply-seam coverage. No DB, no container.
 */
final class ContentProjectAiFailureRepairServiceTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function snapshot(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 123,
            'task_status' => SeoProjectTask::STATUS_FAILED,
            'task_type' => SeoProjectTask::TYPE_CREATE,
            'task_archived' => false,
            'project_archived' => false,
            'published' => false,
            'active' => false,
            'existing_article_unresolved' => false,
            'exec_status' => 'failed',
            'exec_present' => true,
            'run_item_id' => 9001,
            'message' => '',
            'error_message' => '',
            'error_detail' => '',
            'error_code' => '',
            'steps' => [],
            'ai_routing' => [],
        ], $overrides);
    }

    // --- Retryable classification -------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function transientMessageProvider(): iterable
    {
        yield 'routes exhausted code' => ['AI_ROUTES_EXHAUSTED: no route completed'];
        yield 'no eligible ai route' => ['No eligible AI route was attempted'];
        yield 'routes exhausted prose' => ['All AI routes exhausted for this hook'];
        yield 'rate limit 429' => ['Provider returned 429 Too Many Requests'];
        yield 'rate limit prose' => ['Upstream rate limit reached, retry later'];
        yield 'timeout' => ['Request timed out after 120s'];
        yield 'gateway 502' => ['Upstream responded 502 Bad Gateway'];
        yield 'service unavailable 503' => ['503 Service Unavailable'];
        yield 'gateway timeout 504' => ['504 gateway timeout'];
        yield 'temporary unavailable' => ['Provider temporarily unavailable'];
        yield 'cooldown' => ['All models in cooldown window'];
    }

    /**
     * @dataProvider transientMessageProvider
     */
    public function test_transient_messages_classify_as_retryable(string $message): void
    {
        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_RETRYABLE,
            ContentProjectAiFailureRepairService::classifyFailure(self::snapshot(['message' => $message])),
            $message,
        );
    }

    public function test_structured_retryable_routing_metadata_is_retryable(): void
    {
        $snapshot = self::snapshot([
            'message' => 'external_workflow_failed',
            'ai_routing' => [
                'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH,
                'retryable' => true,
                'retry_after_seconds' => 45,
            ],
        ]);

        self::assertTrue(ContentProjectAiFailureRepairService::isHistoricalTransientAiFailure($snapshot));
        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_RETRYABLE,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_transient_signal_inside_step_is_retryable(): void
    {
        $snapshot = self::snapshot([
            'message' => 'Workflow failed',
            'steps' => [
                ['hook_key' => 'content.generate', 'message' => 'No eligible AI route was attempted'],
            ],
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_RETRYABLE,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    // --- Case H: hard failure historical row untouched -----------------------------

    public function test_structured_retryable_false_wins_over_transient_message(): void
    {
        $snapshot = self::snapshot([
            'message' => 'No eligible AI route was attempted',
            'ai_routing' => [
                'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_HARD,
                'retryable' => false,
            ],
        ]);

        self::assertFalse(ContentProjectAiFailureRepairService::isHistoricalTransientAiFailure($snapshot));
        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_hard_route_exhaustion_kind_alone_is_hard(): void
    {
        $snapshot = self::snapshot([
            'message' => 'routes exhausted',
            'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_HARD,
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hardMessageProvider(): iterable
    {
        yield 'model not found' => ['model_not_found: deepseek-r1'];
        yield 'invalid credential' => ['Invalid credential for provider'];
        yield 'credential invalid code' => ['credential_invalid'];
        yield 'billing locked' => ['Billing exhausted — paid plan required'];
        yield 'account restricted' => ['Account restricted by provider'];
        yield 'invalid request' => ['Invalid request: unsupported parameter'];
        yield 'invalid workflow' => ['Invalid workflow definition'];
        yield 'output quality' => ['Output quality gate rejected the draft'];
        yield 'cancelled' => ['Run cancelled by operator'];
        yield 'unauthorized 401' => ['HTTP 401 unauthorized'];
    }

    /**
     * @dataProvider hardMessageProvider
     */
    public function test_hard_messages_classify_as_hard(string $message): void
    {
        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure(self::snapshot(['message' => $message])),
            $message,
        );
    }

    public function test_hard_credential_message_is_not_rescued_by_transient_words(): void
    {
        // Mentions "rate limit" but the decisive part is a credential problem.
        $snapshot = self::snapshot([
            'message' => 'Invalid API key — provider also reported rate limit',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_content_validation_failure_is_not_transient(): void
    {
        $snapshot = self::snapshot([
            'message' => 'Article shorter than minimum 500 characters',
        ]);

        self::assertFalse(ContentProjectAiFailureRepairService::isHistoricalTransientAiFailure($snapshot));
        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_failure_without_any_evidence_stays_hard(): void
    {
        $snapshot = self::snapshot(['exec_present' => false, 'exec_status' => null, 'run_item_id' => null]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_HARD,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    // --- Case I: published / active / archived untouched ---------------------------

    public function test_archived_project_is_never_repaired(): void
    {
        $snapshot = self::snapshot([
            'project_archived' => true,
            'message' => 'AI_ROUTES_EXHAUSTED',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_ARCHIVED,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_archived_task_is_never_repaired(): void
    {
        $snapshot = self::snapshot(['task_archived' => true, 'message' => 'timed out']);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_ARCHIVED,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_published_item_is_never_repaired(): void
    {
        $snapshot = self::snapshot(['published' => true, 'message' => 'AI_ROUTES_EXHAUSTED']);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_PUBLISHED,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_active_item_is_never_repaired(): void
    {
        $snapshot = self::snapshot([
            'active' => true,
            'task_status' => SeoProjectTask::STATUS_WRITING,
            'exec_status' => 'processing',
            'message' => 'AI_ROUTES_EXHAUSTED',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_ACTIVE,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_already_recovered_item_is_skipped(): void
    {
        $snapshot = self::snapshot([
            'task_status' => SeoProjectTask::STATUS_COMPLETED,
            'exec_status' => 'success',
            'message' => 'AI_ROUTES_EXHAUSTED',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_RECOVERED,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_improve_item_is_manual_only(): void
    {
        $snapshot = self::snapshot([
            'task_type' => SeoProjectTask::TYPE_IMPROVE,
            'message' => 'AI_ROUTES_EXHAUSTED',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_IMPROVE,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_unresolved_existing_article_is_skipped(): void
    {
        $snapshot = self::snapshot([
            'task_type' => SeoProjectTask::TYPE_REWRITE,
            'existing_article_unresolved' => true,
            'message' => 'AI_ROUTES_EXHAUSTED',
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_SKIP,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    public function test_pending_item_without_failure_is_skipped(): void
    {
        $snapshot = self::snapshot([
            'task_status' => SeoProjectTask::STATUS_PENDING,
            'exec_status' => null,
            'exec_present' => false,
            'run_item_id' => null,
        ]);

        self::assertSame(
            ContentProjectAiFailureRepairService::CLASS_SKIP,
            ContentProjectAiFailureRepairService::classifyFailure($snapshot),
        );
    }

    // --- Case F: dry run performs no writes ----------------------------------------

    public function test_dry_run_never_invokes_the_rerun_handler(): void
    {
        $calls = [];
        $service = (new ContentProjectAiFailureRepairService())
            ->usingRerunInvoker(function (int $projectId, array $itemIds) use (&$calls): ContentProjectActionResult {
                $calls[] = [$projectId, $itemIds];

                return ContentProjectActionResult::ok('ok', 'Rerun started.', $projectId, $itemIds);
            });

        $result = $service->dispatchRepair(900, [123, 124], apply: false);

        self::assertSame([], $calls);
        self::assertSame(2, $result['queued_for_rerun']);
        self::assertSame([], $result['errors']);
    }

    // --- Case G: apply queues exactly the eligible items ---------------------------

    public function test_apply_queues_each_eligible_item(): void
    {
        $calls = [];
        $service = (new ContentProjectAiFailureRepairService())
            ->usingRerunInvoker(function (int $projectId, array $itemIds) use (&$calls): ContentProjectActionResult {
                $calls[] = [$projectId, $itemIds];

                return ContentProjectActionResult::ok('ok', 'Rerun started.', $projectId, $itemIds);
            });

        $result = $service->dispatchRepair(900, [123], apply: true);

        self::assertSame([[900, [123]]], $calls);
        self::assertSame(1, $result['queued_for_rerun']);
        self::assertSame([], $result['errors']);
    }

    public function test_apply_reports_per_item_failures_without_aborting(): void
    {
        $service = (new ContentProjectAiFailureRepairService())
            ->usingRerunInvoker(function (int $projectId, array $itemIds): ContentProjectActionResult {
                if ($itemIds === [124]) {
                    return ContentProjectActionResult::fail(
                        'validation_failed',
                        'Rerun not executable for current item state.',
                        $projectId,
                    );
                }

                return ContentProjectActionResult::ok('ok', 'Rerun started.', $projectId, $itemIds);
            });

        $result = $service->dispatchRepair(900, [123, 124, 125], apply: true);

        self::assertSame(2, $result['queued_for_rerun']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('item=124', $result['errors'][0]);
    }

    public function test_apply_captures_handler_exceptions_as_errors(): void
    {
        $service = (new ContentProjectAiFailureRepairService())
            ->usingRerunInvoker(function (): ContentProjectActionResult {
                throw new \RuntimeException('lock timeout');
            });

        $result = $service->dispatchRepair(900, [123], apply: true);

        self::assertSame(0, $result['queued_for_rerun']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('lock timeout', $result['errors'][0]);
    }

    public function test_capability_resume_routes_to_resume_path(): void
    {
        self::assertSame(
            ContentProjectAiFailureRepairService::PATH_RESUME,
            ContentProjectAiFailureRepairService::pathForCapabilityAction(
                ContentProjectGenerationRecoveryDecision::ACTION_RESUME,
            ),
        );
        self::assertSame(
            ContentProjectAiFailureRepairService::PATH_RERUN,
            ContentProjectAiFailureRepairService::pathForCapabilityAction(
                ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
            ),
        );
        self::assertSame(
            ContentProjectAiFailureRepairService::PATH_RERUN,
            ContentProjectAiFailureRepairService::pathForCapabilityAction(
                ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
            ),
        );
        self::assertSame(
            ContentProjectAiFailureRepairService::PATH_RESUME,
            ContentProjectAiFailureRepairService::pathForCapabilityAction(
                ContentProjectGenerationRecoveryDecision::ACTION_NONE,
                showResume: true,
                resumableFromStep: 'outline',
            ),
        );
    }
}
