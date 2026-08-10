<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;

use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoCreateRecord;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;

class CreateTask extends SeoCreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['flow_data'] = $data['flow_data'] ?? [
            'nodes' => [],
            'edges' => [],
        ];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('builder', ['record' => $this->record]);
    }
}
