<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Filament\Facades\Filament;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Throwable;

final class ContentProjectAgentLink
{
    public static function url(int $projectId): ?string
    {
        if ($projectId <= 0) {
            return null;
        }

        try {
            $panel = Filament::getCurrentPanel()?->getId();
            if (! in_array($panel, ['seo', 'seo-main'], true)) {
                $panel = 'seo-main';
            }

            return SeoProjectResource::getUrl('view', ['record' => $projectId], panel: $panel);
        } catch (Throwable) {
            return null;
        }
    }
}
