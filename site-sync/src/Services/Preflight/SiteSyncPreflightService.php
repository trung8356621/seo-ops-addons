<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Preflight;

use App\Models\Site;
use Omnichannel\Addons\Content\Services\Health\ArticleRequiredDataHealthAuditor;
use Omnichannel\Addons\Content\Support\ArticleRequiredDataRegistry;
use Omnichannel\Addons\Content\Support\NativeContentTypeMapper;
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
        private readonly SiteSyncPreflightContentComparison $comparison = new SiteSyncPreflightContentComparison(),
    ) {}

    /**
     * @param  string|null  $language  Canonical Polylang language scope (null/'' = unscoped)
     * @return array{
     *   site_id: int,
     *   language: string|null,
     *   wordpress: array{
     *     total: int, post: int, page: int, product: int, other: int,
     *     available: bool, message: string, authoritative: bool, source: string
     *   },
     *   seo_ops: array{total: int, post: int, page: int, product: int, other: int},
     *   count_delta: array{total: int, post: int, page: int, product: int},
     *   count_comparison_authoritative: bool,
     *   data_health: array<string, mixed>,
     *   recommendation: string,
     *   recommendation_label: string,
     *   recommendation_message: string,
     *   severity: string,
     *   technical: array<string, scalar|null>,
     *   last_sync: array{last_success_label: string|null, last_check_label: string|null}
     * }
     */
    public function evaluate(Site $site, ?string $language = null): array
    {
        $siteId = (int) $site->id;
        $language = $language !== null ? trim($language) : '';
        $langArg = $language !== '' ? $language : null;
        // Data health = broad SEO inventory (may include local-only). Separate from comparison.
        $dataHealth = $this->auditor->audit($siteId, $langArg);
        // WP↔local membership comparison = WP-backed comparable content only (no terms / local-only).
        $local = $this->comparison->countLocal($siteId, $langArg);
        $remote = $this->fetchRemoteCounts($site, $langArg);
        $countAuthoritative = (bool) ($remote['authoritative'] ?? false);

        $delta = [
            'total' => (int) $remote['total'] - (int) $local['total'],
            'post' => (int) $remote['post'] - (int) $local['post'],
            'page' => (int) $remote['page'] - (int) $local['page'],
            'product' => (int) $remote['product'] - (int) $local['product'],
        ];

        $maxMissing = (int) ($dataHealth['max_missing'] ?? 0);
        $severity = (string) ($dataHealth['worst_severity'] ?? ArticleRequiredDataRegistry::SEVERITY_GREEN);
        $recommendation = $this->resolveRecommendation($maxMissing, $delta, $severity, $countAuthoritative);

        return [
            'site_id' => $siteId,
            'language' => $langArg,
            'wordpress' => $remote,
            'seo_ops' => [
                'total' => (int) $local['total'],
                'post' => (int) $local['post'],
                'page' => (int) $local['page'],
                'product' => (int) $local['product'],
                'other' => (int) $local['other'],
            ],
            'count_delta' => $delta,
            'count_comparison_authoritative' => $countAuthoritative,
            'data_health' => $dataHealth,
            'recommendation' => $recommendation['recommendation'],
            'recommendation_label' => $recommendation['label'],
            'recommendation_message' => $recommendation['message'],
            'severity' => $severity,
            'technical' => $this->technicalDetails($site),
            'last_sync' => $this->lastSyncSummary($site, $langArg),
        ];
    }

    /**
     * Local-only (no WP HTTP) — for Site Health card / language tab snapshots.
     *
     * @return array{
     *   site_id: int,
     *   language: string|null,
     *   seo_ops: array{total: int, post: int, page: int, product: int, other: int},
     *   data_health: array<string, mixed>,
     *   recommendation: string,
     *   recommendation_label: string,
     *   recommendation_message: string,
     *   severity: string,
     *   technical: array<string, scalar|null>,
     *   last_sync: array{last_success_label: string|null, last_check_label: string|null},
     *   remote_fetched: false
     * }
     */
    public function evaluateLocalOnly(Site $site, ?string $language = null): array
    {
        $siteId = (int) $site->id;
        $language = $language !== null ? trim($language) : '';
        $langArg = $language !== '' ? $language : null;
        $dataHealth = $this->auditor->audit($siteId, $langArg);
        // Language-scoped panels use WP-backed comparable membership.
        // Unscoped Site Health card keeps SEO inventory totals (may include local-only).
        $local = $langArg !== null
            ? $this->comparison->countLocal($siteId, $langArg)
            : [
                'total' => (int) ($dataHealth['total'] ?? 0),
                'post' => (int) ($dataHealth['by_content_type']['post'] ?? 0),
                'page' => (int) ($dataHealth['by_content_type']['page'] ?? 0),
                'product' => (int) ($dataHealth['by_content_type']['product'] ?? 0),
                'other' => (int) ($dataHealth['by_content_type']['other'] ?? 0),
            ];
        $maxMissing = (int) ($dataHealth['max_missing'] ?? 0);
        $severity = (string) ($dataHealth['worst_severity'] ?? ArticleRequiredDataRegistry::SEVERITY_GREEN);
        $delta = ['total' => 0, 'post' => 0, 'page' => 0, 'product' => 0];
        // Local-only: no WP count comparison — treat count side as non-authoritative.
        $recommendation = $this->resolveRecommendation($maxMissing, $delta, $severity, false);

        return [
            'site_id' => $siteId,
            'language' => $langArg,
            'seo_ops' => [
                'total' => (int) $local['total'],
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
            'last_sync' => $this->lastSyncSummary($site, $langArg),
            'remote_fetched' => false,
        ];
    }

    /**
     * @param  array{total: int, post: int, page: int, product: int}  $delta
     * @return array{recommendation: string, label: string, message: string}
     */
    private function resolveRecommendation(
        int $maxMissing,
        array $delta,
        string $severity,
        bool $countAuthoritative,
    ): array {
        $hasCountSkew = collect($delta)->contains(fn (int $n): bool => $n !== 0);

        if ($maxMissing > ArticleRequiredDataRegistry::MISSING_YELLOW_MAX) {
            return [
                'recommendation' => self::RECOMMEND_FULL,
                'label' => 'Khuyến nghị: Đồng bộ toàn bộ',
                'message' => 'SEO Ops đang thiếu hoặc lệch dữ liệu so với WordPress. Đồng bộ toàn bộ sẽ kiểm tra lại inventory và sửa dữ liệu thiếu.',
            ];
        }

        // Only authoritative native-post-type comparison may drive full_sync from count delta.
        // Old-plugin by_content_type fallback is approximate (system CPT / aggregate skew) —
        // never treat that mismatch as a real "sync required" signal.
        if ($countAuthoritative && $hasCountSkew && abs((int) $delta['total']) > 0) {
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

        $syncedMessage = 'Không cần full sync.';
        if (! $countAuthoritative && $hasCountSkew && abs((int) $delta['total']) > 0) {
            $syncedMessage = 'So sánh số lượng WordPress chưa authoritative (plugin thiếu by_native_post_type). '
                .'Không khuyến nghị full sync chỉ vì lệch đếm ước lượng — nâng cấp bridge để Preflight chính xác.';
        }

        return [
            'recommendation' => self::RECOMMEND_SYNCED,
            'label' => 'Dữ liệu đang đồng bộ',
            'message' => $syncedMessage,
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
    private function lastSyncSummary(Site $site, ?string $language = null): array
    {
        $empty = ['last_success_label' => null, 'last_check_label' => null];
        $language = $language !== null ? trim($language) : '';

        try {
            if (! SiteSyncInfrastructure::tablesReady()) {
                return $empty;
            }

            $successQuery = SeoSiteSyncRun::query()
                ->where('site_id', (int) $site->id)
                ->whereIn('status', ['completed', 'completed_with_warnings'])
                ->orderByDesc('id');
            $latestQuery = SeoSiteSyncRun::query()
                ->where('site_id', (int) $site->id)
                ->orderByDesc('id');

            $success = null;
            $latest = null;
            foreach ($successQuery->limit(40)->get() as $run) {
                if ($language !== '' && ! $this->runMatchesLanguage($run, $language)) {
                    continue;
                }
                $success = $run;
                break;
            }
            foreach ($latestQuery->limit(40)->get() as $run) {
                if ($language !== '' && ! $this->runMatchesLanguage($run, $language)) {
                    continue;
                }
                $latest = $run;
                break;
            }

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

    private function runMatchesLanguage(SeoSiteSyncRun $run, string $language): bool
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $runLang = trim((string) ($meta['language_scope'] ?? ''));
        if ($runLang === '') {
            // Legacy unscoped runs count for primary/unscoped readers only when no scoped runs exist.
            return true;
        }

        return $runLang === $language;
    }

    /**
     * @return array{total: int, post: int, page: int, product: int, other: int, available: bool, message: string}
     */
    private function fetchRemoteCounts(Site $site, ?string $language = null): array
    {
        $empty = [
            'total' => 0,
            'post' => 0,
            'page' => 0,
            'product' => 0,
            'other' => 0,
            'available' => false,
            'message' => '',
            'authoritative' => false,
            'source' => '',
        ];

        if ($this->flags->protocolV3Enabled()) {
            $v3 = $this->fetchRemoteCountsViaV3($site, $language);
            if ($v3['available']) {
                return $v3;
            }
        }

        return $this->fetchRemoteCountsViaV2Manifest($site, $empty);
    }

    /**
     * Prefer V3 discover; authoritative only when by_native_post_type is present.
     *
     * @return array{
     *   total: int, post: int, page: int, product: int, other: int,
     *   available: bool, message: string, authoritative: bool, source: string
     * }
     */
    private function fetchRemoteCountsViaV3(Site $site, ?string $language = null): array
    {
        $empty = [
            'total' => 0,
            'post' => 0,
            'page' => 0,
            'product' => 0,
            'other' => 0,
            'available' => false,
            'message' => '',
            'authoritative' => false,
            'source' => '',
        ];

        try {
            $query = [];
            $language = $language !== null ? trim($language) : '';
            if ($language !== '') {
                $query['language'] = $language;
            }
            $result = $this->v3Client->discover($site, $query);
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
        $siteMap = NativeContentTypeMapper::siteMap($site);
        $normalized = $this->comparison->normalizeRemoteDiscover(
            $discover,
            $siteMap !== [] ? $siteMap : null,
        );

        $message = '';
        if (! $normalized['authoritative']) {
            $message = 'Plugin WordPress chưa gửi by_native_post_type — so sánh số lượng không authoritative. '
                .'Nâng cấp bridge để Preflight đếm đối xứng (không khuyến nghị sync chỉ vì lệch ước lượng).';
        }

        return [
            'total' => $normalized['total'],
            'post' => $normalized['post'],
            'page' => $normalized['page'],
            'product' => $normalized['product'],
            'other' => $normalized['other'],
            'available' => true,
            'message' => $message,
            'authoritative' => (bool) $normalized['authoritative'],
            'source' => (string) $normalized['source'],
        ];
    }

    /**
     * Fallback: V2 lightweight manifest (always non-authoritative for count comparison).
     *
     * @param  array{
     *   total: int, post: int, page: int, product: int, other: int,
     *   available: bool, message: string, authoritative: bool, source: string
     * }  $empty
     * @return array{
     *   total: int, post: int, page: int, product: int, other: int,
     *   available: bool, message: string, authoritative: bool, source: string
     * }
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

        // V2 fallback: total = sum of type rows only (never inflate with non-content).
        // Not authoritative — same rolling-deploy rule as old V3 without by_native_post_type.
        $normalized = $this->comparison->fromContentTypeCounts($byType);

        return [
            'total' => $normalized['total'],
            'post' => $normalized['post'],
            'page' => $normalized['page'],
            'product' => $normalized['product'],
            'other' => $normalized['other'],
            'available' => true,
            'message' => 'Manifest V2 không có by_native_post_type — so sánh số lượng không authoritative.',
            'authoritative' => false,
            'source' => SiteSyncPreflightContentComparison::SOURCE_CONTENT_TYPE_FALLBACK,
        ];
    }
}
