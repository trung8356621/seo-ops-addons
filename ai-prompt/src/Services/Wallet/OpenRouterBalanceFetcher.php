<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Wallet;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenRouterBalanceFetcher implements ProviderBalanceFetcherInterface
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/credits';

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === 'openrouter';
    }

    public function fetch(ApiConnection $connection): ProviderBalanceResult
    {
        $apiKey = (string) ($connection->api_key ?? '');
        if (trim($apiKey) === '') {
            return ProviderBalanceResult::supportedFailed('API key trống.');
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                ])
                ->get(self::ENDPOINT);

            if (! $response->successful()) {
                $status = $response->status();
                $body = $response->json();
                $msg = is_array($body) && isset($body['error']['message'])
                    ? (string) $body['error']['message']
                    : ($response->body() ?: 'HTTP '.$status);

                return ProviderBalanceResult::supportedFailed("Lỗi kết nối OpenRouter (HTTP {$status}): {$msg}");
            }

            $data = $response->json();
            if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
                return ProviderBalanceResult::supportedFailed('Phản hồi OpenRouter không đúng cấu trúc.');
            }

            $creditsData = $data['data'];
            $totalCredits = (float) ($creditsData['total_credits'] ?? 0.0);
            $totalUsage = (float) ($creditsData['total_usage'] ?? 0.0);
            $remaining = round(max(0.0, $totalCredits - $totalUsage), 4);

            return ProviderBalanceResult::supportedSuccess($remaining, 'USD');
        } catch (Throwable $e) {
            return ProviderBalanceResult::supportedFailed('Lỗi gọi API OpenRouter: '.$e->getMessage());
        }
    }
}
