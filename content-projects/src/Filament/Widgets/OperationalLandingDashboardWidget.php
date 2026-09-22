<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\OperationalLandingDashboardReadModel;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Widgets\Widget;

/**
 * Role-aware operational landing Dashboard (content canvas only).
 */
final class OperationalLandingDashboardWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.operational-landing-dashboard';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, mixed> */
    protected $listeners = [
        'seoGlobalSiteChanged' => '$refresh',
        'domain-context-changed' => '$refresh',
    ];

    public static function canView(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $resolved = app(OperationalLandingDashboardReadModel::class)->forEffectiveRole();

        return [
            'variant' => (string) ($resolved['variant'] ?? 'content_manager'),
            'data' => is_array($resolved['payload'] ?? null) ? $resolved['payload'] : [],
        ];
    }
}
