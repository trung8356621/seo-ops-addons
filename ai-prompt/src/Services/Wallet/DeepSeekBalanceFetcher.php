<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Wallet;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DeepSeekBalanceFetcher implements ProviderBalanceFetcherInterface
{
    private const ENDPOINT = 'https://api.deepseek.com/user/balance';

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === 'deepseek';
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
                $msg = is_array($body) && isset($body['error_msg'])
                    ? (string) $body['error_msg']
                    : ($response->body() ?: 'HTTP '.$status);

                return ProviderBalanceResult::supportedFailed("Lỗi kết nối DeepSeek (HTTP {$status}): {$msg}");
            }

            $data = $response->json();
            if (! is_array($data) || ! isset($data['balance_infos']) || ! is_array($data['balance_infos'])) {
                return ProviderBalanceResult::supportedFailed('Phản hồi DeepSeek không đúng cấu trúc.');
            }

            // Tìm balance USD trước, nếu không có lấy phần tử đầu tiên
            $targetInfo = null;
            foreach ($data['balance_infos'] as $info) {
                if (is_array($info) && strtoupper(trim((string) ($info['currency'] ?? ''))) === 'USD') {
                    $targetInfo = $info;
                    break;
                }
            }
            if ($targetInfo === null && ! empty($data['balance_infos']) && is_array($data['balance_infos'][0])) {
                $targetInfo = $data['balance_infos'][0];
            }

            if ($targetInfo === null) {
                return ProviderBalanceResult::supportedFailed('Không tìm thấy thông tin số dư trong phản hồi DeepSeek.');
            }

            $currency = strtoupper(trim((string) ($targetInfo['currency'] ?? 'USD'))) ?: 'USD';
            $totalBalance = (float) ($targetInfo['total_balance'] ?? 0.0);

            return ProviderBalanceResult::supportedSuccess($totalBalance, $currency);
        } catch (Throwable $e) {
            return ProviderBalanceResult::supportedFailed('Lỗi gọi API DeepSeek: '.$e->getMessage());
        }
    }
}
