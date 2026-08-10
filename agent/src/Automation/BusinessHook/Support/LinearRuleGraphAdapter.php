<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction;

/**
 * Runtime virtual graph for linear rules — không ghi node rows.
 */
final class LinearRuleGraphAdapter
{
    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toVirtualGraph(AutomationRule $rule): array
    {
        $rule->loadMissing('actions');
        $actions = $rule->actions->sortBy('position')->values();

        $nodes = [[
            'node_key' => 'trigger',
            'node_type' => AutomationNodeType::Trigger->value,
            'name' => 'Trigger',
            'is_enabled' => true,
            'position' => 0,
        ]];

        $edges = [[
            'from_node_key' => 'trigger',
            'to_node_key' => 'action_0',
            'branch' => AutomationEdgeBranch::Always->value,
            'priority' => 100,
        ]];

        $prevKey = 'action_0';
        /** @var AutomationRuleAction $action */
        foreach ($actions as $index => $action) {
            $key = 'action_'.$index;
            $nodes[] = [
                'node_key' => $key,
                'node_type' => AutomationNodeType::Action->value,
                'name' => $action->action_code,
                'action_code' => $action->action_code,
                'input_mapping' => $action->input_mapping,
                'settings' => $action->settings,
                'is_enabled' => $action->is_enabled,
                'position' => $index + 1,
                'config' => [
                    'delay_seconds' => $action->delay_seconds,
                    'continue_on_failure' => $action->continue_on_failure,
                ],
            ];

            if ($index > 0) {
                $edges[] = [
                    'from_node_key' => 'action_'.($index - 1),
                    'to_node_key' => $key,
                    'branch' => AutomationEdgeBranch::Success->value,
                    'priority' => 100,
                ];
            }

            $prevKey = $key;
        }

        $nodes[] = [
            'node_key' => 'end',
            'node_type' => AutomationNodeType::End->value,
            'name' => 'End',
            'is_enabled' => true,
            'position' => count($nodes),
        ];

        $edges[] = [
            'from_node_key' => $prevKey,
            'to_node_key' => 'end',
            'branch' => AutomationEdgeBranch::Success->value,
            'priority' => 100,
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
