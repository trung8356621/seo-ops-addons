<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Settings;

use App\Core\Settings\SettingsSection;
use App\Core\Settings\SettingsSectionContributor;
use Omnichannel\Addons\AgentRuntime\Filament\Pages\SemanticRoutingPage;

final class SemanticRoutingSettingsSectionContributor implements SettingsSectionContributor
{
    public function ownerSlug(): string
    {
        return 'agent-runtime';
    }

    public function sections(): array
    {
        return [
            new SettingsSection(
                id: 'semantic-routing',
                label: 'Semantic Routing',
                icon: 'heroicon-o-queue-list',
                url: $this->url(),
                owner: 'agent-runtime',
                sort: 35,
                coreShared: false,
            ),
        ];
    }

    private function url(): string
    {
        try {
            return SemanticRoutingPage::getUrl(panel: 'admin');
        } catch (\Throwable) {
            try {
                return url('/admin/settings/semantic-routing');
            } catch (\Throwable) {
                return '/admin/settings/semantic-routing';
            }
        }
    }
}
