<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Http\Controllers\Api;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncInboundGateway;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoWpBridgeController extends Controller
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
    ) {}

    /**
     * GET /api/seo-wp-bridge/ping — kiểm tra token + domain từ plugin WP.
     */
    public function ping(Request $request): JsonResponse
    {
        $token = trim((string) ($request->bearerToken() ?? $request->header('X-Seo-Read-Token', '')));
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Thiếu Bearer token.'], 401);
        }

        $site = $this->resolveSiteByReadToken($token);
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Token không hợp lệ.'], 401);
        }

        $connection = $this->databaseConnection->bootstrapBySiteId((int) $site->id);
        $connectionHash = SeoConnectionContext::hash()
            ?? ($connection !== null ? (string) $connection->hash_id : null);

        $siteUrl = trim((string) $request->input('site_url', ''));
        $hostOk = $siteUrl === '' || $this->siteUrlMatchesSite($site, $siteUrl);

        return response()->json([
            'success' => true,
            'message' => 'Kết nối Laravel OK.',
            'site_id' => $site->id,
            'domain' => $site->domain,
            'site_url_match' => $hostOk,
            'connection_hash' => is_string($connectionHash) && $connectionHash !== '' ? $connectionHash : null,
        ]);
    }

    /**
     * Plugin WordPress đẩy một hoặc nhiều mục sau khi tạo/cập nhật bài viết.
     * POST /api/seo-wp-bridge/push-content
     * Authorization: Bearer {seo_read_token}
     */
    public function pushContent(Request $request, SiteSyncInboundGateway $inbound): JsonResponse
    {
        $token = trim((string) ($request->bearerToken() ?? $request->header('X-Seo-Read-Token', '')));
        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu Bearer token (SEO Read Token).',
            ], 401);
        }

        $site = $this->resolveSiteByReadToken($token);
        if ($site === null) {
            return response()->json([
                'success' => false,
                'message' => 'Token không hợp lệ hoặc site chưa cấu hình SEO.',
            ], 401);
        }

        $this->databaseConnection->bootstrapBySiteId((int) $site->id);

        $siteUrl = trim((string) $request->input('site_url', ''));
        if ($siteUrl !== '' && ! $this->siteUrlMatchesSite($site, $siteUrl)) {
            RuntimeLogger::warning('SeoWpBridge push: site_url host mismatch', [
                'site_id' => $site->id,
                'site_domain' => $site->domain,
                'site_url' => $siteUrl,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'site_url không khớp domain đã đăng ký trên Laravel (kiểm tra www / không www).',
            ], 403);
        }

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.wp_id' => ['required', 'integer', 'min:1'],
            'items.*.type' => ['sometimes', 'string', 'max:64'],
            'items.*.action' => ['sometimes', 'string', 'in:trash,force_delete,restore'],
        ]);

        /** @var array<int, array<string, mixed>> $items */
        $items = $request->input('items', []);
        if (! is_array($items)) {
            return response()->json([
                'success' => false,
                'message' => 'items phải là mảng.',
            ], 422);
        }

        $result = $inbound->ingestCompatPush($site, $items);

        RuntimeLogger::info('SeoWpBridge push-content', [
            'site_id' => $site->id,
            'item_count' => count($items),
            'success' => $result['success'] ?? false,
            'synced' => $result['synced'] ?? [],
        ]);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * POST /api/seo-wp-bridge/snapshot-callback — site_sync.v1 delta after WP save/publish.
     */
    public function snapshotCallback(Request $request, SiteSyncInboundGateway $inbound): JsonResponse
    {
        return $this->deltaEvent($request, app(\Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncDeltaEventIngestor::class), $inbound);
    }

    /**
     * POST /api/seo-wp-bridge/delta-event — fast ack + queue (site_sync.v1 outbox).
     */
    public function deltaEvent(
        Request $request,
        \Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncDeltaEventIngestor $ingestor,
        ?SiteSyncInboundGateway $inbound = null,
    ): JsonResponse {
        $token = trim((string) ($request->bearerToken() ?? $request->header('X-Seo-Read-Token', '')));
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Thiếu Bearer token.'], 401);
        }

        $site = $this->resolveSiteByReadToken($token);
        if ($site === null) {
            return response()->json(['success' => false, 'message' => 'Token không hợp lệ.'], 401);
        }

        $this->databaseConnection->bootstrapBySiteId((int) $site->id);

        $siteUrl = trim((string) $request->input('site_url', ''));
        if ($siteUrl !== '' && ! $this->siteUrlMatchesSite($site, $siteUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'site_url không khớp domain.',
            ], 403);
        }

        $raw = (string) $request->getContent();
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $result = $ingestor->receive(
            $site,
            $payload,
            $raw !== '' ? $raw : (json_encode($payload) ?: '{}'),
            $request->header('X-Omi-Timestamp'),
            $request->header('X-Omi-Nonce'),
            $request->header('X-Omi-Signature'),
            $request->header('X-Omi-Idempotency-Key'),
            $request->header('X-Omi-Operation-Id'),
        );

        // Compat: if auto-push disabled, fall back to synchronous snapshot ingest.
        if (($result['code'] ?? '') === 'auto_push_disabled' && $inbound !== null) {
            $result = $inbound->ingestSnapshotCallback($site, $payload);
        }

        $status = ($result['success'] ?? false) ? 200 : (($result['code'] ?? '') === 'signature_invalid' ? 401 : 422);

        return response()->json($result, $status);
    }

    private function resolveSiteByReadToken(string $token): ?Site
    {
        $site = Site::query()
            ->with('metas')
            ->whereHas('metas', static function ($query) use ($token): void {
                $query->where('meta_key', 'seo_read_token')
                    ->where('meta_value', $token);
            })
            ->first();

        if ($site === null) {
            return null;
        }

        if ((string) ($site->getMeta('seo_platform') ?? '') !== 'wordpress') {
            return null;
        }

        return $site;
    }

    private function siteUrlMatchesSite(Site $site, string $siteUrl): bool
    {
        $requestHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if ($requestHost === '') {
            return false;
        }

        return $this->normalizeHost($requestHost) === $this->normalizeHost($this->siteHost($site));
    }

    private function siteHost(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return strtolower((string) parse_url($domain, PHP_URL_HOST));
        }

        return strtolower(rtrim($domain, '/'));
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }
}
