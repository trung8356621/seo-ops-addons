<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;

final class OutlineReviewCheckpointUiContractTest extends TestCase
{
    public function test_paused_runtime_maps_to_waiting_outline_review_info_badge(): void
    {
        $resolver = new ContentProjectArticleRuntimeStatusResolver;
        $status = $resolver->resolve([
            'run_item' => [
                'status' => 'paused',
                'attempt' => 1,
                'action' => 'create',
                'output_snapshot' => [
                    'awaiting_review' => true,
                    'pause_reason' => 'review_checkpoint',
                ],
            ],
            'run_status' => 'running',
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_WAITING_OUTLINE_REVIEW, $status->state);
        self::assertSame('info', $status->tone);
        self::assertFalse($status->isActive);
        self::assertSame('Waiting for review', $status->label);
        self::assertSame('Review checkpoint', $status->detail);

        $badge = ContentProjectStatusBadgePresenter::runtime($status->state);
        self::assertSame('waiting_outline_review', $badge['key']);
        self::assertStringContainsString('Waiting for review', $badge['label']);
        self::assertStringContainsString('bg-info-', $badge['classes']);
        self::assertStringNotContainsString('bg-danger-', $badge['classes']);
    }

    public function test_classifier_and_failed_overlay_respect_outline_review_pause(): void
    {
        $row = [
            'generation_status' => 'pending',
            'execution_status' => 'paused',
            'runtime_status' => [
                'state' => ContentProjectArticleRuntimeStatus::STATE_WAITING_OUTLINE_REVIEW,
                'label' => 'Waiting for review',
                'tone' => 'info',
                'is_active' => false,
            ],
            'is_genuinely_running' => false,
            'type' => 'create',
            'article_id' => 10,
        ];

        $classified = ContentProjectOpsStateClassifier::classify($row);
        self::assertSame('waiting_outline_review', $classified['generation_key']);
        self::assertFalse(ContentProjectFailedOpsDefinition::matches($row));
    }

    public function test_waiting_outline_review_context_flag_without_run_item(): void
    {
        $resolver = new ContentProjectArticleRuntimeStatusResolver;
        $status = $resolver->resolve([
            'waiting_outline_review' => true,
            'task_status' => 'pending',
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_WAITING_OUTLINE_REVIEW, $status->state);
        self::assertSame('info', $status->tone);
    }
}
