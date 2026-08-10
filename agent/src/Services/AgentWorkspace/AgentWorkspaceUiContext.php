<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Shared UI visibility helpers for Agent Workspace surfaces.
 */
final class AgentWorkspaceUiContext
{
    /**
     * Global floating chat must not mount on Agent Workspace pages.
     */
    public static function hidesGlobalChat(): bool
    {
        if (request()->routeIs([
            'filament.seo.pages.agent',
            'filament.admin.pages.agent',
        ])) {
            return true;
        }

        // Fallback when route name not resolved (Livewire subsequent requests / odd mounts).
        $path = trim(request()->path(), '/');

        return (bool) preg_match('#^seo/[^/]+/agent(?:/|$|\?)#', $path)
            || $path === 'admin/agent'
            || str_starts_with($path, 'admin/agent/');
    }
}
