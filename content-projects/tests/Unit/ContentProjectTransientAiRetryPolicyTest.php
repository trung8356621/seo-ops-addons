<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use PHPUnit\Framework\TestCase;

final class ContentProjectTransientAiRetryPolicyTest extends TestCase
{
    public function test_from_exception_retryable_routes_exhausted(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 0,
            routingAttempts: [
                ['result' => 'skipped', 'model' => 'a', 'skip_reason' => 'model_cooldown'],
                ['result' => 'skipped', 'model' => 'b', 'skip_reason' => 'model_cooldown'],
            ],
            diagnostics: ['retry_after_seconds' => 45],
        );
        $meta = ContentProjectTransientAiRetryPolicy::fromException($exception);
        self::assertNotNull($meta);
        self::assertTrue($meta[ContentProjectTransientAiRetryPolicy::PAYLOAD_FLAG]);
        self::assertSame(AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH, $meta['exhaustion_kind']);
        self::assertSame(45, $meta['retry_after_seconds']);
    }

    public function test_from_exception_hard_not_retryable(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [[
                'result' => 'failed',
                'model' => 'x',
                'failure_class' => AiFailureClass::ModelNotFound->value,
            ]],
        );
        self::assertNull(ContentProjectTransientAiRetryPolicy::fromException($exception));
    }

    public function test_from_failed_item_row_vocabulary_message(): void
    {
        $meta = ContentProjectTransientAiRetryPolicy::fromFailedItemRow([
            'message' => 'Vocabulary generation failed: AI_ROUTES_EXHAUSTED: 1 AI attempt(s) failed',
            'steps' => [[
                'status' => 'failed',
                'hook_key' => 'article.vocabulary.generate',
                'message' => 'AI_ROUTES_EXHAUSTED: 1 AI attempt(s) failed',
                'outline_subtask' => 'vocabulary_failed',
            ]],
        ]);
        self::assertNotNull($meta);
        self::assertSame('article.vocabulary.generate', $meta['failed_hook']);
        self::assertSame('vocabulary_failed', $meta['outline_subtask']);
    }

    public function test_structured_hard_exhaustion_wins_over_legacy_message(): void
    {
        $itemRow = [
            'message' => 'AI_ROUTES_EXHAUSTED: No eligible AI route was attempted',
            'steps' => [[
                'status' => 'failed',
                'hook_key' => 'article.outline.structure.generate',
                'message' => 'AI_ROUTES_EXHAUSTED: No eligible AI route was attempted',
                'ai_routing' => [
                    'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                    'retryable' => false,
                    'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_HARD,
                    'attempt_count' => 0,
                    'routing_attempts' => [
                        ['result' => 'skipped', 'model' => 'a', 'skip_reason' => 'model_unavailable'],
                    ],
                ],
            ]],
        ];

        self::assertNull(ContentProjectTransientAiRetryPolicy::fromFailedItemRow($itemRow));
    }

    public function test_structured_temporary_health_retries_with_diagnostics(): void
    {
        $meta = ContentProjectTransientAiRetryPolicy::fromFailedItemRow([
            'message' => 'AI_ROUTES_EXHAUSTED: No eligible AI route was attempted',
            'steps' => [[
                'status' => 'failed',
                'hook_key' => 'article.vocabulary.generate',
                'outline_subtask' => 'vocabulary_failed',
                'ai_routing' => [
                    'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                    'retryable' => true,
                    'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH,
                    'retry_after_seconds' => 45,
                    'attempt_count' => 0,
                    'health_skip_count' => 2,
                    'skip_counts' => ['model_cooldown' => 2],
                    'routing_attempts' => [
                        ['result' => 'skipped', 'model' => 'a', 'skip_reason' => 'model_cooldown'],
                    ],
                ],
            ]],
        ]);

        self::assertNotNull($meta);
        self::assertTrue($meta[ContentProjectTransientAiRetryPolicy::PAYLOAD_FLAG]);
        self::assertSame(AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH, $meta['exhaustion_kind']);
        self::assertSame(45, $meta['retry_after_seconds']);
        self::assertSame(2, $meta['health_skip_count']);
        self::assertSame(['model_cooldown' => 2], $meta['skip_counts']);
        self::assertSame('article.vocabulary.generate', $meta['failed_hook']);
        self::assertSame('vocabulary_failed', $meta['outline_subtask']);
    }

    public function test_structured_flags_at_item_row_level_are_ssot(): void
    {
        self::assertNull(ContentProjectTransientAiRetryPolicy::fromFailedItemRow([
            'message' => 'AI_ROUTES_EXHAUSTED: No eligible AI route was attempted',
            'classification' => AiRoutesExhaustedException::CLASSIFICATION,
            'retryable' => false,
            'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_HARD,
        ]));
    }

    public function test_from_exception_walks_previous_chain(): void
    {
        $hard = new \RuntimeException('Outline generation failed: AI_ROUTES_EXHAUSTED: No eligible AI route was attempted', 0, new AiRoutesExhaustedException(
            attemptCount: 0,
            routingAttempts: [
                ['result' => 'skipped', 'model' => 'a', 'skip_reason' => 'model_unavailable'],
            ],
        ));
        self::assertNull(ContentProjectTransientAiRetryPolicy::fromException($hard));

        $temporary = new \RuntimeException('Outline generation failed', 0, new AiRoutesExhaustedException(
            attemptCount: 0,
            routingAttempts: [
                ['result' => 'skipped', 'model' => 'a', 'skip_reason' => 'model_cooldown'],
            ],
            diagnostics: ['retry_after_seconds' => 60],
        ));
        $meta = ContentProjectTransientAiRetryPolicy::fromException($temporary);
        self::assertNotNull($meta);
        self::assertSame(AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH, $meta['exhaustion_kind']);
        self::assertSame(60, $meta['retry_after_seconds']);
    }

    public function test_delay_floors_and_caps(): void
    {
        self::assertSame(30, ContentProjectTransientAiRetryPolicy::delaySeconds(2, 10));
        self::assertSame(90, ContentProjectTransientAiRetryPolicy::delaySeconds(3, 10));
        self::assertSame(120, ContentProjectTransientAiRetryPolicy::delaySeconds(2, 120));
        self::assertSame(300, ContentProjectTransientAiRetryPolicy::delaySeconds(3, 999));
    }

    public function test_is_transient_result_from_pending_payload(): void
    {
        $result = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Pending,
            message: 'Đang chờ AI',
            payload: [ContentProjectTransientAiRetryPolicy::PAYLOAD_FLAG => true],
            mayDispatchNextOverride: false,
        );
        self::assertTrue(ContentProjectTransientAiRetryPolicy::isTransientResult($result));
        self::assertFalse($result->isFailed());
    }

    public function test_free_only_not_in_policy_source(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Support/RunEngine/ContentProjectTransientAiRetryPolicy.php',
        );
        self::assertStringNotContainsString('FreeOnly', $src);
        self::assertStringNotContainsString('ai_cost_policy', $src);
    }
}
