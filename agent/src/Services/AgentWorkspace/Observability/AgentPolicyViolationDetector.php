<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use App\Support\RuntimeLogger;
use Throwable;

/**
 * Detects policy violations from sanitized payloads. No permanent bans.
 */
final class AgentPolicyViolationDetector
{
    public function __construct(
        private readonly ?AgentMetricRecorder $metrics = null,
        private readonly ?AgentObservabilityEventBus $bus = null,
        private readonly ?AgentReviewService $reviews = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, severity: string, action: string}>
     */
    public function inspect(array $payload, ?string $traceId = null, ?int $siteId = null): array
    {
        $violations = [];
        $encoded = strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

        $checks = [
            ['internal_skill', ['is_hidden' => true, 'internal_skill', 'hidden_skill'], 'high', 'reject'],
            ['auto_confirm', ['auto_confirm', 'disable_confirmation', 'force_none'], 'critical', 'reject'],
            ['cross_site', ['cross_site', 'site_override'], 'critical', 'reject'],
            ['owner_override', ['owner_user_id', 'browser_owner'], 'high', 'reject'],
            ['confirmation_bypass', ['skip_confirmation', 'bypass_confirm'], 'critical', 'reject'],
            ['secret_persistence', ['raw_token', 'awconf_', 'awautoapr_', 'password='], 'critical', 'reject'],
            ['arbitrary_tool', ['eval(', '<?php', 'classname', '::class'], 'critical', 'reject'],
            ['invalid_automation_frequency', ['interval_minutes":1', '"cron"'], 'high', 'reject'],
            ['destructive_auto_run', ['auto_execute', 'auto_publish', 'auto_delete'], 'critical', 'reject'],
            ['fabricated_citation', ['fake_citation', 'citation_id":"made'], 'high', 'review'],
            ['unauthorized_knowledge', ['cross_site_knowledge'], 'high', 'reject'],
        ];

        foreach ($checks as [$code, $needles, $severity, $action]) {
            foreach ($needles as $needle) {
                if (is_string($needle) && str_contains($encoded, $needle)) {
                    $violations[] = ['code' => $code, 'severity' => $severity, 'action' => $action];
                    break;
                }
                if (is_array($needle)) {
                    // skip
                }
            }
        }

        // Also check explicit flags
        if (($payload['auto_confirm'] ?? false) === true) {
            $violations[] = ['code' => 'auto_confirm', 'severity' => 'critical', 'action' => 'reject'];
        }
        if (($payload['is_hidden'] ?? false) === true && ($payload['proposed'] ?? false) === true) {
            $violations[] = ['code' => 'internal_skill', 'severity' => 'high', 'action' => 'reject'];
        }

        $unique = [];
        foreach ($violations as $v) {
            $unique[$v['code']] = $v;
        }
        $violations = array_values($unique);

        foreach ($violations as $v) {
            $this->metrics?->record(
                'security.policy_violation',
                1,
                ['policy_code' => $v['code'], 'severity' => $v['severity']],
                $traceId,
                $siteId,
                null,
                $v['severity'],
            );
            $this->bus?->dispatch([
                'event_type' => 'policy.violation',
                'trace_id' => $traceId ?? 'none',
                'attributes' => $v,
                'severity' => $v['severity'],
            ]);
            if (in_array($v['severity'], ['high', 'critical'], true)) {
                $this->reviews?->createFromPolicy($v, $traceId, $siteId);
                RuntimeLogger::warning('agent.policy.violation', [
                    'code' => $v['code'],
                    'severity' => $v['severity'],
                    'trace_id' => $traceId,
                    // no payload secrets
                ]);
            }
        }

        return $violations;
    }
}
