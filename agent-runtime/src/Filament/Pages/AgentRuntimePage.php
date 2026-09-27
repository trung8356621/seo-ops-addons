<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Filament\Pages;

use Filament\Pages\Page;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Internal standalone host / development harness page for Filament panels.
 *
 * NOTE: This is NOT the canonical end-user UX. Agent Runtime is a global support
 * addon whose canonical UI is the embeddable AgentWidget React component.
 * In Phase 2, this page can be hidden from sidebar navigation via
 * config('agent-runtime.standalone_harness_navigation', false) while remaining
 * available for direct harness testing at /seo/agent-runtime.
 */
class AgentRuntimePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $slug = 'agent-runtime';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'agent-runtime::agent-runtime';

    public static function shouldRegisterNavigation(): bool
    {
        if (function_exists('config')) {
            try {
                return (bool) config('agent-runtime.standalone_harness_navigation', false);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Agent';
    }

    public function getTitle(): string
    {
        return 'Agent';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        if (class_exists(SeoAccessControl::class) && ! SeoAccessControl::canAccessSeoPanel()) {
            return false;
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Explicitly permitted via config (e.g. tests or internal dev flag)
        if (function_exists('config') && (bool) config('agent-runtime.standalone_harness_enabled', false)) {
            return true;
        }

        // Role-based authorization: only owners/admins can directly access the internal harness
        $role = $user->role ?? null;
        if ($role === 'owner' || $role === 'admin') {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            if ($user->hasRole('owner') || $user->hasRole('admin')) {
                return true;
            }
        }

        return false;
    }
}
