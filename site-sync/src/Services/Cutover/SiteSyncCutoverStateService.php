<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Cutover;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncCutoverState;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Throwable;

final class SiteSyncCutoverStateService
{
    public const META_MODE = 'seo_site_sync_v2_cutover_mode';

    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncCheckpointService $checkpoints,
        private readonly SiteSyncCutoverScorecardService $scorecard,
    ) {}

    public function modeFor(Site $site): string
    {
        if ($this->flags->emergencyRollback()) {
            return SiteSyncCutoverModes::LEGACY_ACTIVE;
        }

        $default = (string) $this->flags->defaultMode();
        if (! in_array($default, SiteSyncCutoverModes::ALL, true)) {
            $default = SiteSyncCutoverModes::LEGACY_ACTIVE;
        }

        if (! SiteSyncInfrastructure::hasTable('seo_site_sync_cutover_states')) {
            return $default;
        }

        try {
            $row = SeoSiteSyncCutoverState::query()->where('site_id', (int) $site->id)->first();
            if ($row !== null && in_array((string) $row->mode, SiteSyncCutoverModes::ALL, true)) {
                return (string) $row->mode;
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.cutover_mode',
                'site_id' => (int) $site->id,
            ]);
        }

        return $default;
    }

    public function isV2Writer(Site $site): bool
    {
        $mode = $this->modeFor($site);

        return in_array($mode, [SiteSyncCutoverModes::V2_SHADOW, SiteSyncCutoverModes::V2_ACTIVE], true)
            && $this->flags->enabled();
    }

    public function isV2Active(Site $site): bool
    {
        return $this->modeFor($site) === SiteSyncCutoverModes::V2_ACTIVE && $this->flags->enabled();
    }

    public function legacySchedulerAllowed(Site $site): bool
    {
        if (! $this->flags->legacySchedulerEnabled()) {
            return false;
        }
        if ($this->flags->emergencyRollback()) {
            return true;
        }

        return $this->modeFor($site) === SiteSyncCutoverModes::LEGACY_ACTIVE;
    }

    /**
     * @return array{success: bool, message: string, mode?: string, checkpoint_id?: int, scorecard?: array<string, mixed>}
     */
    public function preview(Site $site): array
    {
        $mode = $this->modeFor($site);
        $card = $this->scorecard->evaluate($site, $mode);
        $checkpoint = $this->checkpoints->create(
            $site,
            'preview',
            $mode,
            null,
            'system',
            null,
            'cutover preview',
        );

        return [
            'success' => true,
            'message' => 'Cutover preview',
            'mode' => $mode,
            'checkpoint_id' => (int) $checkpoint->id,
            'scorecard' => $card,
            'allowed_next' => $this->allowedNext($mode),
        ];
    }

    /**
     * @param  array{actor_type?: string, actor_id?: int|null, reason?: string, confirmation_token?: string|null, emergency?: bool}  $ctx
     * @return array{success: bool, message: string, mode?: string, checkpoint_id?: int, code?: string}
     */
    public function transition(Site $site, string $to, array $ctx = []): array
    {
        $from = $this->modeFor($site);
        $emergency = (bool) ($ctx['emergency'] ?? false);

        if ($from === $to) {
            return ['success' => true, 'message' => 'Already in mode '.$to, 'mode' => $to];
        }

        if (! SiteSyncCutoverModes::canTransition($from, $to, $emergency)) {
            return [
                'success' => false,
                'code' => 'invalid_transition',
                'message' => "Transition {$from} → {$to} not allowed",
            ];
        }

        if (! $this->flags->cutoverUiEnabled() && ($ctx['actor_type'] ?? '') === 'user') {
            return ['success' => false, 'code' => 'cutover_ui_disabled', 'message' => 'Cutover UI disabled'];
        }

        if ($to === SiteSyncCutoverModes::V2_ACTIVE) {
            if (! $this->flags->allowManualActivate()) {
                return ['success' => false, 'code' => 'activate_disabled', 'message' => 'Manual activate disabled by flag'];
            }
            $card = $this->scorecard->evaluate($site, $from);
            if (($card['status'] ?? '') === 'not_ready' || ($card['status'] ?? '') === 'rollback_recommended') {
                return [
                    'success' => false,
                    'code' => 'blocking_readiness',
                    'message' => 'Readiness blocking activation: '.((string) ($card['status'] ?? '')),
                    'scorecard' => $card,
                ];
            }
            if (! empty($card['has_blocking'])) {
                return [
                    'success' => false,
                    'code' => 'blocking_issues',
                    'message' => 'Blocking issues prevent activation',
                    'scorecard' => $card,
                ];
            }
            if (trim((string) ($ctx['confirmation_token'] ?? '')) === '') {
                return ['success' => false, 'code' => 'confirmation_required', 'message' => 'Confirmation token required'];
            }
        }

        if ($to === SiteSyncCutoverModes::LEGACY_ACTIVE && $from === SiteSyncCutoverModes::V2_ACTIVE) {
            if (! $this->flags->allowRollback()) {
                return ['success' => false, 'code' => 'rollback_disabled', 'message' => 'Rollback disabled by flag'];
            }
            if (trim((string) ($ctx['confirmation_token'] ?? '')) === '') {
                return ['success' => false, 'code' => 'confirmation_required', 'message' => 'Confirmation token required for rollback'];
            }
        }

        $checkpoint = $this->checkpoints->create(
            $site,
            $to === SiteSyncCutoverModes::LEGACY_ACTIVE && $from === SiteSyncCutoverModes::V2_ACTIVE
                ? 'rollback'
                : 'transition',
            $from,
            $to,
            (string) ($ctx['actor_type'] ?? 'system'),
            isset($ctx['actor_id']) ? (int) $ctx['actor_id'] : null,
            (string) ($ctx['reason'] ?? ''),
        );

        $row = SeoSiteSyncCutoverState::query()->firstOrNew(['site_id' => (int) $site->id]);
        $row->forceFill([
            'previous_mode' => $from,
            'mode' => $to,
            'checkpoint_id' => (int) $checkpoint->id,
            'shadow_started_at' => $to === SiteSyncCutoverModes::V2_SHADOW ? now() : $row->shadow_started_at,
            'activated_at' => $to === SiteSyncCutoverModes::V2_ACTIVE ? now() : $row->activated_at,
            'rolled_back_at' => $to === SiteSyncCutoverModes::LEGACY_ACTIVE && $from === SiteSyncCutoverModes::V2_ACTIVE
                ? now()
                : $row->rolled_back_at,
            'meta' => array_merge(is_array($row->meta) ? $row->meta : [], [
                'last_reason' => (string) ($ctx['reason'] ?? ''),
                'confirmation_token_id' => substr(hash('sha256', (string) ($ctx['confirmation_token'] ?? '')), 0, 16),
            ]),
        ])->save();

        SiteSyncSiteMeta::put($site, self::META_MODE, $to);

        RuntimeLogger::info('site_sync.cutover_transition', [
            'site_id' => (int) $site->id,
            'from' => $from,
            'to' => $to,
            'checkpoint_id' => (int) $checkpoint->id,
            'actor_type' => $ctx['actor_type'] ?? null,
            'actor_id' => $ctx['actor_id'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => "Cutover mode {$from} → {$to}",
            'mode' => $to,
            'checkpoint_id' => (int) $checkpoint->id,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedNext(string $mode): array
    {
        $next = [];
        foreach (SiteSyncCutoverModes::allowedTransitions($this->flags->allowManualActivate()) as [$from, $to]) {
            if ($from === $mode) {
                $next[] = $to;
            }
        }

        return array_values(array_unique($next));
    }
}
