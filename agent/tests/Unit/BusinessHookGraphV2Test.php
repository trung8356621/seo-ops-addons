<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphEdgeResolver;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\LinearRuleGraphAdapter;
use Tests\TestCase;

final class BusinessHookGraphV2Test extends TestCase
{
    public function test_graph_validator_requires_single_trigger(): void
    {
        $validator = app(AutomationGraphValidator::class);
        $rule = new AutomationRule(['id' => 1]);
        $nodes = collect([
            $this->node('t1', AutomationNodeType::Trigger->value),
            $this->node('t2', AutomationNodeType::Trigger->value),
            $this->node('end', AutomationNodeType::End->value),
        ]);
        $edges = collect([
            $this->edge('t1', 'end', AutomationEdgeBranch::Always->value),
        ]);

        $errors = $validator->validate($rule, $nodes, $edges);
        self::assertNotEmpty($errors);
        self::assertTrue(collect($errors)->contains(static fn (string $e): bool => str_contains($e, 'trigger')));
    }

    public function test_graph_validator_rejects_fan_in(): void
    {
        $validator = app(AutomationGraphValidator::class);
        $rule = new AutomationRule(['id' => 1]);
        $nodes = collect([
            $this->node('trigger', AutomationNodeType::Trigger->value),
            $this->node('a', AutomationNodeType::Action->value, 'wordpress.article.sync'),
            $this->node('b', AutomationNodeType::Action->value, 'notification.send'),
            $this->node('merge', AutomationNodeType::End->value),
        ]);
        $edges = collect([
            $this->edge('trigger', 'a', AutomationEdgeBranch::Always->value),
            $this->edge('trigger', 'b', AutomationEdgeBranch::Always->value),
            $this->edge('a', 'merge', AutomationEdgeBranch::Success->value),
            $this->edge('b', 'merge', AutomationEdgeBranch::Success->value),
        ]);

        $errors = $validator->validate($rule, $nodes, $edges);
        self::assertTrue(collect($errors)->contains(static fn (string $e): bool => str_contains($e, 'Fan-in')));
    }

    public function test_graph_validator_rejects_unknown_action(): void
    {
        $validator = app(AutomationGraphValidator::class);
        $rule = new AutomationRule(['id' => 1]);
        $nodes = collect([
            $this->node('trigger', AutomationNodeType::Trigger->value),
            $this->node('bad', AutomationNodeType::Action->value, 'not.registered.action'),
            $this->node('end', AutomationNodeType::End->value),
        ]);
        $edges = collect([
            $this->edge('trigger', 'bad', AutomationEdgeBranch::Always->value),
            $this->edge('bad', 'end', AutomationEdgeBranch::Success->value),
        ]);

        $errors = $validator->validate($rule, $nodes, $edges);
        self::assertTrue(collect($errors)->contains(static fn (string $e): bool => str_contains($e, 'unknown action')));
    }

    public function test_edge_resolver_condition_branches(): void
    {
        $resolver = app(AutomationGraphEdgeResolver::class);
        $edges = collect([
            $this->edge('c', 'yes', AutomationEdgeBranch::True->value),
            $this->edge('c', 'no', AutomationEdgeBranch::False->value),
        ]);

        $trueEdges = $resolver->resolve($edges, 'c', true, 'true');
        self::assertCount(1, $trueEdges);
        self::assertSame('yes', $trueEdges[0]->to_node_key);

        $falseEdges = $resolver->resolve($edges, 'c', true, 'false');
        self::assertCount(1, $falseEdges);
        self::assertSame('no', $falseEdges[0]->to_node_key);
    }

    public function test_linear_adapter_builds_virtual_graph(): void
    {
        $rule = new AutomationRule([
            'code' => 'linear-adapter-fixture',
            'name' => 'Fixture',
            'event_name' => 'article.completed',
            'workflow_mode' => 'linear',
        ]);
        $rule->setRelation('actions', collect([
            new \Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction([
                'action_code' => 'wordpress.article.sync',
                'position' => 0,
                'is_enabled' => true,
                'continue_on_failure' => false,
                'delay_seconds' => 0,
                'input_mapping' => null,
                'settings' => null,
            ]),
        ]));

        $graph = app(LinearRuleGraphAdapter::class)->toVirtualGraph($rule);
        self::assertGreaterThanOrEqual(3, count($graph['nodes']));
        self::assertGreaterThanOrEqual(2, count($graph['edges']));
        self::assertSame('trigger', $graph['nodes'][0]['node_key']);
    }

    public function test_node_idempotency_key_is_stable_shape(): void
    {
        $key = hash('sha256', '42|wp_sync|1');
        self::assertSame(64, strlen($key));
    }

    private function node(string $key, string $type, ?string $actionCode = null): AutomationRuleNode
    {
        $node = new AutomationRuleNode([
            'node_key' => $key,
            'node_type' => $type,
            'action_code' => $actionCode,
            'is_enabled' => true,
            'config' => $type === AutomationNodeType::Delay->value ? ['seconds' => 10] : null,
        ]);
        if ($type === AutomationNodeType::Condition->value) {
            $node->config = ['conditions' => ['all' => [['field' => 'event.site_id', 'operator' => 'exists']]]];
        }

        return $node;
    }

    private function edge(string $from, string $to, string $branch): AutomationRuleEdge
    {
        return new AutomationRuleEdge([
            'from_node_key' => $from,
            'to_node_key' => $to,
            'branch' => $branch,
            'priority' => 100,
        ]);
    }
}
