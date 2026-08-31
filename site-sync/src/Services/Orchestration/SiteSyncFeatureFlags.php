<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

final class SiteSyncFeatureFlags
{
    private function cfg(string $key, mixed $default = false): mixed
    {
        return config('seo-content-ai.seo_architecture.site_sync_v2.'.$key, $default);
    }

    public function emergencyRollback(): bool
    {
        return (bool) $this->cfg('emergency_rollback', false);
    }

    public function enabled(): bool
    {
        if ($this->emergencyRollback()) {
            return false;
        }

        return (bool) $this->cfg('enabled', true);
    }

    public function orchestratorEnabled(): bool
    {
        return $this->enabled() && (bool) $this->cfg('orchestrator', true);
    }

    public function uiEnabled(): bool
    {
        if ($this->emergencyRollback()) {
            return false;
        }

        $ui = $this->cfg('ui', null);
        if ($ui === null) {
            $ui = $this->cfg('ui_enabled', true);
        }

        return $this->enabled() && (bool) $ui;
    }

    public function legacyActionsVisible(): bool
    {
        if ($this->emergencyRollback()) {
            return true;
        }

        $legacy = $this->cfg('legacy_actions', null);
        if ($legacy === null) {
            $legacy = $this->cfg('legacy_actions_visible', false);
        }

        return (bool) $legacy;
    }

    public function compatPushEnabled(): bool
    {
        return (bool) $this->cfg('compat_push', true);
    }

    public function dualRunShadowEnabled(): bool
    {
        return (bool) $this->cfg('dual_run_shadow', false);
    }

    public function autoPushEnabled(): bool
    {
        if ($this->emergencyRollback()) {
            return false;
        }

        $auto = $this->cfg('auto_push', null);
        if ($auto === null) {
            $auto = $this->cfg('auto_push_enabled', true);
        }

        return $this->enabled() && (bool) $auto;
    }

    public function reconciliationEnabled(): bool
    {
        if ($this->emergencyRollback()) {
            return false;
        }

        $rec = $this->cfg('reconciliation', null);
        if ($rec === null) {
            $rec = $this->cfg('reconciliation_enabled', true);
        }

        return $this->enabled() && (bool) $rec;
    }

    public function workspaceFallbackEnabled(): bool
    {
        $fb = $this->cfg('workspace_fallback', null);
        if ($fb === null) {
            $fb = $this->cfg('workspace_fallback_enabled', true);
        }

        return (bool) $fb;
    }

    /**
     * @deprecated Zero callers — do not use for contract decisions.
     */
    public function acceptV1Contract(): bool
    {
        return (bool) $this->cfg('accept_v1_contract', true);
    }

    public function requireSignedCallbacks(): bool
    {
        return (bool) $this->cfg('require_signed_callbacks', false);
    }

    public function defaultMode(): string
    {
        return (string) $this->cfg('mode_default', 'legacy_active');
    }

    /**
     * @deprecated Zero callers — do not use for contract decisions.
     */
    public function shadowComparisonEnabled(): bool
    {
        return (bool) $this->cfg('shadow_comparison', true);
    }

    public function cutoverUiEnabled(): bool
    {
        return (bool) $this->cfg('cutover_ui', true);
    }

    public function allowManualActivate(): bool
    {
        return (bool) $this->cfg('allow_manual_activate', true);
    }

    public function allowRollback(): bool
    {
        return (bool) $this->cfg('allow_rollback', true);
    }

    public function legacySchedulerEnabled(): bool
    {
        if ($this->emergencyRollback()) {
            return true;
        }

        return (bool) $this->cfg('legacy_scheduler', true);
    }

    public function comparisonExportEnabled(): bool
    {
        return (bool) $this->cfg('comparison_export', true);
    }

    public function repairEnabled(): bool
    {
        return (bool) $this->cfg('repair_enabled', true);
    }

    /**
     * Site Sync V3 protocol gate.
     * Config: seo-content-ai.seo_architecture.site_sync_v3.enabled (default true).
     */
    public function protocolV3Enabled(): bool
    {
        if ($this->emergencyRollback()) {
            return false;
        }

        return (bool) config(
            'seo-content-ai.seo_architecture.site_sync_v3.enabled',
            true,
        );
    }
}
