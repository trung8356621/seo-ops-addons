<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\SearchIntelligence\Models\SeoExtendedProviderConnection;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SeoExtendedProviderConnectionService
{
    private const KEYWORDS_EVERYWHERE_CREDITS_URL = 'https://api.keywordseverywhere.com/v1/account/credits';

    private const SE_RANKING_BALANCE_URL = 'https://api4.seranking.com/v1/account/balance';

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return [
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING,
        ];
    }

    public function isValidProvider(string $provider): bool
    {
        return in_array($provider, $this->supportedProviders(), true);
    }

    public function resolveForUser(int $userId, string $provider): ?SeoExtendedProviderConnection
    {
        if (! $this->isValidProvider($provider)) {
            return null;
        }

        return SeoExtendedProviderConnection::query()
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
     * @return list<SeoExtendedProviderConnection>
     */
    public function configuredForUser(int $userId): array
    {
        $connections = [];

        foreach ($this->supportedProviders() as $provider) {
            $connection = $this->resolveForUser($userId, $provider);
            if ($connection !== null && $connection->isConfigured()) {
                $connections[] = $connection;
            }
        }

        return $connections;
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
     * @return array{status: string, label: string, configured: bool, active: bool, last_checked_at: string|null, connection_id: int|null}
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
                'connection_id' => null,
            ];
        }

        return [
            'status' => (string) $connection->status,
            'label' => $this->statusLabel((string) $connection->status),
            'configured' => true,
            'active' => $connection->isActive(),
            'last_checked_at' => $connection->last_checked_at?->toDateTimeString(),
            'connection_id' => (int) $connection->id,
        ];
    }

    public function saveForUser(int $userId, string $provider, array $payload): SeoExtendedProviderConnection
    {
        if (! $this->isValidProvider($provider)) {
            throw new \InvalidArgumentException("Invalid extended provider: {$provider}");
        }

        $connection = $this->resolveForUser($userId, $provider) ?? new SeoExtendedProviderConnection([
            'user_id' => $userId,
            'provider' => $provider,
            'is_global' => false,
            'status' => 'not_configured',
        ]);

        $connection->name = trim((string) ($payload['name'] ?? $connection->name ?: $provider));
        if (filled($payload['api_key'] ?? null)) {
            $connection->api_key = (string) $payload['api_key'];
        }

        $connection->status = (string) ($payload['status'] ?? $connection->status ?? 'inactive');
        $connection->save();

        return $connection;
    }

    public function deleteById(int $userId, int $connectionId): bool
    {
        $connection = SeoExtendedProviderConnection::query()
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
     * @return array{ok: bool, message: string, status: string}
     */
    public function testConnection(SeoExtendedProviderConnection $connection): array
    {
        if (! $connection->isConfigured()) {
            return [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.not_configured'),
                'status' => 'not_configured',
            ];
        }

        $result = match ((string) $connection->provider) {
            ApiConnectionProviders::KEYWORDS_EVERYWHERE => $this->testKeywordsEverywhere($connection),
            ApiConnectionProviders::SE_RANKING => $this->testSeRanking($connection),
            default => [
                'ok' => false,
                'message' => __('seo-content-ai::filament.api_connections.unsupported_provider'),
                'status' => 'failed',
            ],
        };

        $connection->status = $result['status'] === 'connected' ? 'active' : $result['status'];
        $connection->last_error = $result['ok'] ? null : $this->sanitizeError((string) ($result['message'] ?? ''));
        $connection->last_checked_at = now();
        $connection->save();

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, status: string}
     */
    private function testKeywordsEverywhere(SeoExtendedProviderConnection $connection): array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$connection->api_key,
            ])->timeout(20)->get(self::KEYWORDS_EVERYWHERE_CREDITS_URL);

            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'ok' => false,
                    'message' => __('seo-content-ai::filament.api_connections.status_invalid_credentials'),
                    'status' => 'invalid_credentials',
                ];
            }

            if ($response->status() === 402) {
                return [
                    'ok' => false,
                    'message' => __('seo-content-ai::filament.api_connections.status_quota_exhausted'),
                    'status' => 'quota_exhausted',
                ];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'message' => __('seo-content-ai::filament.api_connections.test_failed'),
                    'status' => 'failed',
                ];
            }

            return [
                'ok' => true,
                'message' => __('seo-content-ai::filament.api_connections.test_success'),
                'status' => 'connected',
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => $this->sanitizeError($exception->getMessage()),
                'status' => 'failed',
            ];
        }
    }

    /**
     * @return array{ok: bool, message: string, status: string}
     */
    private function testSeRanking(SeoExtendedProviderConnection $connection): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token '.$connection->api_key,
                'Accept' => 'application/json',
            ])->timeout(20)->get(self::SE_RANKING_BALANCE_URL);

            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'ok' => false,
                    'message' => __('seo-content-ai::filament.api_connections.status_invalid_credentials'),
                    'status' => 'invalid_credentials',
                ];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'message' => __('seo-content-ai::filament.api_connections.test_failed'),
                    'status' => 'failed',
                ];
            }

            return [
                'ok' => true,
                'message' => __('seo-content-ai::filament.api_connections.test_success'),
                'status' => 'connected',
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => $this->sanitizeError($exception->getMessage()),
                'status' => 'failed',
            ];
        }
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active', 'connected' => __('seo-content-ai::filament.api_connections.status_active'),
            'inactive' => __('seo-content-ai::filament.api_connections.status_inactive'),
            'invalid_credentials' => __('seo-content-ai::filament.api_connections.status_invalid_credentials'),
            'quota_exhausted' => __('seo-content-ai::filament.api_connections.status_quota_exhausted'),
            'failed' => __('seo-content-ai::filament.api_connections.test_failed'),
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    private function sanitizeError(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches('/(password|api[_ -]?key|secret|token|x-api-key)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    }
}
