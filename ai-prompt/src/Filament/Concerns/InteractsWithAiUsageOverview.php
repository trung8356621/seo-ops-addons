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
            ->title('Đã làm mới dữ liệu dashboard')
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
            ->title('Đã kiểm tra số dư nhà cung cấp')
            ->body("Thành công: {$successful}. Thất bại: {$failed}.");

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
            Notification::make()->title('Không tìm thấy connection')->danger()->send();

            return;
        }

        $service = app(AiProviderWalletService::class);
        $result = $service->checkConnectionBalance($connection);

        if (! $result->supported) {
            Notification::make()
                ->title("Provider {$connection->provider} không hỗ trợ balance API")
                ->warning()
                ->send();

            return;
        }

        if ($result->success) {
            Notification::make()
                ->title("Đã cập nhật số dư {$connection->name}")
                ->body("Số dư hiện tại: {$result->currency} ".number_format((float) $result->balance, 2))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title("Không thể cập nhật số dư {$connection->name}")
                ->body($result->error ?: 'Lỗi không xác định khi kết nối API.')
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
                ->title("Đã lưu ngưỡng cảnh báo cho {$connection->name}")
                ->body('Ngưỡng mới: $'.number_format($this->editingThresholdValue, 2))
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
