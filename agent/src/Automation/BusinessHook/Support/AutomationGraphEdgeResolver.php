<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleEdge;
use Illuminate\Support\Collection;

final class AutomationGraphEdgeResolver
{
    /**
     * @param  Collection<int, AutomationRuleEdge>  $edges
     * @return list<AutomationRuleEdge>
     */
    public function resolve(
        Collection $edges,
        string $fromNodeKey,
        bool $success,
        ?string $conditionBranch = null,
    ): array {
        $outgoing = $edges
            ->filter(static fn ($e): bool => (string) $e->from_node_key === $fromNodeKey)
            ->sortBy([
                ['priority', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        if ($outgoing->isEmpty()) {
            return [];
        }

        if ($conditionBranch !== null) {
            $matched = $this->matchBranch($outgoing, $conditionBranch);
            if ($matched !== []) {
                return $matched;
            }
        }

        $primaryBranch = $success
            ? AutomationEdgeBranch::Success->value
            : AutomationEdgeBranch::Failure->value;

        $matched = $this->matchBranch($outgoing, $primaryBranch);
        if ($matched !== []) {
            return $matched;
        }

        if (! $success) {
            return [];
        }

        return $this->matchBranch($outgoing, AutomationEdgeBranch::Always->value);
    }

    /**
     * @param  Collection<int, object>  $outgoing
     * @return list<object>
     */
    private function matchBranch(Collection $outgoing, string $branch): array
    {
        return $outgoing
            ->filter(static fn ($e): bool => (string) ($e->branch ?? '') === $branch)
            ->values()
            ->all();
    }
}
