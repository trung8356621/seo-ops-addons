<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\TagResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\TagResource;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function handleRecordCreation(array $data): Tag
    {
        return TagResource::createTagFromFormData($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('seo-content-ai::filament.tag.created'))
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return TagResource::getUrl('index');
    }
}
