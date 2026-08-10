<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WordPressSiteInfoService
{
    public const META_PLUGIN = 'seo_plugin';

    public const META_PLUGIN_INFO = 'seo_wp_plugin_info';

    public const META_PLUGIN_FETCHED_AT = 'seo_wp_plugin_info_fetched_at';

    /**
     * Gọi WordPress GET /site-info và lưu vào site_metas (không lưu theo article_meta).
     *
     * @return array{success:bool,message:string,site_info?:array<string,mixed>}
     */
    public function fetchAndStore(Site $site): array
    {
        $site->loadMissing('metas');

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token.',
            ];
        }

        $url = $this->buildSiteInfoUrl($site);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Domain site không hợp lệ.',
            ];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress site-info HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 300),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi site-info không hợp lệ.',
                ];
            }

            $siteInfo = is_array($payload['site_info'] ?? null) ? $payload['site_info'] : [];
            if ($siteInfo === []) {
                return [
                    'success' => false,
                    'message' => 'WordPress không trả site_info.',
                ];
            }

            $this->persistSiteInfo($site, $siteInfo);

            return [
                'success' => true,
                'message' => 'Đã lấy thông tin plugin SEO từ WordPress.',
                'site_info' => $siteInfo,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress site-info fetch failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không gọi được site-info: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $siteInfo
     */
    public function persistSiteInfo(Site $site, array $siteInfo): void
    {
        $active = trim((string) ($siteInfo['active'] ?? 'none'));
        if ($active === '') {
            $active = 'none';
        }

        $fetchedAt = now()->toIso8601String();

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_PLUGIN],
            ['meta_value' => $active],
        );

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_PLUGIN_INFO],
            ['meta_value' => json_encode($siteInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_PLUGIN_FETCHED_AT],
            ['meta_value' => $fetchedAt],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStoredSiteInfo(Site $site): ?array
    {
        $site->loadMissing('metas');
        $raw = trim((string) ($site->getMeta(self::META_PLUGIN_INFO) ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function buildSiteInfoUrl(Site $site): string
    {
        $base = $this->buildSiteBaseUrl($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/site-info';
    }

    private function buildSiteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme . '://' . rtrim($domain, '/');
    }
}
