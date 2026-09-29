<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Throwable;

final class SiteHealthCheckService
{
    public function __construct(private readonly DnsResolver $dns, private readonly WordPressSiteSyncClient $wordpress) {}

    public function check(Site $site): SiteHealthResult
    {
        $stages = [];
        $context = $this->wordpress->healthContext($site);
        $base = $context['base'];
        $host = (string) parse_url($base, PHP_URL_HOST);

        if ($host === '' || ! $this->dns->resolves($host)) {
            $stages['dns'] = ['status' => 'failed'];

            return SiteHealthResult::failed('down', 'critical', 'DNS_ERROR', __('site_health.reasons.DNS_ERROR'), $stages);
        }
        $stages['dns'] = ['status' => 'ok'];

        try {
            $response = Http::connectTimeout(3)->timeout(5)->withHeaders(['Range' => 'bytes=0-1023'])->get($base);
        } catch (Throwable $e) {
            $code = self::classifyException($e);
            $stages['connectivity'] = ['status' => 'failed'];

            return SiteHealthResult::failed('down', 'critical', $code, __("site_health.reasons.{$code}"), $stages, $e->getMessage());
        }

        $stages['connectivity'] = ['status' => 'ok'];
        if ($response->serverError()) {
            $stages['public_site'] = ['status' => 'failed', 'detail' => 'HTTP '.$response->status()];

            return SiteHealthResult::failed('down', 'critical', 'HTTP_5XX', __('site_health.reasons.HTTP_5XX'), $stages, 'HTTP '.$response->status());
        }
        $stages['public_site'] = ['status' => 'ok', 'detail' => 'HTTP '.$response->status()];

        $probe = $this->wordpress->healthProbe($site, '/omi-seo-ai/v1/heartbeat', 5);
        if (! $probe['ok']) {
            $code = $probe['auth_error'] ? 'WP_BRIDGE_AUTH_ERROR' : 'WP_BRIDGE_UNREACHABLE';
            $stages['heartbeat'] = ['status' => 'failed', 'detail' => $probe['message']];

            return SiteHealthResult::failed('degraded', $probe['auth_error'] ? 'critical' : 'warning', $code, __("site_health.reasons.{$code}"), $stages, $probe['message']);
        }
        if (($probe['payload']['status'] ?? '') !== 'ok') {
            $stages['heartbeat'] = ['status' => 'failed'];

            return SiteHealthResult::failed('degraded', 'warning', 'HEARTBEAT_INVALID', __('site_health.reasons.HEARTBEAT_INVALID'), $stages, 'Invalid heartbeat payload');
        }
        $stages['heartbeat'] = ['status' => 'ok'];

        $capabilities = $this->wordpress->healthProbe($site, '/omi-seo-ai/v1/capabilities', 5);
        if (! $capabilities['ok']) {
            $code = $capabilities['auth_error'] ? 'WP_BRIDGE_AUTH_ERROR' : 'WP_BRIDGE_UNREACHABLE';
            $stages['auth_plugin'] = ['status' => 'failed', 'detail' => $capabilities['message']];

            return SiteHealthResult::failed('degraded', $capabilities['auth_error'] ? 'critical' : 'warning', $code, __("site_health.reasons.{$code}"), $stages, $capabilities['message']);
        }
        $stages['auth_plugin'] = ['status' => 'ok'];

        return SiteHealthResult::ok($stages);
    }

    public static function classifyException(Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'CONNECTION_TIMEOUT';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'tls')) {
            return 'TLS_ERROR';
        }

        return $e instanceof ConnectionException ? 'CONNECTION_ERROR' : 'SITE_UNREACHABLE';
    }
}
