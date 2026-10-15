<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectOperationalCounterScopeTest extends TestCase
{
    public function test_content_manager_counters_use_the_operational_list_and_keep_planning_generated(): void
    {
        $plans = [];
        for ($id = 1; $id <= 17; $id++) {
            $plans[] = [
                'task_id' => $id,
                'task_status' => 'pending',
                'type' => 'create',
                'article_id' => 0,
                'generation_status' => 'pending',
                'execution_status' => '',
            ];
        }

        $needsReview = [
            'task_id' => 9354,
            'task_status' => 'completed',
            'type' => 'create',
            'article_id' => 13921,
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-09-20T17:22:39+00:00',
            'is_genuinely_running' => false,
        ];
        $generatedOther = [
            'task_id' => 9368,
            'task_status' => 'completed',
            'type' => 'rewrite',
            'article_id' => 4283,
            'generation_status' => 'completed',
            'execution_status' => 'skipped',
            'is_genuinely_running' => false,
        ];
        $generatedOtherTwo = [
            'task_id' => 9369,
            'task_status' => 'completed',
            'type' => 'rewrite',
            'article_id' => 4284,
            'generation_status' => 'completed',
            'execution_status' => 'skipped',
            'is_genuinely_running' => false,
        ];

        $workingSet = [...$plans, $needsReview, $generatedOther, $generatedOtherTwo];
        $operational = ContentProjectItemOperationsReadModel::contentManagerOperationalRows($workingSet);
        $planning = ContentProjectOpsStateClassifier::countSummary($workingSet);
        $summary = ContentProjectOpsStateClassifier::countSummary($operational);

        self::assertSame([9354, 9368, 9369], array_column($operational, 'task_id'));
        self::assertSame(20, $planning['normal']);
        self::assertSame(3, count($operational));
        self::assertSame(1, $summary['recently_completed']);
        self::assertSame(0, $summary['waiting_review']);
        self::assertSame($planning['generated'], $summary['generated']);
    }

    public function test_content_manager_scope_uses_the_ops_presentation_gate(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('usesContentManagerOpsPresentation()', $src);
        self::assertStringContainsString("\$stats['normal'] = \$contentManager ? count(\$operationalRows) : \$workingSetCount;", $src);
    }
}
