<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Filament\Resources\Pages\Page;

class RedirectDomainInfoToEdit extends Page
{
    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.redirect-domain-info';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->redirect(DomainResource::getUrl('edit', ['record' => $record]), navigate: true);
    }
}
