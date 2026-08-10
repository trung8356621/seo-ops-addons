<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Filament\Resources\Pages\Page;

final class LegacyGscEditRedirect extends Page
{
    protected static string $resource = AiConnectionResource::class;

    protected static ?string $slug = 'gsc/edit';

    protected static string $view = 'seo-content-ai::filament.resources.ai-connection-resource.pages.legacy-gsc-edit-redirect';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $connection = app(GoogleSearchConsoleConnectionService::class)->resolveForUser((int) auth()->id());

        if ($connection === null) {
            $this->redirect(AiConnectionResource::getUrl('index'));

            return;
        }

        $this->redirect(AiConnectionResource::gscEditUrl((int) $connection->id));
    }
}
