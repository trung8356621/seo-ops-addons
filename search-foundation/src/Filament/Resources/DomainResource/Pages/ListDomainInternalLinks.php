<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use App\Models\Site;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;

class ListDomainInternalLinks extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.list-domain-internal-links';

    public string $activeTab = 'links';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $tab = request()->query('tab', 'links');
        if (is_string($tab) && in_array($tab, ['links', 'keywords'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return __('Internal link') . ': ' . $site->domain;
    }

    public function getTabUrl(string $tab): string
    {
        return static::getUrl(['record' => $this->getRecord()]) . '?tab=' . urlencode($tab);
    }

    public function getOverviewUrl(): string
    {
        return DomainResource::getUrl('general', ['record' => $this->getRecord()]);
    }

    public function getListPaginator(): LengthAwarePaginator
    {
        $siteId = (int) $this->getRecord()->getKey();
        $service = app(DomainOverviewService::class);

        return $this->activeTab === 'keywords'
            ? $service->paginateKeywords($siteId)
            : $service->paginateLinks($siteId);
    }

    public function getArticlesFilterUrlForLink(object $row): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForLink(
            (int) $this->getRecord()->getKey(),
            (string) $row->url,
            (string) $row->type,
        );
    }

    public function getArticlesFilterUrlForKeyword(object $row): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForKeyword(
            (int) $this->getRecord()->getKey(),
            (int) $row->id,
        );
    }
}
