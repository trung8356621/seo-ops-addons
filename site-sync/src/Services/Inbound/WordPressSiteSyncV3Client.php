<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Inbound;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Throwable;

/**
 * Pulls site_sync.v3 discover/records from WordPress bridge.
 * Auth pattern mirrors WordPressSiteSyncClient (read token + domain base).
 */
final class WordPressSiteSyncV3Client
{
    /**
     * @return array{success: bool, message: string, discover?: array<string, mixed>}
     */
    public function discover(Site $site): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        try {
            $response = $this->getWpRest(
                $auth['base'],
                $auth['token'],
                '/omi-seo-ai/v1/sync/v3/discover',
                30,
            );

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'v3 discover HTTP '.$response->status(),
                ];
            }

            $json = $response->json();
            if (! is_array($json)) {
                return ['success' => false, 'message' => 'v3 discover payload invalid'];
            }

            $discover = is_array($json['discover'] ?? null) ? $json['discover'] : $json;
            if (! isset($discover['schema'])) {
                $discover['schema'] = SiteSyncV3Schema::VERSION;
            }

            return [
                'success' => true,
                'message' => 'ok',
                'discover' => $discover,
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('site_sync.v3_discover_failed', [
                'site_id' => (int) $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Keyset cursor pagination — body must use cursor object, never offset.
     *
     * @param  array<string, mixed>  $body
     * @return array{success: bool, message: string, records?: array<string, mixed>, timings?: array<string, int>}
     */
    public function records(Site $site, array $body): array
    {
        $auth = $this->authContext($site);
        if ($auth['error'] !== null) {
            return ['success' => false, 'message' => $auth['error']];
        }

        if (array_key_exists('offset', $body)) {
            unset($body['offset']);
        }

        try {
            $started = hrtime(true);
            $response = Http::timeout(90)
                ->acceptJson()
                ->withToken($auth['token'])
                ->post($auth['base'].'/wp-json/omi-seo-ai/v1/sync/v3/records', $body);
            $wpMs = (int) ((hrtime(true) - $started) / 1_000_000);

            if (! $response->successful()) {
                $detail = '';
                $json = $response->json();
                if (is_array($json)) {
                    $detail = trim((string) ($json['message'] ?? $json['error'] ?? ''));
                }

                return [
                    'success' => false,
                    'message' => $detail !== ''
                        ? 'v3 records HTTP '.$response->status().': '.$detail
                        : 'v3 records HTTP '.$response->status(),
                    'timings' => ['wp_request_ms' => $wpMs],
                ];
            }

            $decodeStarted = hrtime(true);
            $json = $response->json();
            $decodeMs = (int) ((hrtime(true) - $decodeStarted) / 1_000_000);

            if (! is_array($json)) {
                return [
                    'success' => false,
                    'message' => 'v3 records json invalid',
                    'timings' => ['wp_request_ms' => $wpMs, 'decode_ms' => $decodeMs],
                ];
            }

            $records = is_array($json['records'] ?? null) ? $json['records'] : $json;

            return [
                'success' => true,
                'message' => 'ok',
                'records' => $records,
                'timings' => [
                    'wp_request_ms' => $wpMs,
                    'decode_ms' => $decodeMs,
                ],
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('site_sync.v3_records_failed', [
                'site_id' => (int) $site->id,
                'resource' => (string) ($body['resource'] ?? ''),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function getWpRest(string $base, string $token, string $route, int $timeout): mixed
    {
        $http = Http::timeout($timeout)->acceptJson()->withToken($token);
        $response = $http->get($base.'/wp-json'.$route);
        if ($response->status() !== 404) {
            return $response;
        }

        return Http::timeout($timeout)
            ->acceptJson()
            ->withToken($token)
            ->get($base.'/', ['rest_route' => $route]);
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function authContext(Site $site): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($token === '') {
            return ['token' => '', 'base' => '', 'error' => 'Thiếu SEO Read Token.'];
        }

        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return ['token' => '', 'base' => '', 'error' => 'Domain site không hợp lệ.'];
        }

        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');

        return ['token' => $token, 'base' => rtrim($base, '/'), 'error' => null];
    }
}
