<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Services\ApiConnectionsListService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
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

    public function getTableRecords(): EloquentCollection|Paginator|CursorPaginator
    {
        $records = $this->connectionsList->recordsForUser((int) auth()->id());

        if ($this->connectionTypeFilter !== 'all') {
            $records = $records
                ->filter(fn (Model $record): bool => (string) $record->getAttribute('connection_type') === $this->connectionTypeFilter)
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
            Actions\CreateAction::make()
                ->label(__('seo-content-ai::filament.api_connections.add_connection')),
        ];
    }
}
