<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Models\SeoDataForSeoConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DataForSeoConnectionService
{
    private const API_BASE = 'https://api.dataforseo.com/v3';

    public function resolveForUser(int $userId): ?SeoDataForSeoConnection
    {
        return SeoDataForSeoConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderByDesc('is_global')
            ->orderByDesc('id')
            ->first();
    }

    public function isConfiguredForUser(int $userId): bool
    {
        $connection = $this->resolveForUser($userId);

        return $connection !== null
            && filled($connection->login)
            && filled($connection->password)
            && $connection->status === 'connected';
    }

    /**
     * @return array{status: string, label: string, balance: float|null, last_checked_at: string|null, configured: bool}
     */
    public function statusForUser(int $userId): array
    {
        $connection = $this->resolveForUser($userId);
        if ($connection === null) {
            return [
                'status' => 'not_configured',
                'label' => __('seo-content-ai::filament.api_connections.not_configured'),
                'balance' => null,
                'last_checked_at' => null,
                'configured' => false,
            ];
        }

        return [
            'status' => (string) $connection->status,
            'label' => $this->statusLabel((string) $connection->status),
            'balance' => $connection->balance !== null ? (float) $connection->balance : null,
            'last_checked_at' => $connection->last_checked_at?->toDateTimeString(),
            'configured' => filled($connection->login) && filled($connection->password),
        ];
    }

    public function saveForUser(int $userId, array $payload): SeoDataForSeoConnection
    {
        $connection = $this->resolveForUser($userId) ?? new SeoDataForSeoConnection([
            'user_id' => $userId,
            'is_global' => false,
        ]);

        $connection->login = trim((string) ($payload['login'] ?? $connection->login));
        if (filled($payload['password'] ?? null)) {
            $connection->password = (string) $payload['password'];
        }
        $connection->default_location = trim((string) ($payload['default_location'] ?? '')) ?: null;
        $connection->default_language = trim((string) ($payload['default_language'] ?? '')) ?: null;
        $connection->save();

        return $connection;
    }

    public function deleteById(int $userId, int $connectionId): bool
    {
        $connection = SeoDataForSeoConnection::query()
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
     * @return array{ok: bool, message: string, balance: float|null}
     */
    public function testConnection(SeoDataForSeoConnection $connection): array
    {
        try {
            $response = Http::withBasicAuth((string) $connection->login, (string) $connection->password)
                ->timeout(20)
                ->get(self::API_BASE.'/appendix/user_data');

            if (! $response->successful()) {
                $connection->status = 'error';
                $connection->last_error = $this->sanitizeError($response->json('status_message') ?? $response->body());
                $connection->last_checked_at = now();
                $connection->save();

                return [
                    'ok' => false,
                    'message' => $connection->last_error ?? __('seo-content-ai::filament.api_connections.test_failed'),
                    'balance' => null,
                ];
            }

            $balance = (float) ($response->json('tasks.0.result.0.money.balance') ?? 0);
            $connection->status = 'connected';
            $connection->balance = $balance;
            $connection->last_error = null;
            $connection->last_checked_at = now();
            $connection->save();

            return [
                'ok' => true,
                'message' => __('seo-content-ai::filament.api_connections.test_success'),
                'balance' => $balance,
            ];
        } catch (\Throwable $exception) {
            $connection->status = 'error';
            $connection->last_error = $this->sanitizeError($exception->getMessage());
            $connection->last_checked_at = now();
            $connection->save();

            return [
                'ok' => false,
                'message' => $connection->last_error,
                'balance' => null,
            ];
        }
    }

    /**
     * @return array{position: float|null, ranking_url: string|null, search_volume: int|null, allintitle: int|null}
     */
    public function fetchKeywordMetrics(
        SeoDataForSeoConnection $connection,
        string $keyword,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
    ): array {
        $location = $location ?: $connection->default_location;
        $language = $language ?: $connection->default_language;

        $rankPayload = [
            [
                'keyword' => $keyword,
                'location_name' => $location,
                'language_name' => $language,
                'device' => $device ?: 'desktop',
                'depth' => 100,
            ],
        ];

        $rankResponse = Http::withBasicAuth((string) $connection->login, (string) $connection->password)
            ->timeout(45)
            ->post(self::API_BASE.'/serp/google/organic/live/advanced', $rankPayload);

        $position = null;
        $rankingUrl = null;
        if ($rankResponse->successful()) {
            $items = $rankResponse->json('tasks.0.result.0.items') ?? [];
            if (is_array($items) && $items !== []) {
                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $position = (float) ($index + 1);
                    $rankingUrl = (string) ($item['url'] ?? '');
                    break;
                }
            }
        }

        $volumePayload = [[
            'keywords' => [$keyword],
            'location_name' => $location,
            'language_name' => $language,
        ]];

        $volumeResponse = Http::withBasicAuth((string) $connection->login, (string) $connection->password)
            ->timeout(30)
            ->post(self::API_BASE.'/keywords_data/google_ads/search_volume/live', $volumePayload);

        $searchVolume = null;
        if ($volumeResponse->successful()) {
            $searchVolume = (int) ($volumeResponse->json('tasks.0.result.0.search_volume') ?? 0);
            if ($searchVolume <= 0) {
                $searchVolume = null;
            }
        }

        $allintitlePayload = [[
            'keyword' => 'allintitle:'.$keyword,
            'location_name' => $location,
            'language_name' => $language,
            'device' => $device ?: 'desktop',
            'depth' => 10,
        ]];

        $allintitleResponse = Http::withBasicAuth((string) $connection->login, (string) $connection->password)
            ->timeout(30)
            ->post(self::API_BASE.'/serp/google/organic/live/advanced', $allintitlePayload);

        $allintitle = null;
        if ($allintitleResponse->successful()) {
            $total = (int) ($allintitleResponse->json('tasks.0.result.0.se_results_count') ?? 0);
            $allintitle = $total > 0 ? $total : null;
        }

        return [
            'position' => $position,
            'ranking_url' => $rankingUrl !== '' ? $rankingUrl : null,
            'search_volume' => $searchVolume,
            'allintitle' => $allintitle,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'connected' => __('seo-content-ai::filament.api_connections.connected'),
            'error' => __('seo-content-ai::filament.api_connections.error'),
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    private function sanitizeError(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches('/(password|api[_ -]?key|secret|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
    }
}
