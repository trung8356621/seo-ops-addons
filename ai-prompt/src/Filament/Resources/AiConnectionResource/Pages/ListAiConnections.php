<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Services\ApiConnectionsListService;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionActive;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionFreeOnly;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use App\Models\ApiConnection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class ListAiConnections extends ListRecords
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-list';

    #[Url(as: 'type')]
    public string $connectionTypeFilter = 'all';

    private ApiConnectionsListService $connectionsList;

    private SeoProviderRegistry $providerRegistry;

    private SeoProviderCapabilityResolver $capabilityResolver;

    public function boot(
        ApiConnectionsListService $connectionsList,
        SeoProviderRegistry $providerRegistry,
        SeoProviderCapabilityResolver $capabilityResolver,
    ): void {
        $this->connectionsList = $connectionsList;
        $this->providerRegistry = $providerRegistry;
        $this->capabilityResolver = $capabilityResolver;
        $this->notifyOAuthFlash();
    }

    private function notifyOAuthFlash(): void
    {
        $success = session()->pull('gsc_oauth_success');
        if (is_string($success) && $success !== '') {
            Notification::make()
                ->title($success)
                ->success()
                ->send();
        }

        $error = session()->pull('gsc_oauth_error');
        if (is_string($error) && $error !== '') {
            Notification::make()
                ->title($error)
                ->danger()
                ->send();
        }
    }

    public function getTitle(): string
    {
        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilityMatrixRows(): array
    {
        return $this->providerRegistry->capabilityMatrixRows((int) auth()->id(), $this->capabilityResolver);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function capabilityMatrixColumns(): array
    {
        return array_map(
            static fn ($capability): array => [
                'key' => $capability->value,
                'label' => $capability->matrixLabel(),
            ],
            $this->providerRegistry->matrixCapabilityColumns(),
        );
    }

    public function setConnectionTypeFilter(string $filter): void
    {
        if (! in_array($filter, ['all', ApiConnectionType::Ai->value, ApiConnectionType::Seo->value], true)) {
            return;
        }

        $this->connectionTypeFilter = $filter;
    }

    public function toggleConnectionActive(string $recordKey): void
    {
        $connection = $this->resolveAiConnection($recordKey);
        if ($connection === null) {
            return;
        }

        $nextActive = (string) $connection->status === 'inactive';
        try {
            $updated = app(SetAiConnectionActive::class)->handle($connection, $nextActive);
        } catch (\Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($nextActive
                ? __('seo-content-ai::filament.api_connections.toast_activated', ['name' => $updated->name])
                : __('seo-content-ai::filament.api_connections.toast_deactivated', ['name' => $updated->name]))
            ->success()
            ->send();

        $this->resetTable();
    }

    public function toggleConnectionFreeOnly(string $recordKey): void
    {
        $connection = $this->resolveAiConnection($recordKey);
        if ($connection === null) {
            return;
        }

        $nextFreeOnly = ! (bool) ($connection->paid_locked ?? false);
        try {
            $updated = app(SetAiConnectionFreeOnly::class)->handle($connection, $nextFreeOnly);
        } catch (\Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($nextFreeOnly
                ? __('seo-content-ai::filament.api_connections.toast_free_only_on', ['name' => $updated->name])
                : __('seo-content-ai::filament.api_connections.toast_free_only_off', ['name' => $updated->name]))
            ->success()
            ->send();

        $this->resetTable();
    }

    private function resolveAiConnection(string $recordKey): ?ApiConnection
    {
        if (! ctype_digit($recordKey) && ! is_numeric($recordKey)) {
            return null;
        }

        $connectionId = (int) $recordKey;
        if ($connectionId <= 0) {
            return null;
        }

        $viewerId = (int) (\Filament\Facades\Filament::auth()->id() ?? auth()->id() ?? 0);
        $connection = app(\Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService::class)
            ->queryForViewer($viewerId)
            ->whereKey($connectionId)
            ->first();

        if (! $connection instanceof ApiConnection) {
            return null;
        }

        if (! ApiConnectionProviders::isAi((string) $connection->provider)) {
            return null;
        }

        return $connection;
    }

    public function getTableRecords(): EloquentCollection|Paginator|CursorPaginator
    {
        // Admin Settings must use Filament panel auth (same web guard), never a missing site/tenant id.
        $userId = (int) (\Filament\Facades\Filament::auth()->id() ?? auth()->id() ?? 0);
        $records = $this->connectionsList->recordsForUser($userId);

        if ($this->connectionTypeFilter !== 'all') {
            $records = $records
                ->filter(function (Model $record): bool {
                    $type = (string) $record->getAttribute('connection_type');
                    if ($type === '' && $record instanceof \App\Models\ApiConnection) {
                        $type = ApiConnectionProviders::connectionType((string) $record->getAttribute('provider'))->value;
                    }

                    return $type === $this->connectionTypeFilter;
                })
                ->values();
        }

        $search = trim((string) $this->getTableSearch());
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $records = $records
                ->filter(function (Model $record) use ($needle): bool {
                    $name = mb_strtolower((string) $record->getAttribute('name'));
                    $provider = mb_strtolower(
                        ApiConnectionProviders::label((string) $record->getAttribute('provider')),
                    );
                    $type = mb_strtolower((string) $record->getAttribute('connection_type'));

                    return str_contains($name, $needle)
                        || str_contains($provider, $needle)
                        || str_contains($type, $needle);
                })
                ->values();
        }

        $sortColumn = $this->getTableSortColumn();
        if (in_array($sortColumn, ['name', 'provider', 'connection_type', 'status'], true)) {
            $descending = $this->getTableSortDirection() === 'desc';
            $records = $records
                ->sortBy(
                    fn (Model $record): string => match ($sortColumn) {
                        'provider' => ApiConnectionProviders::label((string) $record->getAttribute('provider')),
                        'connection_type' => (string) $record->getAttribute('connection_type'),
                        'status' => (string) $record->getAttribute('status'),
                        default => (string) $record->getAttribute('name'),
                    },
                    SORT_NATURAL | SORT_FLAG_CASE,
                    $descending,
                )
                ->values();
        }

        // Keep Filament count helpers aligned with the custom inventory (not raw Eloquent query).
        $this->cachedTableRecords = $records;

        return $records;
    }

    public function getTableRecord(?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        $record = $this->getTableRecords()->first(
            fn (Model $record): bool => (string) $record->getKey() === $key,
        );

        return $record ?? parent::getTableRecord($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_all_models')
                ->label(__('seo-content-ai::filament.api_connections.sync_all_models'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $result = app(\Omnichannel\Addons\AiPrompt\Services\SyncAllAiConnectionModelsService::class)
                        ->run((int) auth()->id());
                    $body = implode("\n", array_slice($result['summary_lines'], 0, 12));
                    $notification = Notification::make()
                        ->title(__('seo-content-ai::filament.api_connections.sync_all_done_title'))
                        ->body($body);
                    $result['failed'] === 0
                        ? $notification->success()->send()
                        : $notification->warning()->send();
                }),
            Actions\CreateAction::make()
                ->label(__('seo-content-ai::filament.api_connections.add_connection')),
        ];
    }
}
