<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class WordPressPluginUpdateClient
{
    /**
     * @return array<string, mixed>
     */
    public function check(Site $site, bool $forceRefresh = true): array
    {
        $auth = $this->readAuth($site);
        if ($auth['error'] !== null) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/plugin-update/check', [
                    'force_refresh' => $forceRefresh ? '1' : '0',
                ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        } catch (Throwable $e) {
            RuntimeLogger::channel()->warning('wordpress.plugin_update.check_failed', [
                'site_id' => $site->getKey(),
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'code' => 'unauthorized', 'message' => 'Không thể kết nối website.'];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'code' => 'capability_missing', 'message' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel'];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function install(Site $site, string $operationId): array
    {
        $auth = $this->writeAuth($site);
        if ($auth['error'] !== null) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($auth['token'])
                ->post($auth['base'].'/wp-json/omi-seo-ai/v1/plugin-update/install', [
                    'operation_id' => $operationId,
                ]);
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'code' => 'timeout',
                'message' => 'Không thể kết nối website.',
                'timed_out' => true,
            ];
        } catch (Throwable $e) {
            RuntimeLogger::channel()->warning('wordpress.plugin_update.install_failed', [
                'site_id' => $site->getKey(),
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'code' => 'timeout',
                'message' => 'Không thể kết nối website.',
                'timed_out' => true,
            ];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'code' => 'unauthorized', 'message' => 'Không thể kết nối website.'];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'code' => 'capability_missing', 'message' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel'];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(Site $site): array
    {
        $auth = $this->readAuth($site);
        if ($auth['error'] !== null) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => $auth['error']];
        }

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withToken($auth['token'])
                ->get($auth['base'].'/wp-json/omi-seo-ai/v1/heartbeat');
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        $json = $response->json();
        if (! is_array($json) || ($json['status'] ?? '') !== 'ok') {
            return ['ok' => false, 'code' => 'wp_unreachable', 'message' => 'Không thể kết nối website.'];
        }

        $json['ok'] = true;

        return $json;
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function readAuth(Site $site): array
    {
        return $this->auth($site, 'seo_read_token');
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function writeAuth(Site $site): array
    {
        return $this->auth($site, 'seo_migration_token');
    }

    /**
     * @return array{token: string, base: string, error: ?string}
     */
    private function auth(Site $site, string $tokenKey): array
    {
        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta($tokenKey) ?? ''));
        if ($token === '') {
            return ['token' => '', 'base' => '', 'error' => 'Không thể kết nối website.'];
        }

        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return ['token' => '', 'base' => '', 'error' => 'Không thể kết nối website.'];
        }

        $base = preg_match('#^https?://#i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.ltrim($domain, '/');

        return ['token' => $token, 'base' => rtrim($base, '/'), 'error' => null];
    }

    public static function newOperationId(): string
    {
        return 'wp_plugin_update_'.str_replace('-', '', (string) Str::ulid());
    }
}
