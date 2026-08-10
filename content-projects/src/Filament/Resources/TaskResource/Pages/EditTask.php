<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;

use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Filament\Actions;

class EditTask extends SeoEditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_builder')
                ->label('Open workflow builder')
                ->icon('heroicon-o-squares-2x2')
                ->color('info')
                ->url(fn (): string => TaskResource::getUrl('builder', ['record' => $this->record])),
            Actions\DeleteAction::make(),
        ];
    }
}
