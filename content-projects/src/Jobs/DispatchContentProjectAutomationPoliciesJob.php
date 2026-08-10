<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Jobs;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\AgentPlanTriggerType;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanApplicationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatch scheduled automation policies — one plan per policy per period.
 */
final class DispatchContentProjectAutomationPoliciesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        // Never land on `default` / automation-external — dedicated policy worker.
        $this->onQueue(AutomationQueueName::Policy->value);
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectAgentPlanApplicationService $application,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $enabledTriggers = config('seo-content-ai.content_project_agent.automation.enabled_triggers', ['manual', 'api', 'scheduled']);
        if (! in_array('scheduled', $enabledTriggers, true)) {
            return;
        }

        $policies = ContentProjectAutomationPolicy::query()
            ->where('enabled', true)
            ->where('automation_level', '!=', 'manual')
            ->get();

        $period = now()->format('Y-m-d-H');

        foreach ($policies as $policy) {
            $lockKey = 'automation-policy:'.$policy->public_ref.':'.$period;
            $lock = Cache::lock($lockKey, 3600);
            if (! $lock->get()) {
                continue;
            }

            try {
                if ($policy->site_id === null || (int) $policy->site_id <= 0) {
                    continue;
                }

                $siteRef = ContentProjectPublicRef::site((int) $policy->site_id);
                $context = AgentExecutionContext::fromArray([
                    'actor_ref' => 'agent:policy:'.$policy->public_ref,
                    'actor_type' => 'system',
                    'tenant_ref' => 'tenant:'.$policy->tenant_id,
                    'site_ref' => $siteRef,
                    'request_ref' => 'policy-dispatch:'.$policy->public_ref.':'.$period,
                    'resolved_site_id' => (int) $policy->site_id,
                    'scopes' => app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentScopeEvaluator::class)
                        ->forSystemActor([
                            'content-project:read',
                            'content-project:publish',
                            'content-project:schedule',
                        ]),
                ]);

                $draft = $application->createDraft($context, 'Scheduled automation: '.$policy->name, [
                    'trigger_type' => AgentPlanTriggerType::SCHEDULED,
                    'template' => 'publish_due_check',
                ]);

                if (($draft['success'] ?? false) === true && isset($draft['data']['plan_ref'])) {
                    $application->confirmPlan($context, (string) $draft['data']['plan_ref']);
                    $application->startPlan($context, (string) $draft['data']['plan_ref']);
                }
            } finally {
                $lock->release();
            }
        }
    }
}
