<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\WorkflowGraphReachability;
use PHPUnit\Framework\TestCase;

final class WorkflowGraphReachabilityTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $branchingEdges;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchingEdges = [
            ['sourceNode' => 'input', 'targetNode' => 'outline', 'sourcePort' => 'out_main', 'targetPort' => 'in'],
            ['sourceNode' => 'outline', 'targetNode' => 'extract', 'sourcePort' => 'task_2_vocabulary', 'targetPort' => 'in'],
            ['sourceNode' => 'extract', 'targetNode' => 'save_vocab', 'sourcePort' => 'out_main', 'targetPort' => 'in'],
            ['sourceNode' => 'outline', 'targetNode' => 'writing', 'sourcePort' => 'total', 'targetPort' => 'in'],
            ['sourceNode' => 'writing', 'targetNode' => 'save_article', 'sourcePort' => 'out_main', 'targetPort' => 'in'],
            ['sourceNode' => 'unrelated_a', 'targetNode' => 'unrelated_b', 'sourcePort' => 'out_main', 'targetPort' => 'in'],
        ];
    }

    public function test_reachable_from_outline_includes_both_branches_not_unrelated(): void
    {
        $reachable = WorkflowGraphReachability::reachableNodeIdsFrom('outline', $this->branchingEdges);

        self::assertEqualsCanonicalizing(
            ['outline', 'extract', 'save_vocab', 'writing', 'save_article'],
            $reachable,
        );
        self::assertNotContains('unrelated_a', $reachable);
        self::assertNotContains('unrelated_b', $reachable);
        self::assertNotContains('input', $reachable);
    }

    public function test_reachable_from_writing_is_writing_branch_only(): void
    {
        $reachable = WorkflowGraphReachability::reachableNodeIdsFrom('writing', $this->branchingEdges);

        self::assertSame(['writing', 'save_article'], $reachable);
        self::assertNotContains('extract', $reachable);
        self::assertNotContains('save_vocab', $reachable);
    }

    public function test_has_blocked_predecessor_when_upstream_failed(): void
    {
        $status = [
            'outline' => 'completed',
            'extract' => 'failed',
        ];

        self::assertTrue(
            WorkflowGraphReachability::hasBlockedPredecessor(
                'save_vocab',
                $this->branchingEdges,
                $status,
                ['outline', 'extract', 'save_vocab'],
            ),
        );
        self::assertFalse(
            WorkflowGraphReachability::hasBlockedPredecessor(
                'writing',
                $this->branchingEdges,
                $status,
                ['outline', 'writing', 'save_article'],
            ),
        );
    }
}
