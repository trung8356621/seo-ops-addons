<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetricStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoDataForSeoConnection;
use Illuminate\Support\Facades\Http;

final class KeywordSearchVolumeService
{
    private const API_BASE = 'https://api.dataforseo.com/v3';

    public function __construct(
        private readonly DataForSeoConnectionService $dataForSeo,
    ) {}

    public function isConfiguredForUser(int $userId): bool
    {
        return $this->dataForSeo->isConfiguredForUser($userId);
    }

    /**
     * @return array{volume: int|null, status: string, source: string|null, error: string|null}
     */
    public function resolveVolume(
        int $userId,
        string $keyword,
        ?string $location = null,
        ?string $language = null,
    ): array {
        $connection = $this->dataForSeo->resolveForUser($userId);
        if ($connection === null || ! $this->dataForSeo->isConfiguredForUser($userId)) {
            return [
                'volume' => null,
                'status' => KeywordMetricStatus::NotConfigured->value,
                'source' => null,
                'error' => null,
            ];
        }

        return $this->fetchFromDataForSeo($connection, $keyword, $location, $language);
    }

    /**
     * @return array{volume: int|null, status: string, source: string|null, error: string|null}
     */
    private function fetchFromDataForSeo(
        SeoDataForSeoConnection $connection,
        string $keyword,
        ?string $location,
        ?string $language,
    ): array {
        $location = $location ?: $connection->default_location;
        $language = $language ?: $connection->default_language;

        $payload = [[
            'keywords' => [$keyword],
            'location_name' => $location,
            'language_name' => $language,
        ]];

        try {
            $response = Http::withBasicAuth((string) $connection->login, (string) $connection->password)
                ->timeout(30)
                ->post(self::API_BASE.'/keywords_data/google_ads/search_volume/live', $payload);

            if (! $response->successful()) {
                return [
                    'volume' => null,
                    'status' => KeywordMetricStatus::Failed->value,
                    'source' => 'dataforseo_google_ads',
                    'error' => __('seo-content-ai::filament.performance_hub.volume_fetch_failed'),
                ];
            }

            $volume = (int) ($response->json('tasks.0.result.0.search_volume') ?? 0);

            return [
                'volume' => $volume > 0 ? $volume : null,
                'status' => $volume > 0
                    ? KeywordMetricStatus::Success->value
                    : KeywordMetricStatus::NotFound->value,
                'source' => 'dataforseo_google_ads',
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'volume' => null,
                'status' => KeywordMetricStatus::Failed->value,
                'source' => 'dataforseo_google_ads',
                'error' => mb_substr($exception->getMessage(), 0, 240),
            ];
        }
    }
}
