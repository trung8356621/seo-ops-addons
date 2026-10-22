<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\UsesScriptRegistryUi;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRemoveService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class SocialAutomationScriptsPage extends Page
{
    use Access;
    use UsesScriptRegistryUi;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $slug = 'scripts';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'social-automation::filament.pages.scripts';

    public ?string $selectedKey = null;

    public ?string $confirmRemoveKey = null;

    public ?int $playingMediaId = null;

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
        $this->confirmRemoveKey = null;
        $this->playingMediaId = null;
    }

    public function clearSelection(): void
    {
        $this->selectedKey = null;
    }

    public function openRemoveConfirm(string $packageKey): void
    {
        $this->confirmRemoveKey = $packageKey;
    }

    public function cancelRemoveConfirm(): void
    {
        $this->confirmRemoveKey = null;
    }

    public function openVideoPlayer(int $mediaId): void
    {
        $this->playingMediaId = $mediaId > 0 ? $mediaId : null;
    }

    public function closeVideoPlayer(): void
    {
        $this->playingMediaId = null;
    }

    public function removeScript(string $packageKey): void
    {
        app(SocialAutomationAccess::class)->assertCanMutate();

        $userId = Auth::id();
        $result = app(ScriptRemoveService::class)->remove(
            $packageKey,
            is_numeric($userId) ? (int) $userId : null,
        );

        if (! ($result['ok'] ?? false)) {
            $deps = is_array($result['dependencies'] ?? null) ? $result['dependencies'] : [];
            $depLines = [];
            foreach ($deps as $dep) {
                if (! is_array($dep)) {
                    continue;
                }
                $depLines[] = sprintf(
                    '%s · %s (%s)',
                    (string) ($dep['kind'] ?? 'pkg'),
                    (string) ($dep['name'] ?? ''),
                    (string) ($dep['package_key'] ?? ''),
                );
            }
            $body = $depLines !== []
                ? implode("\n", $depLines)
                : implode(', ', $result['errors'] ?? ['blocked']);

            Notification::make()
                ->title(__('social-automation::filament.scripts.remove_blocked'))
                ->body($body)
                ->danger()
                ->send();

            $this->confirmRemoveKey = null;

            return;
        }

        if ($this->selectedKey === $packageKey) {
            $this->selectedKey = null;
        }
        $this->confirmRemoveKey = null;

        Notification::make()
            ->title(__('social-automation::filament.scripts.remove_success'))
            ->success()
            ->send();
    }

    /** @return array<string, mixed>|null */
    public function selectedDetail(): ?array
    {
        if ($this->selectedKey === null || $this->selectedKey === '') {
            return null;
        }

        return $this->registryUi()->scriptDetail($this->selectedKey);
    }

    public function mediaUrl(int $mediaId): string
    {
        return url('/social-automation/script-media/'.$mediaId);
    }
}
