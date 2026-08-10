<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Illuminate\Support\Collection;

final class AutomationGraphValidator
{
    private const MAX_CYCLE_VISITS_DEFAULT = 3;

    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
        private readonly AutomationConditionEngine $conditionEngine,
    ) {}

    /**
     * @param  Collection<int, AutomationRuleNode>|null  $nodes
     * @param  Collection<int, AutomationRuleEdge>|null  $edges
     * @return list<string>
     */
    public function validate(AutomationRule $rule, ?Collection $nodes = null, ?Collection $edges = null): array
    {
        $nodes ??= $rule->relationLoaded('nodes')
            ? $rule->nodes
            : $rule->nodes()->get();
        $edges ??= $rule->relationLoaded('edges')
            ? $rule->edges
            : $rule->edges()->get();

        $errors = [];
        $enabledNodes = $nodes->filter(static fn (AutomationRuleNode $n): bool => (bool) $n->is_enabled);

        $triggerNodes = $enabledNodes->filter(
            static fn (AutomationRuleNode $n): bool => $n->node_type === AutomationNodeType::Trigger->value,
        );
        if ($triggerNodes->count() !== 1) {
            $errors[] = 'Graph must have exactly one enabled trigger node.';
        }

        $endNodes = $enabledNodes->filter(
            static fn (AutomationRuleNode $n): bool => $n->node_type === AutomationNodeType::End->value,
        );
        if ($endNodes->isEmpty()) {
            $errors[] = 'Graph must have at least one enabled end node or terminal path.';
        }

        $keys = $enabledNodes->pluck('node_key')->all();
        if (count($keys) !== count(array_unique($keys))) {
            $errors[] = 'Node keys must be unique within the rule.';
        }

        $nodeKeySet = array_fill_keys($keys, true);
        foreach ($edges as $edge) {
            if (! $edge instanceof AutomationRuleEdge) {
                continue;
            }
            if (! isset($nodeKeySet[$edge->from_node_key]) || ! isset($nodeKeySet[$edge->to_node_key])) {
                $errors[] = "Edge [{$edge->from_node_key}→{$edge->to_node_key}] references missing node.";
            }
            if ($edge->branch !== null && ! in_array($edge->branch, AutomationEdgeBranch::values(), true)) {
                $errors[] = "Edge [{$edge->from_node_key}→{$edge->to_node_key}] has invalid branch [{$edge->branch}].";
            }
        }

        /** @var array<string, list<string>> $incoming */
        $incoming = [];
        foreach ($edges as $edge) {
            if (! $edge instanceof AutomationRuleEdge) {
                continue;
            }
            $incoming[$edge->to_node_key][] = $edge->from_node_key;
        }

        foreach ($enabledNodes as $node) {
            if (! $node instanceof AutomationRuleNode) {
                continue;
            }
            if ($node->node_type === AutomationNodeType::Trigger->value) {
                continue;
            }
            $inCount = count($incoming[$node->node_key] ?? []);
            if ($inCount > 1) {
                $errors[] = "Fan-in not allowed: node [{$node->node_key}] has {$inCount} incoming edges.";
            }

            $errors = array_merge($errors, $this->validateNode($node));
        }

        if ($errors !== []) {
            return $errors;
        }

        $triggerKey = (string) $triggerNodes->first()->node_key;
        $errors = array_merge($errors, $this->validateReachability($triggerKey, $enabledNodes, $edges));
        $errors = array_merge($errors, $this->validateCycles($triggerKey, $enabledNodes, $edges));

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private function validateNode(AutomationRuleNode $node): array
    {
        $errors = [];
        $type = AutomationNodeType::tryFrom($node->node_type);

        if ($type === null) {
            return ["Unknown node type [{$node->node_type}] on [{$node->node_key}]."];
        }

        return match ($type) {
            AutomationNodeType::Action => $this->validateActionNode($node),
            AutomationNodeType::Condition => $this->validateConditionNode($node),
            AutomationNodeType::Delay => $this->validateDelayNode($node),
            AutomationNodeType::DispatchEvent => $this->validateDispatchNode($node),
            AutomationNodeType::Trigger, AutomationNodeType::End => [],
        };
    }

    /**
     * @return list<string>
     */
    private function validateActionNode(AutomationRuleNode $node): array
    {
        $code = trim((string) ($node->action_code ?? ''));
        if ($code === '') {
            return ["Action node [{$node->node_key}] requires action_code."];
        }
        if (! $this->actionRegistry->has($code)) {
            return ["Action node [{$node->node_key}] references unknown action [{$code}]."];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateConditionNode(AutomationRuleNode $node): array
    {
        $config = $node->config ?? [];
        $conditions = is_array($config['conditions'] ?? null) ? $config['conditions'] : null;
        if ($conditions === null || $conditions === []) {
            return ["Condition node [{$node->node_key}] requires config.conditions."];
        }

        return $this->conditionEngine->validate($conditions);
    }

    /**
     * @return list<string>
     */
    private function validateDelayNode(AutomationRuleNode $node): array
    {
        $seconds = (int) ($node->config['seconds'] ?? $node->settings['seconds'] ?? 0);
        if ($seconds <= 0) {
            return ["Delay node [{$node->node_key}] requires positive config.seconds."];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateDispatchNode(AutomationRuleNode $node): array
    {
        $eventName = trim((string) ($node->settings['event_name'] ?? $node->config['event_name'] ?? ''));
        if ($eventName === '') {
            return ["Dispatch node [{$node->node_key}] requires settings.event_name."];
        }

        return [];
    }

    /**
     * @param  Collection<int, AutomationRuleNode>  $nodes
     * @param  Collection<int, AutomationRuleEdge>  $edges
     * @return list<string>
     */
    private function validateReachability(
        string $triggerKey,
        Collection $nodes,
        Collection $edges,
    ): array {
        $adjacency = $this->buildAdjacency($edges);
        $reachable = [];
        $stack = [$triggerKey];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($reachable[$current])) {
                continue;
            }
            $reachable[$current] = true;
            foreach ($adjacency[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        $errors = [];
        foreach ($nodes as $node) {
            if (! $node instanceof AutomationRuleNode || ! $node->is_enabled) {
                continue;
            }
            if (! isset($reachable[$node->node_key])) {
                $errors[] = "Unreachable enabled node [{$node->node_key}].";
            }
        }

        return $errors;
    }

    /**
     * @param  Collection<int, AutomationRuleNode>  $nodes
     * @param  Collection<int, AutomationRuleEdge>  $edges
     * @return list<string>
     */
    private function validateCycles(
        string $triggerKey,
        Collection $nodes,
        Collection $edges,
    ): array {
        $adjacency = $this->buildAdjacency($edges);
        $allowCycleNodes = [];
        foreach ($nodes as $node) {
            if (! $node instanceof AutomationRuleNode) {
                continue;
            }
            if ((bool) ($node->config['allow_cycle'] ?? $node->settings['allow_cycle'] ?? false)) {
                $allowCycleNodes[$node->node_key] = true;
            }
        }

        $errors = [];
        $visiting = [];
        $visited = [];

        $dfs = function (string $key, array $path) use (
            &$dfs,
            &$errors,
            &$visiting,
            &$visited,
            $adjacency,
            $allowCycleNodes,
        ): void {
            if (isset($visited[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                if (! isset($allowCycleNodes[$key])) {
                    $errors[] = "Accidental cycle detected involving node [{$key}].";
                }

                return;
            }

            $visiting[$key] = true;
            foreach ($adjacency[$key] ?? [] as $next) {
                $dfs($next, [...$path, $key]);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };

        $dfs($triggerKey, []);

        return $errors;
    }

    /**
     * @param  Collection<int, AutomationRuleEdge>  $edges
     * @return array<string, list<string>>
     */
    private function buildAdjacency(Collection $edges): array
    {
        $adjacency = [];
        foreach ($edges as $edge) {
            if (! $edge instanceof AutomationRuleEdge) {
                continue;
            }
            $adjacency[$edge->from_node_key][] = $edge->to_node_key;
        }

        return $adjacency;
    }

    public function maxCycleVisits(object $node): int
    {
        $config = is_array($node->config ?? null) ? $node->config : [];
        $settings = is_array($node->settings ?? null) ? $node->settings : [];
        $max = (int) ($config['max_cycle_visits'] ?? $settings['max_cycle_visits'] ?? 0);

        return $max > 0 ? $max : self::MAX_CYCLE_VISITS_DEFAULT;
    }
}
