<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\Content\DataTransfer\SerpProviderUsage;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpRankProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Illuminate\Support\Str;

final class SeoSerpProviderConnectionService
{
    public function __construct(
        private readonly SerpRankProviderRegistry $registry,
    ) {}

    public function resolveForUser(int $userId, string $provider): ?SeoSerpProviderConnection
    {
        if (! SerpProviderKeys::isValid($provider)) {
            return null;
        }

        return SeoSerpProviderConnection::query()
            ->where('provider', $provider)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderByDesc('is_global')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<SeoSerpProviderConnection>
     */
    public function configuredForUser(int $userId): array
    {
        $connections = [];

        foreach (SerpProviderKeys::all() as $provider) {
            $connection = $this->resolveForUser($userId, $provider);
            if ($connection !== null && $connection->isConfigured()) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @return list<SeoSerpProviderConnection>
     */
    public function activeForUser(int $userId): array
    {
        return array_values(array_filter(
            $this->configuredForUser($userId),
            static fn (SeoSerpProviderConnection $connection): bool => $connection->isActive(),
        ));
    }

    /**
     * @return list<array{key: string, label: string, configured: bool, active: bool, status: string, status_label: string}>
     */
    public function tabSourcesForUser(int $userId): array
    {
        $tabs = [];

        foreach (SerpProviderKeys::all() as $provider) {
            $connection = $this->resolveForUser($userId, $provider);
            if ($connection === null || ! $connection->isConfigured()) {
                continue;
            }

            $status = (string) ($connection->status ?? 'not_configured');
            $tabs[] = [
                'key' => $provider,
                'label' => SerpProviderKeys::label($provider),
                'configured' => true,
                'active' => $connection->isActive(),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'connection_id' => (int) $connection->id,
            ];
        }

        return $tabs;
    }

    public function isConfiguredForUser(int $userId, string $provider): bool
    {
        $connection = $this->resolveForUser($userId, $provider);

        return $connection !== null && $connection->isConfigured();
    }

    public function isActiveForUser(int $userId, string $provider): bool
    {
        $connection = $this->resolveForUser($userId, $provider);

        return $connection !== null && $connection->isActive();
    }

    /**
     * @return array{status: string, label: string, configured: bool, active: bool, last_checked_at: string|null, last_rank_check_at: string|null, usage_label: string|null, connection_id: int|null}
     */
    public function statusForUser(int $userId, string $provider): array
    {
        $connection = $this->resolveForUser($userId, $provider);
        if ($connection === null || ! $connection->isConfigured()) {
            return [
                'status' => 'not_configured',
                'label' => __('seo-content-ai::filament.api_connections.not_configured'),
                'configured' => false,
                'active' => false,
                'last_checked_at' => null,
                'last_rank_check_at' => null,
                'usage_label' => null,
                'connection_id' => null,
            ];
        }

        $usage = $this->resolveUsageLabel($connection);

        return [
            'status' => (string) $connection->status,
            'label' => $this->statusLabel((string) $connection->status),
            'configured' => true,
            'active' => $connection->isActive(),
            'last_checked_at' => $connection->last_checked_at?->toDateTimeString(),
            'last_rank_check_at' => $connection->last_rank_check_at?->toDateTimeString(),
            'usage_label' => $usage,
            'connection_id' => (int) $connection->id,
        ];
    }

    public function saveForUser(int $userId, string $provider, array $payload): SeoSerpProviderConnection
    {
        if (! SerpProviderKeys::isValid($provider)) {
            throw new \InvalidArgumentException("Invalid SERP provider: {$provider}");
        }

        $connection = $this->resolveForUser($userId, $provider) ?? new SeoSerpProviderConnection([
            'user_id' => $userId,
            'provider' => $provider,
            'is_global' => false,
            'status' => 'not_configured',
        ]);

        $connection->name = trim((string) ($payload['name'] ?? $connection->name ?: SerpProviderKeys::label($provider)));
        if (filled($payload['api_key'] ?? null)) {
            $connection->api_key = (string) $payload['api_key'];
        }

        $connection->status = (string) ($payload['status'] ?? $connection->status ?? 'inactive');
        $connection->default_country = $this->nullableString($payload['default_country'] ?? null);
        $connection->default_language = $this->nullableString($payload['default_language'] ?? null);
        $connection->default_location = $this->nullableString($payload['default_location'] ?? null);
        $connection->default_device = $this->nullableString($payload['default_device'] ?? null) ?: 'desktop';
        $connection->result_depth = max(1, min(100, (int) ($payload['result_depth'] ?? $connection->result_depth ?: 100)));
        $connection->save();

        return $connection;
    }

    public function deleteById(int $userId, int $connectionId): bool
    {
        $connection = SeoSerpProviderConnection::query()
            ->where('id', $connectionId)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->first();

        if ($connection === null || $connection->is_global) {
            return false;
        }

        $connection->delete();

        return true;
    }

    /**
     * @return array{ok: bool, message: string, usage: SerpProviderUsage|null}
     */
    public function testConnection(SeoSerpProviderConnection $connection): array
    {
        if (! $connection->isConfigured()) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.serp_not_configured'),
                'usage' => null,
            ];
        }

        try {
            $provider = $this->registry->get((string) $connection->provider);
            $result = $provider->testConnection($connection);

            $connection->status = ($result['ok'] ?? false) ? 'active' : 'invalid_credentials';
            $connection->last_error = ($result['ok'] ?? false) ? null : $this->sanitizeError((string) ($result['message'] ?? ''));
            $connection->last_checked_at = now();
            $connection->save();

            return $result;
        } catch (\Throwable $exception) {
            $connection->status = 'provider_unavailable';
            $connection->last_error = $this->sanitizeError($exception->getMessage());
            $connection->last_checked_at = now();
            $connection->save();

            return [
                'ok' => false,
                'message' => $connection->last_error,
                'usage' => null,
            ];
        }
    }

    public function markRankCheckCompleted(SeoSerpProviderConnection $connection): void
    {
        $connection->last_rank_check_at = now();
        $connection->save();
    }

    private function resolveUsageLabel(SeoSerpProviderConnection $connection): ?string
    {
        try {
            $usage = $this->registry->get((string) $connection->provider)->getUsageOrCredits($connection);
        } catch (\Throwable) {
            return __('seo-content-ai::filament.api_connections.usage_unavailable');
        }

        if ($usage === null || ! $usage->available) {
            return __('seo-content-ai::filament.api_connections.usage_unavailable');
        }

        if ($usage->creditsRemaining !== null) {
            return __('seo-content-ai::filament.api_connections.usage_credits_remaining', [
                'count' => number_format($usage->creditsRemaining),
            ]);
        }

        return __('seo-content-ai::filament.api_connections.usage_unavailable');
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('seo-content-ai::filament.api_connections.status_active'),
            'inactive' => __('seo-content-ai::filament.api_connections.status_inactive'),
            'invalid_credentials' => __('seo-content-ai::filament.api_connections.status_invalid_credentials'),
            'quota_exhausted' => __('seo-content-ai::filament.api_connections.status_quota_exhausted'),
            'rate_limited' => __('seo-content-ai::filament.api_connections.status_rate_limited'),
            'provider_unavailable' => __('seo-content-ai::filament.api_connections.status_provider_unavailable'),
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function sanitizeError(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches('/(password|api[_ -]?key|secret|token|x-api-key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    }
}
