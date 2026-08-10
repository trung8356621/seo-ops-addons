<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Filament\Widgets;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
use Filament\Widgets\Widget;

class WordPressPluginWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.wordpress-plugin';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public bool $showOlderVersions = false;

    public static function canView(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures()
            && SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $manifest = app(ExternalPluginRegistry::class)->resolveOrFail('omi-seo-ai-bridge');

        return WordPressPluginReleaseService::forManifest($manifest)->overview();
    }
}
