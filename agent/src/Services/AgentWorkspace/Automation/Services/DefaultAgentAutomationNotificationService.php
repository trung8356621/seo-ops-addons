<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationNotificationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationNotificationData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Support\RuntimeLogger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class DefaultAgentAutomationNotificationService implements AgentAutomationNotificationService
{
    public function maybeNotify(
        AgentWorkspaceContext $context,
        array $notificationConfig,
        AgentAutomationNotificationData $data,
        array $runContext,
    ): array {
        $policy = (string) ($notificationConfig['policy'] ?? $data->policy);
        if ($policy === 'silent_success' && ($runContext['status'] ?? '') === 'succeeded') {
            return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'silent_success'];
        }
        if ($policy === 'failure_only' && ($runContext['status'] ?? '') !== 'failed') {
            return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'failure_only'];
        }
        if ($policy === 'condition_matched' && ! ($runContext['condition_matched'] ?? false)) {
            return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'condition_not_matched'];
        }
        if ($policy === 'change_only' && ! ($runContext['changed'] ?? false)) {
            return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'no_change'];
        }

        $cooldown = max(0, (int) ($notificationConfig['cooldown_minutes'] ?? 60));
        $dedupeKey = 'agent_auto_notify:'.$context->siteId.':'.$data->fingerprint;
        if ($cooldown > 0 && Cache::has($dedupeKey)) {
            $prevSeverity = (string) Cache::get($dedupeKey.':sev', '');
            if ($prevSeverity === $data->severity) {
                return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'dedupe_cooldown'];
            }
        }

        $quiet = is_array($runContext['quiet_hours'] ?? null) ? $runContext['quiet_hours'] : null;
        $timezone = (string) ($runContext['timezone'] ?? 'UTC');
        if ($quiet !== null && $this->inQuietHours($quiet, $timezone)) {
            $qPolicy = (string) ($quiet['policy'] ?? 'delay_notification');
            if ($qPolicy === 'skip_non_critical' && $data->severity !== 'critical') {
                return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'quiet_hours_skip'];
            }
            if ($qPolicy === 'delay_notification') {
                $deliverAt = $this->quietHoursEnd($quiet, $timezone);

                return [
                    'sent' => false,
                    'delayed' => true,
                    'skipped' => false,
                    'reason' => 'quiet_hours_delay',
                    'data' => array_merge($data->toArray(), [
                        'delayed' => true,
                        'deliver_at' => $deliverAt,
                        'run_hash_id' => $data->runHashId,
                    ]),
                ];
            }
            // ignore → fall through
        }

        $hourKey = 'agent_auto_notify_hour:'.$context->siteId.':'.gmdate('YmdH');
        $count = (int) Cache::get($hourKey, 0);
        $max = (int) ($runContext['max_notifications_per_hour'] ?? 40);
        if ($count >= $max) {
            return ['sent' => false, 'delayed' => false, 'skipped' => true, 'reason' => 'notification_quota'];
        }

        $sentDestinations = [];
        foreach ($data->destinations as $dest) {
            $dest = (string) $dest;
            if ($dest === 'email' && ! ($runContext['email_configured'] ?? false)) {
                $sentDestinations[] = 'agent_workspace'; // fallback
                continue;
            }
            $sentDestinations[] = $dest;
        }

        try {
            RuntimeLogger::info('agent.automation.notification', [
                'site_ref' => $context->siteRef,
                'fingerprint' => $data->fingerprint,
                'destinations' => $sentDestinations,
                'run_hash_id' => $data->runHashId,
                'automation_hash_id' => $data->automationHashId,
                'title' => $data->title,
                // no secrets
            ]);
        } catch (Throwable) {
            // ignore logging failures
        }

        Cache::put($dedupeKey, 1, $cooldown * 60);
        Cache::put($dedupeKey.':sev', $data->severity, $cooldown * 60);
        Cache::put($hourKey, $count + 1, 3600);

        return [
            'sent' => true,
            'delayed' => false,
            'skipped' => false,
            'data' => array_merge($data->toArray(), ['destinations' => $sentDestinations]),
        ];
    }

    /**
     * @param  array<string, mixed>  $quiet
     */
    private function inQuietHours(array $quiet, string $timezone): bool
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }
        $now = new DateTimeImmutable('now', $tz);
        $start = (string) ($quiet['start'] ?? '22:00');
        $end = (string) ($quiet['end'] ?? '07:00');
        $cur = $now->format('H:i');
        if ($start <= $end) {
            return $cur >= $start && $cur < $end;
        }

        // wraps midnight
        return $cur >= $start || $cur < $end;
    }

    /**
     * @param  array<string, mixed>  $quiet
     */
    private function quietHoursEnd(array $quiet, string $timezone): string
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }
        $now = new DateTimeImmutable('now', $tz);
        $end = (string) ($quiet['end'] ?? '07:00');
        [$h, $m] = array_map('intval', explode(':', $end));
        $candidate = $now->setTime($h, $m, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
