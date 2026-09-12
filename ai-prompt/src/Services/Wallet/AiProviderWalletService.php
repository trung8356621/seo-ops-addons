<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Wallet;

use App\Models\ApiConnection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AiProviderWalletService
{
    public const STATUS_NORMAL = 'normal';
    public const STATUS_LOW_BALANCE = 'low_balance';
    public const STATUS_CHECK_FAILED = 'check_failed';
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var list<ProviderBalanceFetcherInterface> */
    private array $fetchers;

    public function __construct(?array $fetchers = null)
    {
        $this->fetchers = $fetchers ?? [
            new DeepSeekBalanceFetcher(),
            new OpenRouterBalanceFetcher(),
        ];
    }

    public function supportsBalance(string $provider): bool
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($provider)) {
                return true;
            }
        }

        return false;
    }

    public function getFetcher(string $provider): ?ProviderBalanceFetcherInterface
    {
        foreach ($this->fetchers as $fetcher) {
            if ($fetcher->supports($provider)) {
                return $fetcher;
            }
        }

        return null;
    }

    public function checkConnectionBalance(ApiConnection $connection): ProviderBalanceResult
    {
        $provider = (string) ($connection->provider ?? '');
        $fetcher = $this->getFetcher($provider);

        if ($fetcher === null) {
            $connection->forceFill([
                'balance_status' => 'unsupported',
                'balance_checked_at' => now(),
                'balance_error' => null,
            ])->save();

            return ProviderBalanceResult::unsupported();
        }

        $result = $fetcher->fetch($connection);

        $threshold = (float) ($connection->balance_warning_threshold ?? 5.0);

        if ($result->success && $result->balance !== null) {
            $status = ($result->balance <= $threshold) ? 'low_balance' : 'normal';
            $connection->forceFill([
                'balance' => $result->balance,
                'currency' => $result->currency,
                'balance_status' => $status,
                'balance_checked_at' => now(),
                'balance_error' => null,
            ])->save();
        } else {
            // Thất bại: Không ghi balance thành 0, giữ lại balance thành công gần nhất nếu có
            $connection->forceFill([
                'balance_status' => 'check_failed',
                'balance_checked_at' => now(),
                'balance_error' => $result->error,
            ])->save();
        }

        return $result;
    }

    /**
     * @return array<int, ProviderBalanceResult>
     */
    public function checkAllActiveConnections(): array
    {
        $connections = ApiConnection::query()
            ->where('status', 'active')
            ->get();

        $results = [];
        foreach ($connections as $connection) {
            $results[(int) $connection->id] = $this->checkConnectionBalance($connection);
        }

        return $results;
    }

    public function updateWarningThreshold(ApiConnection $connection, float $threshold): void
    {
        $threshold = max(0.0, round($threshold, 4));
        $connection->balance_warning_threshold = $threshold;

        // Nếu connection đang có balance và status không phải check_failed/unsupported, re-evaluate
        if ($connection->balance !== null && in_array((string) $connection->balance_status, ['normal', 'low_balance'], true)) {
            $connection->balance_status = ((float) $connection->balance <= $threshold) ? 'low_balance' : 'normal';
        }

        $connection->save();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getWalletCards(): Collection
    {
        $connections = ApiConnection::query()
            ->where('status', 'active')
            ->orderBy('provider')
            ->orderBy('name')
            ->get();

        return $connections->map(function (ApiConnection $conn): array {
            $provider = (string) ($conn->provider ?? '');
            $supported = $this->supportsBalance($provider);
            $status = (string) ($conn->balance_status ?? 'unknown');

            if (! $supported) {
                $status = 'unsupported';
            }

            $statusLabel = match ($status) {
                'normal' => 'Bình thường',
                'low_balance' => 'Sắp hết tiền',
                'check_failed' => 'Không cập nhật được',
                'unsupported' => 'Không hỗ trợ',
                default => 'Chưa kiểm tra',
            };

            $statusBadgeClass = match ($status) {
                'normal' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400',
                'low_balance' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400',
                'check_failed' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950/40 dark:text-red-400',
                'unsupported' => 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800/40 dark:text-gray-400',
                default => 'bg-gray-50 text-gray-500 ring-gray-400/20 dark:bg-gray-800/40 dark:text-gray-400',
            };

            $checkedAt = $conn->balance_checked_at
                ? Carbon::parse($conn->balance_checked_at)->diffForHumans()
                : null;

            return [
                'id' => (int) $conn->id,
                'name' => (string) $conn->name,
                'provider' => $provider,
                'provider_label' => ucfirst($provider),
                'supported' => $supported,
                'balance' => $conn->balance !== null ? (float) $conn->balance : null,
                'currency' => (string) ($conn->currency ?? 'USD'),
                'threshold' => (float) ($conn->balance_warning_threshold ?? 5.0),
                'status' => $status,
                'status_label' => $statusLabel,
                'status_badge_class' => $statusBadgeClass,
                'checked_at' => $checkedAt,
                'checked_at_exact' => $conn->balance_checked_at ? Carbon::parse($conn->balance_checked_at)->toIso8601String() : null,
                'error' => (string) ($conn->balance_error ?? ''),
            ];
        });
    }
}
