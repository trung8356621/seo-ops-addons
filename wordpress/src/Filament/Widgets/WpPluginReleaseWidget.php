<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Filament\Widgets;

use Omnichannel\Addons\WordPress\Services\WordPressPluginDomainsOverviewService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Widgets\Widget;

class WpPluginReleaseWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.wp-plugin-release';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
    ];

    public static function canView(): bool
    {
        return SeoAccessControl::canManageWordPressPlugin()
            && ! SeoAccessControl::hasGlobalSiteScope();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(WordPressPluginDomainsOverviewService::class)->overview();
    }
}
