<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Illuminate\Support\Facades\Http;

final class LaravelConfigHttpSender implements ConfigHttpSender
{
    public function send(string $method, string $url, array $headers, string $body): ConfigHttpResult
    {
        $timeout = (int) config('configuration-sync.timeout_seconds', 20);
        try {
            $response = Http::withHeaders($headers)
                ->timeout(max(1, $timeout))
                ->withBody($body, 'application/json')
                ->send(strtoupper($method), $url);
        } catch (\Throwable $e) {
            throw new ConfigurationSyncException('server_offline', 'OPS-SERVER configuration endpoint is unreachable.', 0);
        }

        $flat = [];
        foreach ($response->headers() as $name => $values) {
            $flat[(string) $name] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return new ConfigHttpResult($response->status(), $response->body(), $flat);
    }
}
