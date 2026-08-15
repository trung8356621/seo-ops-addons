<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Widgets;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusFormatter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusParser;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\Seo\Livewire\Concerns\RefreshesOnDomainContextChanged;
use App\Models\SeoDatabaseConnection;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Publishing Queue Health — list header.
 */
final class ContentProjectQueueHealthWidget extends StatsOverviewWidget
{
    use RefreshesOnDomainContextChanged;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return SeoAccessControl::canMutateContentProjects()
            || SeoAccessControl::canViewProjectArchives();
    }

    protected function getStats(): array
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        $siteIds = $globalSiteId !== null
            ? [$globalSiteId]
            : SeoAccessControl::accessibleSiteIds();
        $connectionId = null;
        $current = SeoConnectionContext::current();
        if ($current instanceof SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }
        $health = app(ContentProjectQueueHealthService::class)->snapshot(
            $siteIds !== [] ? $siteIds : null,
            $connectionId,
        );

        $formatter = new OperationalStatusFormatter();
        $parsedSuccess = OperationalStatusParser::parse($health['last_success'] ?? null);
        $includeDomain = is_int($parsedSuccess['connection_id'])
            && $parsedSuccess['connection_id'] > 0
            && $connectionId !== $parsedSuccess['connection_id'];

        $worker = $formatter->formatWorker($health['last_worker_run'] ?? null);
        $success = $formatter->formatSuccess($health['last_success'] ?? null, $includeDomain);
        $failure = $formatter->formatFailure($health['last_failure'] ?? null);

        return [
            Stat::make(__('seo-content-ai::filament.projects.health_waiting'), (string) $health['waiting'])
                ->description($this->description('health_last_worker', $worker))
                ->extraAttributes($this->tooltipAttrs($worker))
                ->color('primary'),
            Stat::make(__('seo-content-ai::filament.projects.health_processing'), (string) $health['processing'])
                ->description($this->description('health_last_success', $success))
                ->extraAttributes($this->tooltipAttrs($success))
                ->color('success'),
            Stat::make(__('seo-content-ai::filament.projects.health_failed'), (string) $health['failed'])
                ->description($this->description('health_last_failure', $failure))
                ->extraAttributes($this->tooltipAttrs($failure))
                ->color('danger'),
            Stat::make(__('seo-content-ai::filament.projects.health_retrying'), (string) $health['retrying'])
                ->color('warning'),
        ];
    }

    /**
     * @param  array{text: string, tooltip: string|null, empty: bool, raw: string|null}  $display
     */
    private function description(string $key, array $display): string
    {
        if ($display['empty']) {
            return $display['text'];
        }

        return __('seo-content-ai::filament.projects.'.$key, ['at' => $display['text']]);
    }

    /**
     * @param  array{text: string, tooltip: string|null, empty: bool, raw: string|null}  $display
     * @return array<string, string>
     */
    private function tooltipAttrs(array $display): array
    {
        if (! is_string($display['tooltip']) || $display['tooltip'] === '') {
            return [];
        }

        return ['title' => $display['tooltip']];
    }
}
