<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Concerns;

use App\Models\ApiConnection;
use Filament\Notifications\Notification;
use Omnichannel\Addons\AiPrompt\Services\AiTokenUsageAnalyticsService;
use Omnichannel\Addons\AiPrompt\Services\Wallet\AiProviderWalletService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Shared Livewire state/actions for Admin Dashboard Usage overview.
 * Domain SSOT remains AiTokenUsageAnalyticsService + AiProviderWalletService.
 */
trait InteractsWithAiUsageOverview
{
    public string $usageDateRange = '30d';

    public string $usageAddonFilter = 'all';

    public ?int $editingThresholdConnectionId = null;

    public float $editingThresholdValue = 5.0;

    public function refreshUsageOverview(): void
    {
        $this->assertUsageManager();

        Notification::make()
            ->title(__('seo-content-ai::filament.ai_usage.refresh_success'))
            ->success()
            ->send();
    }

    public function refreshProviderWallets(): void
    {
        $this->assertUsageManager();

        $results = app(AiProviderWalletService::class)->checkAllActiveConnections();
        $successful = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result->success) {
                $successful++;
            } elseif ($result->supported) {
                $failed++;
            }
        }

        $notification = Notification::make()
            ->title(__('seo-content-ai::filament.ai_usage.wallets_checked'))
            ->body(__('seo-content-ai::filament.ai_usage.wallets_checked_body', [
                'successful' => $successful,
                'failed' => $failed,
            ]));

        if ($failed > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public function refreshConnectionBalance(int $connectionId): void
    {
        $this->assertUsageManager();
        $connection = ApiConnection::query()->find($connectionId);
        if (! $connection instanceof ApiConnection) {
            Notification::make()->title(__('seo-content-ai::filament.ai_usage.connection_not_found'))->danger()->send();

            return;
        }

        $service = app(AiProviderWalletService::class);
        $result = $service->checkConnectionBalance($connection);

        if (! $result->supported) {
            Notification::make()
                ->title(__('seo-content-ai::filament.ai_usage.balance_unsupported', ['provider' => $connection->provider]))
                ->warning()
                ->send();

            return;
        }

        if ($result->success) {
            Notification::make()
                ->title(__('seo-content-ai::filament.ai_usage.balance_updated', ['name' => $connection->name]))
                ->body(__('seo-content-ai::filament.ai_usage.balance_updated_body', [
                    'currency' => $result->currency,
                    'balance' => number_format((float) $result->balance, 2),
                ]))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.ai_usage.balance_update_failed', ['name' => $connection->name]))
                ->body($result->error ?: __('seo-content-ai::filament.ai_usage.unknown_api_error'))
                ->danger()
                ->send();
        }
    }

    public function openEditThresholdModal(int $connectionId): void
    {
        $connection = ApiConnection::query()->find($connectionId);
        if (! $connection instanceof ApiConnection) {
            return;
        }

        $this->editingThresholdConnectionId = $connectionId;
        $this->editingThresholdValue = (float) ($connection->balance_warning_threshold ?? 5.0);
    }

    public function closeEditThresholdModal(): void
    {
        $this->editingThresholdConnectionId = null;
    }

    public function saveThreshold(): void
    {
        $this->assertUsageManager();
        if ($this->editingThresholdConnectionId === null) {
            return;
        }

        $connection = ApiConnection::query()->find($this->editingThresholdConnectionId);
        if ($connection instanceof ApiConnection) {
            app(AiProviderWalletService::class)->updateWarningThreshold($connection, $this->editingThresholdValue);

            Notification::make()
                ->title(__('seo-content-ai::filament.ai_usage.threshold_saved', ['name' => $connection->name]))
                ->body(__('seo-content-ai::filament.ai_usage.threshold_saved_body', [
                    'threshold' => number_format($this->editingThresholdValue, 2),
                ]))
                ->success()
                ->send();
        }

        $this->editingThresholdConnectionId = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function walletCards(): array
    {
        return app(AiProviderWalletService::class)->getWalletCards()->all();
    }

    /**
     * @return array{seo: array{calls: int, tokens: int}, seeding: array{calls: int, tokens: int}, total_tokens: int, total_calls: int}
     */
    public function tokenSummary(): array
    {
        return app(AiTokenUsageAnalyticsService::class)->getSummary($this->usageDateRange, $this->usageAddonFilter);
    }

    /**
     * @return array{labels: list<string>, dates: list<string>, seo_series: list<int>, seeding_series: list<int>, total_series: list<int>, max_tokens: int}
     */
    public function tokenDailyTrend(): array
    {
        return app(AiTokenUsageAnalyticsService::class)->getDailyTrend($this->usageDateRange);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tokenTableData(): array
    {
        return app(AiTokenUsageAnalyticsService::class)->getTableData($this->usageDateRange, $this->usageAddonFilter);
    }

    protected function assertUsageManager(): void
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return;
        }

        if (class_exists(SeoAccessControl::class) && SeoAccessControl::canAccessManagerFeatures()) {
            return;
        }

        abort(403);
    }
}
