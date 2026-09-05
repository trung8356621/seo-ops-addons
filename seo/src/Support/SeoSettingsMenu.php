<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use App\Filament\Resources\UserResource;

/**
 * Settings navigation — driven by Core SettingsSectionRegistry (canonical Admin URLs).
 * SEO sidebar reuses the same menu; multiple nav entry points, one implementation.
 */
final class SeoSettingsMenu
{
    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    public static function items(): array
    {
        try {
            /** @var SettingsSectionRegistry $registry */
            $registry = app(SettingsSectionRegistry::class);
            app(CoreSettingsBootstrap::class)->seed($registry);

            $items = $registry->menuItems();
            if ($items !== []) {
                return $items;
            }
        } catch (\Throwable) {
            // fall through to static fallback
        }

        return self::fallbackItems();
    }

    /**
     * @return list<array{id: string, label: string, icon: string, url: string}>
     */
    private static function fallbackItems(): array
    {
        return [
            [
                'id' => 'general',
                'label' => 'seo-content-ai::filament.settings_general.nav',
                'icon' => 'heroicon-o-cog-6-tooth',
                'url' => url('/admin/settings/general'),
            ],
            [
                'id' => 'ai-center',
                'label' => 'AI Center',
                'icon' => 'heroicon-o-cpu-chip',
                'url' => url('/admin/settings/ai-center'),
            ],
            [
                'id' => 'api',
                'label' => 'API Connections',
                'icon' => 'heroicon-o-link',
                'url' => url('/admin/settings/api'),
            ],
            [
                'id' => 'members',
                'label' => 'Members',
                'icon' => 'heroicon-o-users',
                'url' => SeoTeamMembersUrl::resolve(),
            ],
        ];
    }
}

/**
 * Members shortcut: Core Admin when actor can access; else SEO Team page.
 */
final class SeoTeamMembersUrl
{
    public static function resolve(): string
    {
        try {
            if (UserResource::canAccess()) {
                return UserResource::getUrl(panel: 'admin');
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            return \Omnichannel\Addons\SearchFoundation\Filament\Pages\SeoTeam::getUrl();
        } catch (\Throwable) {
            return url('/admin/users');
        }
    }
}
