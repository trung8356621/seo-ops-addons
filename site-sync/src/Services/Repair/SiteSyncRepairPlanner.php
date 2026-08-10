<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Repair;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncInboundEventJob;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncComparisonDiff;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRepairPlan;
use Omnichannel\Addons\SiteSync\Services\Handshake\SiteSyncHandshakeService;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use App\Models\Site;
use Illuminate\Support\Str;

final class SiteSyncRepairPlanner
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncHandshakeService $handshake,
        private readonly WordPressSiteSyncClient $client,
    ) {}

    /**
     * @param  list<int>  $diffIds
     * @return array{success: bool, message: string, plan_id?: int, public_ref?: string, items?: list<array<string, mixed>>}
     */
    public function preview(Site $site, array $diffIds = []): array
    {
        if (! $this->flags->repairEnabled()) {
            return ['success' => false, 'message' => 'Repair disabled by flag'];
        }

        $items = [];
        $diffs = SeoSiteSyncComparisonDiff::query()
            ->where('site_id', (int) $site->id)
            ->when($diffIds !== [], static fn ($q) => $q->whereIn('id', $diffIds))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        foreach ($diffs as $diff) {
            $action = match ((string) $diff->reason_code) {
                'missing_in_v2' => 'request_missing_snapshot',
                'invalid_legacy_url', 'invalid_v2_url' => 'normalize_url',
                'dead_letter_critical' => 'requeue_inbound',
                default => null,
            };
            if ($action === null) {
                continue;
            }
            $items[] = [
                'id' => 'repair_'.(int) $diff->id,
                'diff_id' => (int) $diff->id,
                'action' => $action,
                'reason_code' => (string) $diff->reason_code,
                'message' => (string) $diff->message,
            ];
        }

        // Always offer handshake refresh as optional selected repair.
        $items[] = [
            'id' => 'repair_handshake',
            'diff_id' => null,
            'action' => 'refresh_handshake',
            'reason_code' => 'handshake_refresh',
            'message' => 'Re-run callback handshake',
        ];
        $items[] = [
            'id' => 'repair_capability',
            'diff_id' => null,
            'action' => 'refresh_capability',
            'reason_code' => 'capability_refresh',
            'message' => 'Refresh capability manifest',
        ];

        $plan = SeoSiteSyncRepairPlan::query()->create([
            'site_id' => (int) $site->id,
            'public_ref' => 'ssrpr_'.Str::lower(Str::random(10)),
            'status' => 'preview',
            'dry_run' => true,
            'items' => $items,
            'result' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Repair preview (no mutation)',
            'plan_id' => (int) $plan->id,
            'public_ref' => (string) $plan->public_ref,
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $selectedIds
     * @return array{success: bool, message: string, result?: array<string, mixed>}
     */
    public function execute(Site $site, int $planId, array $selectedIds, bool $dryRun, ?int $actorId): array
    {
        if (! $this->flags->repairEnabled()) {
            return ['success' => false, 'message' => 'Repair disabled by flag'];
        }

        $plan = SeoSiteSyncRepairPlan::query()
            ->where('site_id', (int) $site->id)
            ->whereKey($planId)
            ->first();
        if ($plan === null) {
            return ['success' => false, 'message' => 'Repair plan not found'];
        }

        $items = is_array($plan->items) ? $plan->items : [];
        $selected = array_values(array_filter(
            $items,
            static fn (array $item): bool => in_array((string) ($item['id'] ?? ''), $selectedIds, true),
        ));
        if ($selected === []) {
            return ['success' => false, 'message' => 'No selected repair IDs'];
        }

        $results = [];
        foreach ($selected as $item) {
            $action = (string) ($item['action'] ?? '');
            if ($dryRun) {
                $results[] = ['id' => $item['id'], 'action' => $action, 'status' => 'dry_run_ok'];
                continue;
            }
            $results[] = match ($action) {
                'refresh_handshake' => [
                    'id' => $item['id'],
                    'action' => $action,
                    'status' => 'ok',
                    'detail' => $this->handshake->validate($site),
                ],
                'refresh_capability' => [
                    'id' => $item['id'],
                    'action' => $action,
                    'status' => ($this->client->fetchCapabilities($site)['success'] ?? false) ? 'ok' : 'failed',
                ],
                'requeue_inbound' => $this->requeueLatestDeadLetter($site, (string) $item['id']),
                default => ['id' => $item['id'], 'action' => $action, 'status' => 'skipped_preview_only'],
            };
        }

        $plan->forceFill([
            'status' => $dryRun ? 'preview' : 'executed',
            'dry_run' => $dryRun,
            'actor_id' => $actorId,
            'result' => ['items' => $results],
        ])->save();

        return [
            'success' => true,
            'message' => $dryRun ? 'Repair dry-run' : 'Repair executed for selected items',
            'result' => ['items' => $results],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requeueLatestDeadLetter(Site $site, string $repairId): array
    {
        $event = SeoSiteSyncInboundEvent::query()
            ->where('site_id', (int) $site->id)
            ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
            ->orderByDesc('id')
            ->first();
        if ($event === null) {
            return ['id' => $repairId, 'action' => 'requeue_inbound', 'status' => 'noop'];
        }
        $event->forceFill([
            'status' => SeoSiteSyncInboundEvent::STATUS_QUEUED,
            'retry_after' => null,
        ])->save();
        ProcessSiteSyncInboundEventJob::dispatch((int) $event->id);

        return ['id' => $repairId, 'action' => 'requeue_inbound', 'status' => 'queued', 'event_id' => (int) $event->id];
    }
}
