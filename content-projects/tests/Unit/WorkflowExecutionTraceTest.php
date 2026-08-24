<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionTrace;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionTraceTest extends TestCase
{
    public function test_from_steps_preserves_skip_reason_and_multiple_result_ids(): void
    {
        $trace = WorkflowExecutionTrace::fromSteps([
            [
                'node_id' => 'outline-node',
                'type' => 'prompt',
                'title' => 'Dàn ý bài viết',
                'status' => 'completed',
                'result_id' => 11,
                'prompt_result_ids' => [11, 12],
                'hook_key' => 'article.outline.structure.generate',
            ],
            [
                'node_id' => 'keyword-filter',
                'type' => 'filter',
                'title' => 'Extract keywords',
                'status' => 'skipped',
                'skip_reason' => 'not_reachable',
            ],
        ]);

        self::assertCount(2, $trace);
        self::assertSame('not_reachable', $trace[1]['skip_reason']);
        self::assertSame([11, 12], $trace[0]['prompt_result_ids']);
    }
}
