<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoCreateRecord;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

class CreateKeyword extends SeoCreateRecord
{
    protected static string $resource = KeywordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['site_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $siteId = (int) ($this->form->getState()['site_id'] ?? SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0 || ! $this->record) {
            return;
        }

        app(KeywordPersistenceService::class)->upsertMeta($this->record, $siteId);
    }

    protected function getRedirectUrl(): string
    {
        return KeywordResource::getUrl('index');
    }
}
