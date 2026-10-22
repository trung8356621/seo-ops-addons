<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\UsesScriptRegistryUi;

final class SocialAutomationScriptsPage extends Page
{
    use Access;
    use UsesScriptRegistryUi;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $slug = 'scripts';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'social-automation::filament.pages.scripts';

    public ?string $selectedKey = null;

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.scripts.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.scripts.title');
    }

    /** @return list<array<string, mixed>> */
    public function scripts(): array
    {
        return $this->registryUi()->listScriptsForUi();
    }

    public function selectScript(string $packageKey): void
    {
        $this->selectedKey = $packageKey;
    }

    public function clearSelection(): void
    {
        $this->selectedKey = null;
    }

    /** @return array<string, mixed>|null */
    public function selectedDetail(): ?array
    {
        if ($this->selectedKey === null || $this->selectedKey === '') {
            return null;
        }

        return $this->registryUi()->scriptDetail($this->selectedKey);
    }
}
