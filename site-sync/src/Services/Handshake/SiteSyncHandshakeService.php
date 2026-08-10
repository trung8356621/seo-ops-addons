<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Handshake;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Laravel ↔ WP callback handshake (no secrets in UI).
 */
final class SiteSyncHandshakeService
{
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_INCOMPATIBLE = 'incompatible';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly WordPressSiteSyncClient $client,
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(Site $site): array
    {
        return SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_HANDSHAKE) ?? [
            'status' => self::STATUS_NOT_CONFIGURED,
            'message' => 'Callback chưa xác minh',
            'checked_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(Site $site): array
    {
        $site->loadMissing('metas');
        $checks = [];
        $status = self::STATUS_HEALTHY;

        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $checks[] = [
            'key' => 'connection_identity',
            'ok' => $token !== '' && trim((string) $site->domain) !== '',
            'label' => $token === '' ? 'Thiếu token/domain' : 'Kết nối đồng bộ hoạt động',
        ];

        $caps = $this->client->fetchCapabilities($site);
        $bridge = (string) ($caps['manifest']?->bridgeVersion ?? '');
        $compatible = $bridge !== '' && version_compare($bridge, SiteSyncSchema::MIN_BRIDGE_VERSION, '>=');
        $checks[] = [
            'key' => 'plugin_version',
            'ok' => $compatible,
            'label' => $compatible
                ? 'Plugin '.$bridge
                : 'Plugin cần nâng cấp (≥ '.SiteSyncSchema::MIN_BRIDGE_VERSION.')',
        ];
        $checks[] = [
            'key' => 'contract_version',
            'ok' => ($caps['success'] ?? false),
            'label' => ($caps['success'] ?? false) ? SiteSyncSchema::VERSION : 'Capability endpoint không phản hồi',
        ];

        $secret = trim((string) ($site->getMeta('seo_sync_callback_secret') ?? ''));
        $signedOk = ! $this->flags->requireSignedCallbacks() || $secret !== '';
        $checks[] = [
            'key' => 'hmac_secret',
            'ok' => $signedOk,
            'label' => $signedOk
                ? ($secret !== '' ? 'Chữ ký đã cấu hình' : 'Chữ ký tùy chọn')
                : 'Chữ ký không hợp lệ / chưa cấu hình',
        ];

        // Round-trip probe: capability latency as health proxy (no secret echo).
        $driftMs = null;
        $started = microtime(true);
        try {
            $auth = $this->probeBase($site);
            if ($auth['base'] !== '') {
                $nonce = Str::lower(Str::random(12));
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->withToken($auth['token'])
                    ->get($auth['base'].'/wp-json/omi-seo-ai/v1/capabilities', ['ping' => $nonce]);
                $driftMs = (int) ((microtime(true) - $started) * 1000);
                $checks[] = [
                    'key' => 'capability_endpoint',
                    'ok' => $response->successful(),
                    'label' => $response->successful()
                        ? 'Capability OK ('.$driftMs.'ms)'
                        : 'Capability endpoint không phản hồi',
                ];
                $checks[] = [
                    'key' => 'server_time_drift',
                    'ok' => $driftMs < 5000,
                    'label' => $driftMs >= 5000 ? 'Đồng hồ máy chủ lệch / latency cao' : 'Latency chấp nhận được',
                ];
            }
        } catch (Throwable $e) {
            $checks[] = [
                'key' => 'capability_endpoint',
                'ok' => false,
                'label' => 'Capability endpoint không phản hồi',
            ];
            $status = self::STATUS_FAILED;
        }

        foreach ($checks as $check) {
            if (! $check['ok']) {
                if (in_array($check['key'], ['plugin_version', 'contract_version'], true)) {
                    $status = self::STATUS_INCOMPATIBLE;
                } elseif ($status === self::STATUS_HEALTHY) {
                    $status = self::STATUS_DEGRADED;
                }
            }
        }
        if (! ($checks[0]['ok'] ?? false)) {
            $status = self::STATUS_NOT_CONFIGURED;
        }

        $message = match ($status) {
            self::STATUS_HEALTHY => 'Kết nối đồng bộ hoạt động',
            self::STATUS_NOT_CONFIGURED => 'Callback chưa xác minh',
            self::STATUS_INCOMPATIBLE => 'Plugin cần nâng cấp',
            self::STATUS_FAILED => 'Handshake thất bại',
            default => 'Đồng bộ ở trạng thái suy giảm',
        };

        $payload = [
            'status' => $status,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
            'bridge_version' => $bridge,
            'contract' => SiteSyncSchema::VERSION,
            'latency_ms' => $driftMs,
            'checks' => $checks,
            // Never include secret.
        ];
        SiteSyncSiteMeta::putJson($site, SiteSyncSchema::META_HANDSHAKE, $payload);

        return $payload;
    }

    /**
     * @return array{token: string, base: string}
     */
    private function probeBase(Site $site): array
    {
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $domain = trim((string) $site->domain);
        $base = $domain === ''
            ? ''
            : (preg_match('#^https?://#i', $domain) === 1 ? rtrim($domain, '/') : 'https://'.ltrim($domain, '/'));

        return ['token' => $token, 'base' => $base];
    }
}
