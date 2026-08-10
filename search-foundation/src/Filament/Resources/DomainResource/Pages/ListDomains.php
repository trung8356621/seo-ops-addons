<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\Seo\Filament\Pages\DomainGlobalCtaSettings;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    public function mount(): void
    {
        parent::mount();

        app(SeoMainDomainService::class)->deduplicatePrimarySitesForVisibleOwners();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('global_cta_settings')
                ->label(__('seo-content-ai::filament.domain.global_cta_settings'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(DomainGlobalCtaSettings::getUrl()),
            Actions\CreateAction::make()
                ->label('Add domain')
                ->icon('heroicon-o-plus'),
        ];
    }
}
