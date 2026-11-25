<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Core\Capability\CapabilityRegistry;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SiteSync\Contracts\SiteHealthNotificationCapability;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;
use Omnichannel\Addons\SiteSync\Models\SiteHealthState;
use Throwable;

final class SiteHealthStateService
{
    /** Independent failed checks required before confirming an incident. */
    public const CONFIRM_FAILURES = 3;

    /** Consecutive healthy checks required to close an open incident. */
    public const RECOVERY_SUCCESSES = 2;

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    public function record(Site $site, SiteHealthResult $result): SiteHealthState
    {
        [$state, $notification, $incident] = DB::connection('omi_seo_ai')->transaction(function () use ($site, $result): array {
            $now = now();
            $state = SiteHealthState::query()->lockForUpdate()->firstOrNew(['site_id' => (int) $site->id]);

            if ($result->healthy) {
                return $this->recordSuccess($site, $state, $result, $now);
            }

            return $this->recordFailure($site, $state, $result, $now);
        });

        if (is_string($notification) && $incident instanceof SiteHealthIncident) {
            try {
                $notifier = $this->notifier();
                if ($notification === 'resolved') {
                    $notifier?->incidentResolved($site, $incident);
                } else {
                    $notifier?->incidentActive($site, $incident);
                }
            } catch (Throwable $exception) {
                RuntimeLogger::report($exception, [
                    'source' => self::class,
                    'site_id' => (int) $site->id,
                    'site_health_incident_id' => (int) $incident->id,
                    'site_health_notification' => $notification,
                ]);
            }
        }

        return $state;
    }

    /**
     * @return array{0: SiteHealthState, 1: ?string, 2: ?SiteHealthIncident}
     */
    private function recordSuccess(Site $site, SiteHealthState $state, SiteHealthResult $result, mixed $now): array
    {
        $incident = $state->active_incident_id ? SiteHealthIncident::query()->find($state->active_incident_id) : null;
        $openIncident = $incident instanceof SiteHealthIncident && $incident->resolved_at === null;

        if (! $openIncident) {
            $state->forceFill([
                'active_incident_id' => null, 'status' => 'healthy', 'severity' => null,
                'error_code' => null, 'reason' => null, 'technical_error' => null,
                'diagnostic_stages' => $result->stages, 'consecutive_failures' => 0,
                'first_failure_at' => null, 'last_checked_at' => $now, 'last_success_at' => $now,
            ])->save();

            return [$state, null, null];
        }

        // First healthy check while incident is open → recovering (no email).
        if ($state->status !== 'recovering') {
            $state->forceFill([
                'status' => 'recovering',
                'severity' => null,
                'error_code' => null,
                'reason' => null,
                'technical_error' => null,
                'diagnostic_stages' => $result->stages,
                'consecutive_failures' => 0,
                'last_checked_at' => $now,
                'last_success_at' => $now,
            ])->save();

            return [$state, null, null];
        }

        // Second consecutive healthy check → close incident and emit recovery once.
        $incident->forceFill(['status' => 'resolved', 'resolved_at' => $now, 'last_occurred_at' => $now])->save();
        $state->forceFill([
            'active_incident_id' => null, 'status' => 'healthy', 'severity' => null,
            'error_code' => null, 'reason' => null, 'technical_error' => null,
            'diagnostic_stages' => $result->stages, 'consecutive_failures' => 0,
            'first_failure_at' => null, 'last_checked_at' => $now, 'last_success_at' => $now,
        ])->save();

        return [$state, 'resolved', $incident];
    }

    /**
     * @return array{0: SiteHealthState, 1: ?string, 2: ?SiteHealthIncident}
     */
    private function recordFailure(Site $site, SiteHealthState $state, SiteHealthResult $result, mixed $now): array
    {
        $failures = (int) $state->consecutive_failures + 1;
        $state->forceFill([
            'status' => $failures >= self::CONFIRM_FAILURES ? $result->status : 'suspected',
            'severity' => $result->severity, 'error_code' => $result->errorCode,
            'reason' => $result->reason, 'technical_error' => SiteHealthSanitizer::clean($result->technicalError),
            'diagnostic_stages' => $result->stages, 'consecutive_failures' => $failures,
            'first_failure_at' => $state->first_failure_at ?? $now, 'last_checked_at' => $now,
        ])->save();

        if ($failures < self::CONFIRM_FAILURES) {
            return [$state, null, null];
        }

        $incident = $state->active_incident_id ? SiteHealthIncident::query()->find($state->active_incident_id) : null;
        $needsNewIncident = ! $incident instanceof SiteHealthIncident || $incident->resolved_at !== null;

        if ($needsNewIncident) {
            $incident = SiteHealthIncident::query()->create([
                'site_id' => (int) $site->id, 'status' => $result->status, 'severity' => $result->severity,
                'error_code' => $result->errorCode, 'reason' => $result->reason,
                'technical_error' => SiteHealthSanitizer::clean($result->technicalError),
                'diagnostic_stages' => $result->stages, 'detected_at' => $state->first_failure_at ?? $now,
                'last_occurred_at' => $now,
            ]);
            $state->forceFill(['active_incident_id' => $incident->id])->save();

            // Notify only when a new incident is confirmed — never on subsequent cron failures.
            return [$state, 'active', $incident];
        }

        $incident->forceFill([
            'status' => $result->status, 'severity' => $result->severity, 'error_code' => $result->errorCode,
            'reason' => $result->reason, 'technical_error' => SiteHealthSanitizer::clean($result->technicalError),
            'diagnostic_stages' => $result->stages, 'last_occurred_at' => $now,
        ])->save();

        return [$state, null, $incident];
    }

    private function notifier(): ?SiteHealthNotificationCapability
    {
        return $this->capabilities->getAs(SiteHealthNotificationCapability::ID, SiteHealthNotificationCapability::class);
    }
}
