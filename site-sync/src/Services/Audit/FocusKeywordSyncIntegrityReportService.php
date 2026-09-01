<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Audit;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Live (optional) + Laravel-local Focus Keyword integrity report for one site.
 * STOP before mutating importer — evidence only.
 */
final class FocusKeywordSyncIntegrityReportService
{
    public function __construct(
        private readonly FocusKeywordCoverageService $coverage,
        private readonly FocusKeywordSyncIntegrityAuditor $auditor,
        private readonly WordPressSiteSyncV3Client $v3Client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Site $site, bool $liveWp = false, int $maxLivePages = 80): array
    {
        $siteId = (int) $site->id;
        $filterUrl = app(\Omnichannel\Addons\Seo\Services\DomainOverviewService::class)
            ->buildArticlesFilterUrlForMissingFocusKeyword($siteId);
        $coverage = $this->coverage->forSite($siteId, $filterUrl);

        $maps = $this->laravelMaps($siteId);
        $wpFocus = [];
        $v3Focus = [];
        $liveMeta = [
            'attempted' => $liveWp,
            'success' => false,
            'message' => $liveWp ? '' : 'skipped (pass --live to fetch WP V3 records)',
            'pages' => 0,
            'records' => 0,
        ];

        if ($liveWp) {
            $live = $this->fetchLiveV3FocusMaps($site, $maxLivePages);
            $wpFocus = $live['wp_focus'];
            $v3Focus = $live['v3_focus'];
            $liveMeta = $live['meta'];
        } else {
            // Without live WP, treat Laravel provider relations as the only provider signal
            // and leave WP/V3 empty so set-diffs stay empty unless live is used.
            $liveMeta['message'] = 'Local-only: WP/V3 stages require --live fetch';
        }

        $audit = $this->auditor->audit(
            $wpFocus,
            $v3Focus,
            $maps['provider_by_wp'],
            $maps['effective_by_wp'],
            $maps['eligible_wp_ids'],
            $maps['missing_effective_wp_ids'],
        );

        return [
            'site_id' => $siteId,
            'domain' => (string) $site->domain,
            'coverage' => $coverage,
            'live' => $liveMeta,
            'audit' => $audit,
            'laravel_maps' => [
                'eligible_wp_count' => count($maps['eligible_wp_ids']),
                'provider_wp_count' => count(array_filter($maps['provider_by_wp'])),
                'effective_wp_count' => count(array_filter($maps['effective_by_wp'])),
                'missing_effective_wp_count' => count($maps['missing_effective_wp_ids']),
            ],
            'ui_142_semantics' => [
                'path' => 'Keyword workspace tab Focus → HasKeywordWorkspaceNavigation::getKeywordWorkspaceTabCounts()',
                'query' => 'KeywordDictionaryQuery::filtered(site, language, focus=true) → whereHas(mainArticles)',
                'meaning' => 'COUNT of Keyword rows that have ≥1 main_article_id (unique phrases with Focus Article), NOT article coverage',
            ],
        ];
    }

    /**
     * @return array{
     *   eligible_wp_ids: list<int>,
     *   missing_effective_wp_ids: list<int>,
     *   provider_by_wp: array<int, bool>,
     *   effective_by_wp: array<int, bool>
     * }
     */
    private function laravelMaps(int $siteId): array
    {
        $eligible = $this->coverage->query()->eligibleQuery($siteId)
            ->with(['wordpressLink', 'articleMetas'])
            ->get();

        $eligibleWpIds = [];
        $effectiveByWp = [];
        $articleIdToWp = [];
        $coveredArticleIds = [];

        foreach ($eligible as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpId <= 0) {
                continue;
            }
            $eligibleWpIds[] = $wpId;
            $articleIdToWp[(int) $article->id] = $wpId;
        }
        $eligibleWpIds = array_values(array_unique($eligibleWpIds));

        $covered = $this->coverage->query()->coveredEligibleQuery($siteId)
            ->with('wordpressLink')
            ->get();
        foreach ($covered as $article) {
            $coveredArticleIds[(int) $article->id] = true;
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpId > 0) {
                $effectiveByWp[$wpId] = true;
            }
        }

        $missingWp = [];
        foreach ($eligible as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpId <= 0) {
                continue;
            }
            if (! isset($coveredArticleIds[(int) $article->id])) {
                $missingWp[] = $wpId;
            }
        }
        $missingWp = array_values(array_unique($missingWp));

        $providerByWp = [];
        foreach ($eligibleWpIds as $wpId) {
            $providerByWp[$wpId] = false;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')
            && Schema::connection('omi_seo_ai')->hasTable('keywords')
            && $articleIdToWp !== []) {
            $rows = DB::connection('omi_seo_ai')
                ->table('keyword_meta as km')
                ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
                ->where('km.meta_key', KeywordMetaKey::MainArticleId->value)
                ->whereIn('km.meta_value', array_map('strval', array_keys($articleIdToWp)))
                ->where(function ($q): void {
                    $q->where('k.source', SiteSyncSchema::SOURCE_PROVIDER)
                        ->orWhereNull('k.source')
                        ->orWhere('k.source', '');
                })
                ->where(function ($q): void {
                    $q->where('k.source_locked', false)->orWhereNull('k.source_locked');
                })
                ->get(['km.meta_value as article_id', 'k.source']);

            foreach ($rows as $row) {
                $articleId = (int) $row->article_id;
                $wpId = $articleIdToWp[$articleId] ?? 0;
                if ($wpId > 0) {
                    $providerByWp[$wpId] = true;
                }
            }
        }

        return [
            'eligible_wp_ids' => $eligibleWpIds,
            'missing_effective_wp_ids' => $missingWp,
            'provider_by_wp' => $providerByWp,
            'effective_by_wp' => $effectiveByWp,
        ];
    }

    /**
     * @return array{
     *   wp_focus: array<int, list<string>>,
     *   v3_focus: array<int, list<string>>,
     *   meta: array<string, mixed>
     * }
     */
    private function fetchLiveV3FocusMaps(Site $site, int $maxPages): array
    {
        $wpFocus = [];
        $v3Focus = [];
        $pages = 0;
        $records = 0;
        $cursor = null;

        try {
            $discovered = $this->v3Client->discover($site);
            if (! ($discovered['success'] ?? false)) {
                return [
                    'wp_focus' => [],
                    'v3_focus' => [],
                    'meta' => [
                        'attempted' => true,
                        'success' => false,
                        'message' => 'v3 discover failed: '.(string) ($discovered['message'] ?? ''),
                        'pages' => 0,
                        'records' => 0,
                    ],
                ];
            }

            $discover = is_array($discovered['discover'] ?? null) ? $discovered['discover'] : [];
            $snapshotAt = (string) ($discover['snapshot_at'] ?? $discover['generated_at'] ?? '');
            $bounds = is_array($discover['snapshot_bounds'] ?? null) ? $discover['snapshot_bounds'] : [];
            if ($snapshotAt === '') {
                return [
                    'wp_focus' => [],
                    'v3_focus' => [],
                    'meta' => [
                        'attempted' => true,
                        'success' => false,
                        'message' => 'v3 discover missing snapshot_at',
                        'pages' => 0,
                        'records' => 0,
                    ],
                ];
            }

            while ($pages < $maxPages) {
                $body = [
                    'schema' => SiteSyncV3Schema::VERSION,
                    'resource' => SiteSyncV3Schema::RESOURCE_CONTENT,
                    'mode' => 'full',
                    'limit' => SiteSyncV3Schema::RECORDS_PER_JOB,
                    'cursor' => $cursor,
                    'snapshot_at' => $snapshotAt,
                    'snapshot_bounds' => [
                        'content_max_id' => (int) ($bounds['content_max_id'] ?? 0),
                        'term_max_id' => (int) ($bounds['term_max_id'] ?? 0),
                    ],
                    'sync_generation' => 0,
                ];

                $fetched = $this->v3Client->records($site, $body);
                if (! ($fetched['success'] ?? false)) {
                    return [
                        'wp_focus' => $wpFocus,
                        'v3_focus' => $v3Focus,
                        'meta' => [
                            'attempted' => true,
                            'success' => false,
                            'message' => (string) ($fetched['message'] ?? 'v3 records failed'),
                            'pages' => $pages,
                            'records' => $records,
                        ],
                    ];
                }

                $payload = is_array($fetched['records'] ?? null) ? $fetched['records'] : [];
                $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (! empty($item['wp_is_term'])) {
                        continue;
                    }
                    $wpId = (int) ($item['wp_id'] ?? $item['wordpress_id'] ?? 0);
                    if ($wpId <= 0) {
                        continue;
                    }
                    $records++;
                    $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
                    $phrases = $this->extractFocusPhrasesFromV3Item($item, $seo);
                    if ($phrases !== []) {
                        // Live V3 records are the bridge export of WP provider SEO.
                        // WP→V3 exporter loss is not separable on this stream alone.
                        $wpFocus[$wpId] = $phrases;
                        $v3Focus[$wpId] = $phrases;
                    }
                }

                $pages++;
                $hasMore = (bool) ($payload['has_more'] ?? false);
                $cursor = is_array($payload['cursor'] ?? null)
                    ? $payload['cursor']
                    : (is_array($payload['next_cursor'] ?? null) ? $payload['next_cursor'] : null);
                if (! $hasMore || $cursor === null || $items === []) {
                    break;
                }
            }

            return [
                'wp_focus' => $wpFocus,
                'v3_focus' => $v3Focus,
                'meta' => [
                    'attempted' => true,
                    'success' => true,
                    'message' => 'ok',
                    'pages' => $pages,
                    'records' => $records,
                    'snapshot_at' => $snapshotAt,
                    'note' => 'Live V3 content records = bridge-normalized focus_keywords from WP provider. WP raw vs exporter loss needs a separate Rank Math/Yoast meta probe.',
                ],
            ];
        } catch (Throwable $e) {
            RuntimeLogger::warning('focus_keyword_integrity_live_failed', [
                'site_id' => (int) $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'wp_focus' => $wpFocus,
                'v3_focus' => $v3Focus,
                'meta' => [
                    'attempted' => true,
                    'success' => false,
                    'message' => $e->getMessage(),
                    'pages' => $pages,
                    'records' => $records,
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $seo
     * @return list<string>
     */
    private function extractFocusPhrasesFromV3Item(array $item, array $seo): array
    {
        $out = [];
        $scalar = trim((string) ($item['focus_keyword'] ?? $seo['focus_keyword'] ?? ''));
        if ($scalar !== '') {
            $out[] = $scalar;
        }
        $list = is_array($seo['focus_keywords'] ?? null) ? $seo['focus_keywords'] : [];
        foreach ($list as $row) {
            if (is_string($row) && trim($row) !== '') {
                $out[] = trim($row);
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $phrase = trim((string) ($row['phrase'] ?? $row['keyword'] ?? ''));
            if ($phrase !== '') {
                $out[] = $phrase;
            }
        }

        return array_values(array_unique($out));
    }
}
