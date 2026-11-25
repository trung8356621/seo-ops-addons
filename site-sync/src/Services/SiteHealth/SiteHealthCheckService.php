<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Throwable;

/**
 * Domain & hosting availability only.
 * HTTP status codes and WordPress Bridge probes do not trigger Site Health Down.
 */
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
            // Single probe per check — do not retry here (retries would inflate failure evidence).
            $response = Http::connectTimeout(3)->timeout(5)->withHeaders(['Range' => 'bytes=0-1023'])->get($base);
        } catch (Throwable $e) {
            $code = self::classifyException($e);

            // TLS/certificate issues are recorded but never treated as Hosting Down.
            if ($code === 'TLS_ERROR') {
                $stages['connectivity'] = ['status' => 'warning', 'detail' => 'TLS_ERROR'];
                $stages['public_site'] = ['status' => 'ok', 'detail' => 'TLS_RECORDED'];

                return SiteHealthResult::ok($stages);
            }

            $stages['connectivity'] = ['status' => 'failed', 'detail' => $code];

            return SiteHealthResult::failed('down', 'critical', $code, __("site_health.reasons.{$code}"), $stages, $e->getMessage());
        }

        // Any HTTP response (2xx/3xx/4xx/5xx) means the host answered — site is alive.
        $stages['connectivity'] = ['status' => 'ok'];
        $stages['public_site'] = ['status' => 'ok', 'detail' => 'HTTP '.$response->status()];

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
