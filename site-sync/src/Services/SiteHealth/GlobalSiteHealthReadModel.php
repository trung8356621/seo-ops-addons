<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Core\Sites\SiteAccess;
use App\Models\Site;
use App\Models\User;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;
use Omnichannel\Addons\SiteSync\Models\SiteHealthState;

final class GlobalSiteHealthReadModel
{
    public function __construct(private readonly SiteAccess $access) {}

    /** @return array{severity: string, signature: string, incidents: list<array<string, mixed>>}|null */
    public function forUser(User $user): ?array
    {
        $siteIds = $this->access->accessibleSiteIds($user);
        if ($siteIds === []) {
            return null;
        }

        $states = SiteHealthState::query()->whereIn('site_id', $siteIds)->whereNotNull('active_incident_id')->get();
        if ($states->isEmpty()) {
            return null;
        }

        $sites = Site::query()->whereIn('id', $states->pluck('site_id'))->get()->keyBy('id');
        $incidents = SiteHealthIncident::query()->whereIn('id', $states->pluck('active_incident_id'))->whereNull('resolved_at')->get()->keyBy('id');
        $rows = [];
        foreach ($states as $state) {
            $incident = $incidents->get($state->active_incident_id);
            $site = $sites->get($state->site_id);
            if (! $incident instanceof SiteHealthIncident || ! $site instanceof Site) {
                continue;
            }
            $rows[] = [
                'id' => (int) $incident->id, 'site_id' => (int) $site->id, 'domain' => (string) $site->domain,
                'website_url' => preg_match('#^https?://#i', (string) $site->domain) ? (string) $site->domain : 'https://'.$site->domain,
                'status' => (string) $state->status, 'severity' => (string) $state->severity,
                'error_code' => (string) $state->error_code, 'reason' => (string) $state->reason,
                'technical_error' => SiteHealthSanitizer::clean((string) $state->technical_error),
                'stages' => $state->diagnostic_stages ?? [], 'detected_at' => $incident->detected_at?->toIso8601String(),
                'detected_ago' => $incident->detected_at?->diffForHumans(),
                'duration' => $incident->detected_at?->diffForHumans(now(), true),
                'last_checked_at' => $state->last_checked_at?->toIso8601String(), 'last_success_at' => $state->last_success_at?->toIso8601String(),
                'consecutive_failures' => (int) $state->consecutive_failures,
            ];
        }

        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));
        $severity = collect($rows)->contains(fn (array $row): bool => $row['severity'] === 'critical') ? 'critical' : 'warning';
        $signature = hash('sha256', implode('|', array_map(static fn (array $row): string => $row['id'].':'.$row['severity'], $rows)));

        return ['severity' => $severity, 'signature' => $signature, 'incidents' => $rows];
    }
}
