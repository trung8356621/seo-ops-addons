<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Preflight;

use App\Models\Site;
use Omnichannel\Addons\Content\Services\Health\ArticleRequiredDataHealthAuditor;
use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use App\Support\RuntimeLogger;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Throwable;

/**
 * Lightweight Site Sync preflight: WP vs SEO Ops counts + required Article data health.
 * Does not start sync. Uses summary manifest only (no heavy reconcile).
 */
final class SiteSyncPreflightService
{
    public const RECOMMEND_NORMAL = 'normal_sync';

    public const RECOMMEND_FULL = 'full_sync';

    public const RECOMMEND_SYNCED = 'synced';

    public function __construct(
        private readonly ArticleRequiredDataHealthAuditor $auditor,
        private readonly WordPressSiteSyncClient $client,
        private readonly WordPressSiteSyncV3Client $v3Client,
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array{
     *   site_id: int,
     *   wordpress: array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string},
     *   seo_ops: array{total: int, post: int, page: int, product: int, other: int},
     *   count_delta: array{total: int, post: int, page: int, product: int},
     *   data_health: array<string, mixed>,
     *   recommendation: string,
     *   recommendation_label: string,
     *   recommendation_message: string,
     *   severity: string,
     *   technical: array<string, scalar|null>,
     *   last_sync: array{last_success_label: string|null, last_check_label: string|null}
     * }
     */
    public function evaluate(Site $site): array
    {
        $siteId = (int) $site->id;
        $dataHealth = $this->auditor->audit($siteId);
        $local = $dataHealth['by_content_type'];
        $localTotal = (int) $dataHealth['total'];
        $remote = $this->fetchRemoteCounts($site);

        $delta = [
            'total' => (int) $remote['total'] - $localTotal,
            'post' => (int) $remote['post'] - (int) $local['post'],
            'page' => (int) $remote['page'] - (int) $local['page'],
            'product' => (int) $remote['product'] - (int) $local['product'],
        ];

        $maxMissing = (int) ($dataHealth['max_missing'] ?? 0);
        $severity = (string) ($dataHealth['worst_severity'] ?? ArticleRequiredDataRegistry::SEVERITY_GREEN);
        $recommendation = $this->resolveRecommendation($maxMissing, $delta, $severity);

        return [
            'site_id' => $siteId,
            'wordpress' => $remote,
            'seo_ops' => [
                'total' => $localTotal,
                'post' => (int) $local['post'],
                'page' => (int) $local['page'],
                'product' => (int) $local['product'],
                'other' => (int) $local['other'],
            ],
            'count_delta' => $delta,
            'data_health' => $dataHealth,
            'recommendation' => $recommendation['recommendation'],
            'recommendation_label' => $recommendation['label'],
            'recommendation_message' => $recommendation['message'],
            'severity' => $severity,
            'technical' => $this->technicalDetails($site),
            'last_sync' => $this->lastSyncSummary($site),
        ];
    }

    /**
     * Local-only (no WP HTTP) — for Site Health card.
     *
     * @return array{
     *   site_id: int,
     *   seo_ops: array{total: int, post: int, page: int, product: int, other: int},
     *   data_health: array<string, mixed>,
     *   recommendation: string,
     *   recommendation_label: string,
     *   recommendation_message: string,
     *   severity: string,
     *   technical: array<string, scalar|null>,
     *   last_sync: array{last_success_label: string|null, last_check_label: string|null}
     * }
     */
    public function evaluateLocalOnly(Site $site): array
    {
        $siteId = (int) $site->id;
        $dataHealth = $this->auditor->audit($siteId);
        $local = $dataHealth['by_content_type'];
        $maxMissing = (int) ($dataHealth['max_missing'] ?? 0);
        $severity = (string) ($dataHealth['worst_severity'] ?? ArticleRequiredDataRegistry::SEVERITY_GREEN);
        $delta = ['total' => 0, 'post' => 0, 'page' => 0, 'product' => 0];
        $recommendation = $this->resolveRecommendation($maxMissing, $delta, $severity);

        return [
            'site_id' => $siteId,
            'seo_ops' => [
                'total' => (int) $dataHealth['total'],
                'post' => (int) $local['post'],
                'page' => (int) $local['page'],
                'product' => (int) $local['product'],
                'other' => (int) $local['other'],
            ],
            'data_health' => $dataHealth,
            'recommendation' => $recommendation['recommendation'],
            'recommendation_label' => $recommendation['label'],
            'recommendation_message' => $recommendation['message'],
            'severity' => $severity,
            'technical' => $this->technicalDetails($site),
            'last_sync' => $this->lastSyncSummary($site),
        ];
    }

    /**
     * @param  array{total: int, post: int, page: int, product: int}  $delta
     * @return array{recommendation: string, label: string, message: string}
     */
    private function resolveRecommendation(int $maxMissing, array $delta, string $severity): array
    {
        $hasCountSkew = collect($delta)->contains(fn (int $n): bool => $n !== 0);

        if ($maxMissing > ArticleRequiredDataRegistry::MISSING_YELLOW_MAX) {
            return [
                'recommendation' => self::RECOMMEND_FULL,
                'label' => 'Khuyến nghị: Đồng bộ toàn bộ',
                'message' => 'SEO Ops đang thiếu hoặc lệch dữ liệu so với WordPress. Đồng bộ toàn bộ sẽ kiểm tra lại inventory và sửa dữ liệu thiếu.',
            ];
        }

        if ($hasCountSkew && abs((int) $delta['total']) > 0) {
            $missingTypes = [];
            foreach (['post' => 'Post', 'page' => 'Page', 'product' => 'Product'] as $key => $label) {
                if ((int) ($delta[$key] ?? 0) !== 0) {
                    $missingTypes[] = $label;
                }
            }
            $typeHint = $missingTypes === []
                ? 'Tổng số đang lệch.'
                : 'Lệch theo type: '.implode(', ', $missingTypes).'.';

            return [
                'recommendation' => self::RECOMMEND_FULL,
                'label' => 'Khuyến nghị: Đồng bộ toàn bộ',
                'message' => 'SEO Ops đang thiếu hoặc lệch dữ liệu so với WordPress. '.$typeHint.' Đồng bộ toàn bộ sẽ kiểm tra lại inventory và sửa dữ liệu thiếu.',
            ];
        }

        if ($maxMissing > 0 || $severity !== ArticleRequiredDataRegistry::SEVERITY_GREEN) {
            return [
                'recommendation' => self::RECOMMEND_NORMAL,
                'label' => 'Khuyến nghị: Đồng bộ thay đổi',
                'message' => 'Có dữ liệu cấu trúc thiếu ở mức vừa phải. Đồng bộ thay đổi thường đủ để cập nhật.',
            ];
        }

        return [
            'recommendation' => self::RECOMMEND_SYNCED,
            'label' => 'Dữ liệu đang đồng bộ',
            'message' => 'Không cần full sync.',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function technicalDetails(Site $site): array
    {
        $details = [
            'run_id' => null,
            'manifest_revision' => null,
            'schema_version' => null,
            'cursor' => null,
            'capabilities' => null,
            'last_sync_generation' => null,
            'bridge_version' => null,
        ];

        try {
            if (! SiteSyncInfrastructure::tablesReady()) {
                return $details;
            }

            $run = SeoSiteSyncRun::query()
                ->where('site_id', (int) $site->id)
                ->orderByDesc('id')
                ->first();

            if ($run !== null) {
                $details['run_id'] = (int) $run->id;
                $details['cursor'] = $run->cursor !== null ? (string) $run->cursor : null;
                $meta = is_array($run->meta) ? $run->meta : [];
                $details['last_sync_generation'] = isset($meta['sync_generation'])
                    ? (string) $meta['sync_generation']
                    : (isset($meta['generation']) ? (string) $meta['generation'] : null);
                $details['manifest_revision'] = isset($meta['manifest_revision'])
                    ? (string) $meta['manifest_revision']
                    : null;
            }

            if (SiteSyncInfrastructure::hasTable('seo_site_capabilities')) {
                $cap = SeoSiteCapability::query()->where('site_id', (int) $site->id)->first();
                if ($cap !== null) {
                    $details['schema_version'] = (string) ($cap->schema_version ?? '');
                    $details['bridge_version'] = (string) ($cap->bridge_version ?? '');
                    $manifest = is_array($cap->manifest) ? $cap->manifest : [];
                    $caps = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
                    $details['capabilities'] = $caps === []
                        ? null
                        : implode(', ', array_keys($caps));
                    if ($details['manifest_revision'] === null && isset($manifest['revision'])) {
                        $details['manifest_revision'] = (string) $manifest['revision'];
                    }
                }
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.preflight_technical',
                'site_id' => (int) $site->id,
            ]);
        }

        return array_filter(
            $details,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @return array{last_success_label: string|null, last_check_label: string|null}
     */
    private function lastSyncSummary(Site $site): array
    {
        $empty = ['last_success_label' => null, 'last_check_label' => null];

        try {
            if (! SiteSyncInfrastructure::tablesReady()) {
                return $empty;
            }

            $success = SeoSiteSyncRun::query()
                ->where('site_id', (int) $site->id)
                ->whereIn('status', ['completed', 'completed_with_warnings'])
                ->orderByDesc('id')
                ->first();

            $latest = SeoSiteSyncRun::query()
                ->where('site_id', (int) $site->id)
                ->orderByDesc('id')
                ->first();

            return [
                'last_success_label' => $success !== null
                    ? (SystemDateTime::formatDateTime($success->finished_at ?? $success->updated_at) ?? null)
                    : null,
                'last_check_label' => $latest !== null
                    ? (SystemDateTime::formatDateTime($latest->updated_at ?? $latest->started_at) ?? null)
                    : null,
            ];
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.preflight_last_sync',
                'site_id' => (int) $site->id,
            ]);

            return $empty;
        }
    }

    /**
     * @return array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string}
     */
    private function fetchRemoteCounts(Site $site): array
    {
        $empty = [
            'total' => 0,
            'post' => 0,
            'page' => 0,
            'product' => 0,
            'other' => 0,
            'available' => false,
            'message' => '',
        ];

        if ($this->flags->protocolV3Enabled()) {
            $v3 = $this->fetchRemoteCountsViaV3($site);
            if ($v3['available']) {
                return $v3;
            }
        }

        return $this->fetchRemoteCountsViaV2Manifest($site, $empty);
    }

    /**
     * Prefer V3 discover by_content_type when protocol V3 is enabled.
     *
     * @return array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string}
     */
    private function fetchRemoteCountsViaV3(Site $site): array
    {
        $empty = [
            'total' => 0,
            'post' => 0,
            'page' => 0,
            'product' => 0,
            'other' => 0,
            'available' => false,
            'message' => '',
        ];

        try {
            $result = $this->v3Client->discover($site);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.preflight_v3_discover',
                'site_id' => (int) $site->id,
            ]);

            return array_merge($empty, ['message' => 'V3 discover thất bại: '.$e->getMessage()]);
        }

        if (! ($result['success'] ?? false)) {
            return array_merge($empty, [
                'message' => (string) ($result['message'] ?? 'V3 discover thất bại'),
            ]);
        }

        $discover = is_array($result['discover'] ?? null) ? $result['discover'] : [];
        $byType = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        $raw = is_array($discover['by_content_type'] ?? null) ? $discover['by_content_type'] : [];
        foreach ($raw as $type => $count) {
            $key = strtolower(trim((string) $type));
            if (! isset($byType[$key])) {
                $key = 'other';
            }
            $byType[$key] += (int) $count;
        }

        $total = (int) ($discover['total'] ?? 0);
        if ($total <= 0) {
            $total = array_sum($byType);
        }

        return [
            'total' => $total,
            'post' => $byType['post'],
            'page' => $byType['page'],
            'product' => $byType['product'],
            'other' => $byType['other'],
            'available' => true,
            'message' => '',
        ];
    }

    /**
     * Fallback: V2 lightweight manifest.
     *
     * @param  array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string}  $empty
     * @return array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string}
     */
    private function fetchRemoteCountsViaV2Manifest(Site $site, array $empty): array
    {
        try {
            $manifest = $this->client->fetchLightweightManifest($site, true);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.preflight_manifest',
                'site_id' => (int) $site->id,
            ]);

            return array_merge($empty, ['message' => 'Không đọc được WordPress manifest: '.$e->getMessage()]);
        }

        if (! ($manifest['success'] ?? false)) {
            return array_merge($empty, [
                'message' => (string) ($manifest['message'] ?? 'Lightweight manifest thất bại'),
            ]);
        }

        $byType = ['post' => 0, 'page' => 0, 'product' => 0, 'other' => 0];
        if (is_array($manifest['by_type'] ?? null)) {
            foreach ($manifest['by_type'] as $type => $count) {
                $key = strtolower(trim((string) $type));
                if (! isset($byType[$key])) {
                    $key = 'other';
                }
                $byType[$key] += (int) $count;
            }
        }

        $total = (int) ($manifest['totals']['entries'] ?? 0);
        if ($total <= 0) {
            $total = array_sum($byType);
        }

        return [
            'total' => $total,
            'post' => $byType['post'],
            'page' => $byType['page'],
            'product' => $byType['product'],
            'other' => $byType['other'],
            'available' => true,
            'message' => '',
        ];
    }
}
