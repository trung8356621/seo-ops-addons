<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPrompts extends ListRecords
{
    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add prompt'),
            Actions\Action::make('ai_settings')
                ->label('AI settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn (): string => AiConnectionResource::getUrl('index')),
        ];
    }
}
