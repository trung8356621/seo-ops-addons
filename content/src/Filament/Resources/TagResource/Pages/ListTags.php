<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\TagResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\TagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTags extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('seo-content-ai::filament.tag.create')),
        ];
    }
}
