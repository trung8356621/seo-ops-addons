<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersion;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersionEdge;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleVersionNode;
use Illuminate\Support\Collection;

/**
 * Immutable graph snapshot for execution — version nodes preferred over live draft.
 */
final class AutomationGraphSnapshot
{
    /**
     * @param  Collection<int, object>  $nodes
     * @param  Collection<int, object>  $edges
     */
    public function __construct(
        public readonly AutomationRule $rule,
        public readonly ?AutomationRuleVersion $version,
        public readonly Collection $nodes,
        public readonly Collection $edges,
    ) {}

    public static function fromVersion(AutomationRule $rule, AutomationRuleVersion $version): self
    {
        $version->loadMissing(['nodes', 'edges']);

        return new self($rule, $version, $version->nodes, $version->edges);
    }

    public static function fromLiveRule(AutomationRule $rule): self
    {
        $rule->loadMissing(['nodes', 'edges']);

        return new self($rule, null, $rule->nodes, $rule->edges);
    }

    public function findNode(string $nodeKey): ?object
    {
        return $this->nodes->first(static fn ($n): bool => (string) $n->node_key === $nodeKey);
    }
}
