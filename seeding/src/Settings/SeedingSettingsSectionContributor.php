<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Settings;

use App\Core\Settings\SettingsSection;
use App\Core\Settings\SettingsSectionContributor;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingServiceStatusPage;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

final class SeedingSettingsSectionContributor implements SettingsSectionContributor
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
    ) {}

    public function ownerSlug(): string
    {
        return SeedingServiceResolver::SLUG;
    }

    public function sections(): array
    {
        if (! $this->resolver->isActive()) {
            return [];
        }

        return [
            new SettingsSection(
                id: 'seeding-service',
                label: 'Seeding',
                icon: 'heroicon-o-chat-bubble-left-right',
                url: $this->statusUrl(),
                owner: SeedingServiceResolver::SLUG,
                sort: 85,
                coreShared: false,
            ),
        ];
    }

    private function statusUrl(): string
    {
        try {
            if (class_exists(\App\Filament\Resources\SeedingDatabaseConnectionResource::class)) {
                return \App\Filament\Resources\SeedingDatabaseConnectionResource::getUrl('index');
            }

            return SeedingServiceStatusPage::getUrl(panel: 'seeding');
        } catch (\Throwable) {
            return url('/admin/seeding-database-connections');
        }
    }
}
