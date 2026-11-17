<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

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
        // Legacy global CTA Settings UI stays retired. CTA Manager V2 is per-Site
        // at domains/{id}/cta-settings (table action "Quản lý CTA"). CTA Detector stays in Article Editor.
        return [
            Actions\CreateAction::make()
                ->label(__('Add domain'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
