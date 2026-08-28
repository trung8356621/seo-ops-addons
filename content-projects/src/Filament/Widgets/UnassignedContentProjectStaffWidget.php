<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets;

use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Filament\Widgets\Widget;

/**
 * @deprecated Card static retired with one-project-per-user list UI.
 * Giữ class để tránh break discovery/cache cũ — canView = false.
 */
final class UnassignedContentProjectStaffWidget extends Widget
{
    protected static string $view = 'seo-content-ai::filament.widgets.unassigned-content-project-staff';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public bool $showAll = false;

    public static function canView(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ContentProjectStaffAvailabilityService::class);
        $payload = $service->widgetPayload(null);

        return [
            'total' => $payload['total'],
            'staff' => $payload['staff'],
            'showAll' => $this->showAll,
            'limit' => ContentProjectStaffAvailabilityService::WIDGET_LIMIT,
            'createUrl' => $service->createProjectUrl(0),
            'monthDisplay' => $payload['month_display'],
        ];
    }

    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;
    }
}
