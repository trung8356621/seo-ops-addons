<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Illuminate\Support\Facades\Http;

final class LaravelSeoAccessTransport implements SeoAccessTransport
{
    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        $pending = Http::timeout(20)->acceptJson();
        if (is_string($bearer) && $bearer !== '') {
            $pending = $pending->withToken($bearer);
        }

        $response = strtoupper($method) === 'POST'
            ? $pending->post($url, $jsonBody ?? [])
            : $pending->get($url, $query);
        $json = $response->json();

        return [
            'status' => $response->status(),
            'json' => is_array($json) ? $json : [],
        ];
    }
}
