<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Shared UI visibility helpers for Chat / Agent Workspace surfaces.
 */
final class AgentWorkspaceUiContext
{
    /**
     * Floating chat is retired. Kept for compatibility checks / tests.
     */
    public static function hidesGlobalChat(): bool
    {
        return true;
    }

    public static function isChatWorkspacePath(?string $path = null): bool
    {
        $path ??= trim(request()->path(), '/');

        if (request()->routeIs([
            'filament.seo.pages.chat',
            'filament.seo.pages.agent',
            'filament.admin.pages.agent',
        ])) {
            return true;
        }

        return (bool) preg_match('#^seo/[^/]+/(?:chat|agent)(?:/|$|\?)#', $path)
            || $path === 'admin/agent'
            || str_starts_with($path, 'admin/agent/');
    }
}
