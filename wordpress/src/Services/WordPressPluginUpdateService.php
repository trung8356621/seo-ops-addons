<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use App\Support\RuntimeLogger;

final class WordPressPluginUpdateService
{
    public const META_KEY = 'seo_wp_plugin_update';

    public function __construct(
        private readonly WordPressPluginUpdateClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(Site $site): array
    {
        $stored = $this->load($site);
        $installed = trim((string) ($stored['installed_version'] ?? ''));
        if ($installed === '') {
            $installed = $this->fallbackInstalledVersion($site);
        }

        $supported = $stored['plugin_update_supported'] ?? null;
        $latest = trim((string) ($stored['latest_version'] ?? ''));
        $checkedAt = trim((string) ($stored['version_checked_at'] ?? ''));
        $updateAvailable = (bool) ($stored['update_available'] ?? false);
        if ($latest !== '' && $installed !== '') {
            $updateAvailable = version_compare($installed, $latest, '<');
        }

        $phase = (string) ($stored['last_update_status'] ?? '');

        return [
            'installed_version' => $installed !== '' ? $installed : null,
            'latest_version' => $latest !== '' ? $latest : null,
            'update_available' => $updateAvailable,
            'version_checked_at' => $checkedAt !== '' ? $checkedAt : null,
            'plugin_update_supported' => $supported,
            'last_update_status' => $phase !== '' ? $phase : null,
            'last_update_error' => $stored['last_update_error'] ?? null,
            'last_update_attempt_at' => $stored['last_update_attempt_at'] ?? null,
            'release_url' => $stored['release_url'] ?? null,
            'can_check' => true,
            'can_update' => $supported === true && $updateAvailable,
            'unsupported' => $supported === false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Site $site): array
    {
        $this->log('plugin_update_check', $site, []);
        $result = $this->client->check($site, true);

        if (($result['code'] ?? '') === 'capability_missing') {
            $this->persist($site, [
                'plugin_update_supported' => false,
                'installed_version' => $this->fallbackInstalledVersion($site),
                'latest_version' => null,
                'update_available' => false,
                'version_checked_at' => now()->toIso8601String(),
                'last_update_error' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel',
            ]);

            return [
                'ok' => false,
                'code' => 'capability_missing',
                'message' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel',
                'status' => $this->status($site),
            ];
        }

        if (($result['ok'] ?? false) !== true) {
            $installed = (string) ($result['installed_version'] ?? $this->fallbackInstalledVersion($site));
            $message = $this->humanMessage((string) ($result['code'] ?? ''), $installed, (string) ($result['message'] ?? ''));
            $this->persist($site, [
                'installed_version' => $installed !== '' ? $installed : null,
                'last_update_error' => $message,
                'version_checked_at' => now()->toIso8601String(),
            ]);

            return [
                'ok' => false,
                'code' => (string) ($result['code'] ?? 'wp_unreachable'),
                'message' => $message,
                'status' => $this->status($site),
            ];
        }

        $this->persist($site, [
            'plugin_update_supported' => true,
            'installed_version' => $result['installed_version'] ?? null,
            'latest_version' => $result['latest_version'] ?? null,
            'update_available' => (bool) ($result['update_available'] ?? false),
            'version_checked_at' => (string) ($result['checked_at'] ?? now()->toIso8601String()),
            'release_url' => $result['release_url'] ?? null,
            'last_update_error' => null,
        ]);

        $latest = (string) ($result['latest_version'] ?? '');

        return [
            'ok' => true,
            'message' => $latest !== '' ? 'Đã kiểm tra · phiên bản mới nhất '.$latest : 'Đã kiểm tra',
            'status' => $this->status($site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Site $site): array
    {
        $before = $this->status($site);
        $previous = (string) ($before['installed_version'] ?? '');
        $expected = (string) ($before['latest_version'] ?? '');

        if ($before['unsupported'] === true) {
            return [
                'ok' => false,
                'message' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel',
                'status' => $before,
            ];
        }

        if ($expected === '' || ! $before['update_available']) {
            return [
                'ok' => false,
                'message' => 'Chưa có bản cập nhật.',
                'status' => $before,
            ];
        }

        $operationId = WordPressPluginUpdateClient::newOperationId();
        $this->persist($site, [
            'last_update_attempt_at' => now()->toIso8601String(),
            'last_update_status' => 'updating',
            'last_update_error' => null,
            'last_operation_id' => $operationId,
        ]);
        $this->log('plugin_update_requested', $site, [
            'operation_id' => $operationId,
            'from' => $previous,
            'to' => $expected,
        ]);

        $install = $this->client->install($site, $operationId);
        $this->persist($site, [
            'last_update_status' => 'verifying',
        ]);

        $verified = $this->verifyAfterInstall($site, $previous, $expected, $install);
        $this->persist($site, $verified['persist']);
        $this->log($verified['event'], $site, [
            'operation_id' => $operationId,
            'installed_version' => $verified['persist']['installed_version'] ?? null,
        ]);

        return [
            'ok' => $verified['ok'],
            'message' => $verified['message'],
            'status' => $this->status($site),
        ];
    }

    /**
     * @param  array<string, mixed>  $install
     * @return array{ok: bool, message: string, event: string, persist: array<string, mixed>}
     */
    public function verifyAfterInstall(Site $site, string $previous, string $expected, array $install): array
    {
        $heartbeat = $this->client->heartbeat($site);
        $observed = trim((string) ($heartbeat['plugin_version'] ?? ''));
        $activeCaps = is_array($heartbeat['capabilities'] ?? null) ? $heartbeat['capabilities'] : [];
        $pluginUpdate = (bool) ($activeCaps['plugin_update'] ?? false);

        if ($observed !== '' && $expected !== '' && version_compare($observed, $expected, '>=')) {
            $reconciled = ($install['timed_out'] ?? false) === true || ($install['ok'] ?? false) !== true;
            $this->rememberHeartbeat($site, $heartbeat);

            return [
                'ok' => true,
                'message' => 'Đã cập nhật thành công',
                'event' => $reconciled ? 'plugin_update_reconciled' : 'plugin_update_completed',
                'persist' => [
                    'plugin_update_supported' => $pluginUpdate || true,
                    'installed_version' => $observed,
                    'latest_version' => $expected,
                    'update_available' => false,
                    'version_checked_at' => now()->toIso8601String(),
                    'last_update_status' => $reconciled ? 'reconciled' : 'completed',
                    'last_update_error' => null,
                ],
            ];
        }

        if (($heartbeat['plugin_active'] ?? true) === false || (($install['plugin_active'] ?? true) === false && $observed === $previous)) {
            return [
                'ok' => false,
                'message' => 'Plugin không còn active sau khi cập nhật.',
                'event' => 'plugin_update_failed',
                'persist' => [
                    'last_update_status' => 'failed',
                    'last_update_error' => 'Plugin không còn active sau khi cập nhật.',
                    'installed_version' => $observed !== '' ? $observed : $previous,
                ],
            ];
        }

        $message = $this->humanMessage(
            (string) ($install['code'] ?? 'upgrade_failed'),
            $previous,
            (string) ($install['message'] ?? ''),
        );

        return [
            'ok' => false,
            'message' => $message,
            'event' => 'plugin_update_failed',
            'persist' => [
                'last_update_status' => 'failed',
                'last_update_error' => $message,
                'installed_version' => $observed !== '' ? $observed : ($install['installed_version'] ?? $previous),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $heartbeat
     */
    private function rememberHeartbeat(Site $site, array $heartbeat): void
    {
        $site->metas()->updateOrCreate(
            ['meta_key' => 'seo_wp_heartbeat'],
            ['meta_value' => (string) json_encode([
                'status' => $heartbeat['status'] ?? 'ok',
                'plugin_version' => $heartbeat['plugin_version'] ?? null,
                'capabilities' => $heartbeat['capabilities'] ?? [],
                'plugin_update_source' => $heartbeat['plugin_update_source'] ?? 'github_release',
                'observed_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function load(Site $site): array
    {
        $site->loadMissing('metas');
        $raw = trim((string) ($site->getMeta(self::META_KEY) ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function persist(Site $site, array $patch): void
    {
        $merged = array_merge($this->load($site), $patch);
        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => (string) json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
        $site->unsetRelation('metas');
        $site->load('metas');
    }

    private function fallbackInstalledVersion(Site $site): string
    {
        $heartbeat = $site->getMeta('seo_wp_heartbeat');
        if (is_string($heartbeat) && $heartbeat !== '') {
            $decoded = json_decode($heartbeat, true);
            if (is_array($decoded) && ! empty($decoded['plugin_version'])) {
                return (string) $decoded['plugin_version'];
            }
        }

        $info = app(WordPressSiteInfoService::class)->getStoredSiteInfo($site);
        $fromInfo = trim((string) ($info['bridge_version'] ?? ''));

        return $fromInfo;
    }

    private function humanMessage(string $code, string $installed, string $fallback): string
    {
        return match ($code) {
            'github_asset_missing' => $fallback !== '' ? $fallback : 'Bản phát hành không có gói cài đặt hợp lệ.',
            'github_invalid_tag' => $fallback !== '' ? $fallback : 'Bản phát hành GitHub không có phiên bản hợp lệ.',
            'capability_missing' => 'Plugin hiện tại chưa hỗ trợ cập nhật từ Laravel',
            'wp_unreachable', 'unauthorized', 'timeout' => 'Không thể kết nối website.',
            'plugin_inactive' => 'Plugin không còn active sau khi cập nhật.',
            default => $fallback !== ''
                ? $fallback
                : ('Không thể kiểm tra phiên bản mới.'.($installed !== '' ? ' Phiên bản đang cài: '.$installed : '')),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, Site $site, array $context): void
    {
        RuntimeLogger::channel()->info('wordpress.'.$event, array_merge([
            'site_id' => $site->getKey(),
            'domain' => (string) $site->domain,
        ], $context));
    }
}
