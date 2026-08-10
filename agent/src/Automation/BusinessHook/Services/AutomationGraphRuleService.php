<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator;

final class AutomationGraphRuleService
{
    public function __construct(
        private readonly AutomationGraphValidator $graphValidator,
        private readonly AutomationSchedulerService $scheduler,
        private readonly AutomationVersionService $versionService,
    ) {}

    /**
     * Persist draft graph via version service (preferred V3 path).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function save(
        AutomationRule $rule,
        array $nodes,
        array $edges,
        ?array $layout = null,
        ?int $expectedRevision = null,
        ?int $actorId = null,
    ): AutomationRule {
        $rule = $this->versionService->saveDraft($rule, $nodes, $edges, $layout, $expectedRevision, $actorId);

        if ($rule->trigger_type === 'schedule' && $rule->schedule_expression) {
            $next = $this->scheduler->computeNextRunAt($rule);
            $rule->forceFill(['next_run_at' => $next])->save();
        }

        return $rule->fresh(['nodes', 'edges', 'actions']) ?? $rule;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function syncGraph(AutomationRule $rule, array $nodes, array $edges): AutomationRule
    {
        return $this->save($rule, $nodes, $edges);
    }

    /**
     * @return list<string>
     */
    public function validateGraph(AutomationRule $rule): array
    {
        $rule->loadMissing(['nodes', 'edges']);

        return $this->graphValidator->validate($rule);
    }
}
