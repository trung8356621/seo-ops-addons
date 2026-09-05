<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Settings;

use App\Core\Settings\SettingsSection;
use App\Core\Settings\SettingsSectionContributor;
use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;

/**
 * Shared AI / API settings — URLs point at Admin (Core) canonical panel.
 */
final class AiCoreSettingsContributor implements SettingsSectionContributor
{
    public function ownerSlug(): string
    {
        return 'ai-prompt';
    }

    public function sections(): array
    {
        // CoreSettingsHub already registers AI/API as coreShared when classes exist.
        // Keep contributor empty to avoid duplicate hub cards; SEO menu uses CoreUrls helper.
        return [];
    }

    public static function aiCenterAdminUrl(): string
    {
        try {
            return SeoSettingsAiCenter::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/settings/ai-center');
        }
    }

    public static function apiConnectionsAdminUrl(): string
    {
        try {
            return AiConnectionResource::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/settings/api');
        }
    }
}
