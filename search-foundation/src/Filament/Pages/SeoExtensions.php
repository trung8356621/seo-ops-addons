<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Pages;

use Omnichannel\Addons\Agent\Extension\ExtensionHealthService;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Runtime Info — read-only UI over Extension Registry health.
 * Sidebar: hidden; embedded as Operation Center → Runtime Info tab.
 */
final class SeoExtensions extends Page
{
    protected static ?string $slug = 'extensions';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 85;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.seo-extensions';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $actionLoading = false;

    /**
     * Friendly runtime labels — UI only; registry ids unchanged.
     *
     * @var array<string, string>
     */
    private const RUNTIME_LABELS = [
        'ai-providers' => 'AI Runtime',
        'content-pipelines' => 'Pipeline Runtime',
        'local-seo' => 'SEO Runtime',
        'wordpress' => 'Publishing Runtime',
    ];

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures()
            || SeoAccessControl::canAccessContentOperations();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.extensions.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.extensions.title');
    }

    public function mount(
        ExtensionRegistry $extensionRegistry,
        ExtensionStateStore $stateStore,
    ): void {
        $this->rows = self::buildRuntimeSnapshot($extensionRegistry, $stateStore);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function buildRuntimeSnapshot(
        ?ExtensionRegistry $extensionRegistry = null,
        ?ExtensionStateStore $stateStore = null,
    ): array {
        $extensionRegistry ??= app(ExtensionRegistry::class);
        $stateStore ??= app(ExtensionStateStore::class);

        $rows = [];

        foreach ($extensionRegistry->installed() as $definition) {
            $id = $definition->manifest->id;
            $enabled = $stateStore->isEnabled($id);
            $status = $stateStore->getStatus($id);
            $health = $stateStore->getHealthPayload($id);

            if (! $enabled) {
                $status = 'disabled';
            } elseif (is_array($health) && isset($health['status'])) {
                $status = (string) $health['status'];
            }

            $drivers = [];
            if (is_array($health) && isset($health['drivers']) && is_array($health['drivers'])) {
                foreach ($health['drivers'] as $driver) {
                    if (! is_array($driver)) {
                        continue;
                    }
                    $driverId = (string) ($driver['id'] ?? '');
                    if ($driverId !== '') {
                        $drivers[] = $driverId;
                    }
                }
            }

            if ($drivers === []) {
                $drivers = $definition->manifest->providers;
            }

            $capabilities = $definition->manifest->capabilities;
            $capabilitySummary = $capabilities !== []
                ? implode(', ', $capabilities)
                : (string) ($health['message'] ?? implode(', ', $definition->manifest->providers));

            $rows[] = [
                'id' => $id,
                'name' => self::RUNTIME_LABELS[$id] ?? $definition->manifest->name,
                'version' => $definition->manifest->version,
                'sdk' => $definition->manifest->sdk,
                'status' => $status,
                'enabled' => $enabled,
                'providers' => $definition->manifest->providers,
                'driver' => $drivers !== [] ? implode(', ', $drivers) : '—',
                'last_check' => is_array($health)
                    ? (string) ($health['checked_at'] ?? $health['updated_at'] ?? '—')
                    : '—',
                'capability_summary' => $capabilitySummary !== '' ? $capabilitySummary : '—',
            ];
        }

        return $rows;
    }

    public function refreshHealth(
        ExtensionHealthService $healthService,
        ExtensionRegistry $extensionRegistry,
        ExtensionStateStore $stateStore,
    ): void {
        $this->actionLoading = true;

        try {
            $healthService->runAll();
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_refreshed'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->rows = self::buildRuntimeSnapshot($extensionRegistry, $stateStore);
        $this->actionLoading = false;
    }
}
