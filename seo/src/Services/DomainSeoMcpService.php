<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\Site;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Models\SeoFinding;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\LinkAnalysisRunService;
use Omnichannel\Addons\SiteSync\Services\LinkHealth\LinkHealthRunService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Legacy domain capability facade — NOT the future unified context API.
 *
 * Prefer canonical gateways:
 * - KeywordLandscapeGateway (domain.keyword_landscape)
 * - SiteContextGateway (site intelligence / indexability / link health)
 * - GscContextGateway (GSC)
 * - KeywordRelationshipGateway (keyword.relationship)
 *
 * Reads prepared snapshots/findings. Never crawls. No HTTP loopback.
 */
final class DomainSeoMcpService
{
    private const EMPTY_LANDSCAPE = [
        'clusters' => [],
        'keywords' => [],
        'hash' => '',
        'version' => 0,
    ];

    private const IDLE_PROGRESS = ['status' => 'idle'];

    public function __construct(
        private readonly SeoFindingSyncService $findings,
        private readonly LinkHealthRunService $linkHealth,
        private readonly LinkAnalysisRunService $linkAnalysis,
        private readonly KeywordGenerationContextBuilder $generationContext,
        private readonly KeywordTagResolver $keywordTags,
        private readonly KeywordTagQuery $keywordTagQuery,
        private readonly KeywordLandscapeGateway $landscapeGateway,
        private readonly \Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway $siteContextGateway,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message?: string, data: array<string, mixed>}
     */
    public function execute(int $siteId, string $capability, array $input): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['ok' => false, 'message' => 'Site not found.', 'data' => []];
        }

        if ($capability === 'domain.run_analysis') {
            return $this->runAnalysis($site, $input);
        }

        $this->findings->syncFromSnapshots($site);
        $freshness = $this->freshness($site);

        $data = match ($capability) {
            'domain.seo_brief' => $this->seoBrief($site, $freshness),
            'domain.keyword_overview' => $this->keywordOverview($site, $freshness),
            'domain.keyword_landscape' => $this->keywordLandscape($site, $freshness),
            'domain.keyword_gaps' => $this->keywordGaps($site, $freshness),
            'domain.keyword_cluster_detail' => $this->keywordClusterDetail($site, $freshness, $input),
            'domain.keyword_generation_context' => $this->keywordGenerationContext($site, $freshness, $input),
            'domain.keyword_opportunities' => $this->keywordGaps($site, $freshness),
            'domain.keyword_near_top' => $this->unavailableKeywords($freshness),
            'domain.rewrite_candidates',
            'domain.content_opportunities' => $this->unavailableContent($freshness),
            'domain.internal_link_opportunities' => $this->linkSnapshot($site, $freshness, 'opportunities'),
            'domain.orphan_pages' => $this->linkSnapshot($site, $freshness, 'orphan_pages'),
            'domain.broken_links' => $this->linkSnapshot($site, $freshness, 'broken_links'),
            'domain.indexability' => $this->indexability($site, $freshness),
            'domain.action_plan' => $this->actionPlan($site, $freshness),
            default => ['text' => 'Unsupported tool.'],
        };

        return ['ok' => true, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message?: string, data: array<string, mixed>}
     */
    private function runAnalysis(Site $site, array $input): array
    {
        $kind = (string) ($input['kind'] ?? $input['analysis'] ?? 'link_opportunities');
        $allowed = ['link_health', 'link_opportunities', 'keyword_refresh'];
        if (! in_array($kind, $allowed, true)) {
            return ['ok' => false, 'message' => 'Unsupported analysis kind.', 'data' => []];
        }

        $run = null;
        if ($kind === 'keyword_refresh') {
            // Keyword classification pipeline retired — accept no-op refresh.
        } elseif ($kind === 'link_health') {
            $run = $this->linkHealth->start($site);
        } else {
            $run = $this->linkAnalysis->start($site);
        }

        return [
            'ok' => true,
            'data' => [
                'queued' => true,
                'kind' => $kind,
                'run_id' => $run?->id,
                'generated_at' => now()->toIso8601String(),
                'stale' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function seoBrief(Site $site, array $freshness): array
    {
        $periodKey = now()->format('Y-m');
        $ctx = $this->siteContextGateway->forSite($site, $periodKey);
        $findings = is_array($ctx->summary['findings'] ?? null) ? $ctx->summary['findings'] : [];
        $top = is_array($findings['top'] ?? null) ? $findings['top'] : [];
        $lines = ['SEO Overview — '.(string) $site->domain, ''];
        $lines[] = 'Critical';
        $criticalHigh = array_values(array_filter(
            $top,
            static fn (mixed $row): bool => is_array($row)
                && in_array((string) ($row['severity'] ?? ''), ['critical', 'high'], true),
        ));
        if ($criticalHigh === []) {
            $lines[] = '- None';
        } else {
            foreach ($criticalHigh as $row) {
                $lines[] = '- '.(string) ($row['type'] ?? 'finding');
            }
        }
        $lines[] = '';
        $lines[] = 'Quick wins';
        $opps = 0;
        foreach (is_array($ctx->context['opportunities'] ?? null) ? $ctx->context['opportunities'] : [] as $opp) {
            if (is_array($opp) && ($opp['key'] ?? '') === 'internal_link_opportunity') {
                $opps = (int) ($opp['count'] ?? 0);
            }
        }
        $lines[] = $opps > 0
            ? '- '.$opps.' internal-link opportunities'
            : '- None prepared';
        $gaps = $this->gapStats(self::EMPTY_LANDSCAPE);
        $lines[] = '';
        $lines[] = 'Keyword Opportunities';
        $lines[] = '- '.$gaps['weak'].' weak clusters';
        $lines[] = '- '.$gaps['missing'].' missing topic directions';
        $lines[] = '- Keyword rank near-top unavailable (no reliable rank snapshot)';
        $lines[] = '- '.$gaps['saturated'].' saturated clusters should not expand';
        $lines[] = '';
        $lines[] = 'Data freshness';
        $lines[] = '- Link analysis: '.(string) ($freshness['link_analysis_human'] ?? 'unknown');
        $lines[] = '- Heartbeat: '.(string) ($freshness['heartbeat_human'] ?? 'unknown');
        $lines[] = '- Keyword classification: '.(string) ($freshness['classification_human'] ?? 'unknown');

        return [
            'text' => implode("\n", $lines),
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'] || $ctx->stale(),
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function unavailableKeywords(array $freshness): array
    {
        return [
            'text' => 'Keyword insight unavailable (insufficient target/rank evidence).',
            'status' => 'unavailable',
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function unavailableContent(array $freshness): array
    {
        return [
            'text' => 'Rewrite candidates are prepared on-demand in the editor; no full-site content scan.',
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function linkSnapshot(Site $site, array $freshness, string $field): array
    {
        $periodKey = now()->format('Y-m');
        $ctx = $this->siteContextGateway->forSite($site, $periodKey);
        $linkHealth = is_array($ctx->summary['link_health'] ?? null) ? $ctx->summary['link_health'] : [];

        return [
            'count' => (int) ($linkHealth[$field] ?? 0),
            'snapshot' => $linkHealth,
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'] || $ctx->stale(),
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function indexability(Site $site, array $freshness): array
    {
        $periodKey = now()->format('Y-m');
        $ctx = $this->siteContextGateway->forSite($site, $periodKey);
        $indexability = is_array($ctx->summary['indexability'] ?? null) ? $ctx->summary['indexability'] : [];
        $indexable = (int) ($indexability['indexable'] ?? 0);
        $noindex = (int) ($indexability['noindex'] ?? 0);

        return [
            'indexable' => $indexable,
            'noindex' => $noindex,
            'unexpected_noindex' => $noindex,
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'] || $ctx->stale(),
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function actionPlan(Site $site, array $freshness): array
    {
        $open = SeoFinding::query()->where('site_id', (int) $site->id)->where('status', SeoFinding::STATUS_OPEN)->get();
        $p1 = $open->whereIn('severity', ['critical', 'high']);
        $p2 = $open->whereIn('severity', ['medium', 'low']);
        $lines = [
            'Priority 1 — Critical fixes',
            $p1->isEmpty() ? '- None' : $p1->map(static fn (SeoFinding $f): string => '- '.$f->type)->implode("\n"),
            '',
            'Priority 2 — Quick wins',
            $p2->isEmpty() ? '- None' : $p2->map(static fn (SeoFinding $f): string => '- '.$f->type)->implode("\n"),
            '',
            'Priority 3 — Content opportunities',
            '- Use editor rewrite on-demand; no full-site AI scan.',
        ];
        $gaps = $this->gapStats(self::EMPTY_LANDSCAPE);
        $lines[] = '';
        $lines[] = 'Keyword intelligence';
        $lines[] = '- Priority 1: Create content for '.$gaps['missing'].' missing clusters';
        $lines[] = '- Priority 2: Expand intent gaps across weak clusters ('.$gaps['weak'].')';
        $lines[] = '- Priority 3: Do not generate more keywords for '.$gaps['saturated'].' saturated clusters';

        return [
            'text' => implode("\n", $lines),
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function freshness(Site $site): array
    {
        $link = SiteSyncSiteMeta::getJson($site, 'seo_link_analysis_snapshot') ?? [];
        $hb = SiteSyncSiteMeta::getJson($site, 'seo_wp_heartbeat') ?? [];
        $dict = SiteSyncSiteMeta::getJson($site, 'seo_keyword_dictionary') ?? [];
        $progress = SiteSyncSiteMeta::getJson($site, 'seo_keyword_intelligence_progress') ?? [];
        $linkAt = $link['last_analyzed_at'] ?? null;
        $hbAt = $hb['observed_at'] ?? null;
        $classAt = $progress['last_activity_at'] ?? $progress['finished_at'] ?? null;
        $dictAt = $dict['pushed_at'] ?? null;
        $stale = true;
        if (is_string($linkAt) && $linkAt !== '') {
            try {
                $stale = \Carbon\Carbon::parse($linkAt)->lt(now()->subHours(48));
            } catch (\Throwable) {
                $stale = true;
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'data_freshness' => [
                'link_analysis' => $linkAt,
                'heartbeat' => $hbAt,
                'classification' => $classAt,
                'dictionary' => $dictAt,
            ],
            'link_analysis_human' => SystemDateTime::formatRelative($linkAt) ?? 'unknown',
            'heartbeat_human' => SystemDateTime::formatRelative($hbAt) ?? 'unknown',
            'classification_human' => SystemDateTime::formatRelative(is_string($classAt) ? $classAt : null) ?? 'unknown',
            'dictionary_human' => SystemDateTime::formatRelative(is_string($dictAt) ? $dictAt : null) ?? 'unknown',
            'classification_status' => (string) ($progress['status'] ?? 'idle'),
            'stale' => $stale,
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function keywordOverview(Site $site, array $freshness): array
    {
        $kw = self::EMPTY_LANDSCAPE;
        $c = is_array($kw['classification'] ?? null) ? $kw['classification'] : [];
        $tagCounts = $this->operationalTagCounts((int) $site->id);
        $samples = $this->keywordTagSamples((int) $site->id);
        $lines = [
            'Keyword Overview — '.(string) $site->domain,
            '',
            'Raw keywords: '.(int) ($kw['raw_keywords'] ?? 0),
            'Usable SEO keywords: '.(int) ($kw['usable_seo_keywords'] ?? 0),
            'Canonical keywords: '.(int) ($kw['canonical_keywords'] ?? 0),
            'Clusters: '.(int) ($kw['cluster_count'] ?? 0),
            '',
            'Tags:',
            '- Focus: '.(int) ($tagCounts[KeywordTag::FOCUS] ?? 0),
            '- Cần xem lại: '.(int) ($tagCounts[KeywordTag::NEEDS_REVIEW] ?? 0),
            '- Loại SEO: '.(int) ($tagCounts[KeywordTag::SEO_EXCLUDED] ?? 0),
            '- Có link: '.(int) ($tagCounts[KeywordTag::HAS_LINK] ?? 0),
        ];

        return $this->keywordPayload($freshness, self::IDLE_PROGRESS, [
            'text' => implode("\n", $lines),
            'counts' => [
                'raw' => (int) ($kw['raw_keywords'] ?? 0),
                'usable' => (int) ($kw['usable_seo_keywords'] ?? 0),
                'canonical' => (int) ($kw['canonical_keywords'] ?? 0),
                'clusters' => (int) ($kw['cluster_count'] ?? 0),
            ],
            'tags' => $tagCounts,
            'keywords' => $samples,
            'classification' => $c,
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function keywordLandscape(Site $site, array $freshness): array
    {
        $landscape = $this->landscapeGateway->forSite((int) $site->id, true);
        $core = [];
        $weak = [];
        $missing = [];
        $topics = [];

        foreach ($landscape->topics as $topic) {
            $row = $this->landscapeTopicSummary($topic);
            $topics[] = $row;
            $coverage = $topic->coverage;
            if ($coverage === 'strong') {
                $core[] = $row;
            } elseif ($coverage === 'weak') {
                $weak[] = $row;
            } elseif ($coverage === 'unknown' || $topic->articleCount <= 0) {
                $missing[] = $row;
            }
        }

        $lines = [
            'Keyword Landscape — '.(string) $site->domain,
            '',
            'Topics: '.$landscape->topicCount(),
            'Core (strong): '.count($core),
            'Weak: '.count($weak),
            'Missing / thin: '.count($missing),
        ];

        return $this->keywordPayload($freshness, self::IDLE_PROGRESS, [
            'text' => implode("\n", $lines),
            'topics' => $topics,
            'core_topics' => $core,
            'saturated_topics' => [],
            'weak_topics' => $weak,
            'missing_topics' => $missing,
            'cluster_count' => $landscape->topicCount(),
            'source_updated_at' => $landscape->sourceUpdatedAt,
        ]);
    }

    /**
     * @return array{
     *   id: int,
     *   name: string,
     *   mcp: float,
     *   dna_count: int,
     *   article_count: int,
     *   coverage: string,
     *   dna: list<array{phrase: string, weight: int}>
     * }
     */
    private function landscapeTopicSummary(KeywordLandscapeTopic $topic): array
    {
        return $topic->toMcpContextRow();
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function keywordGaps(Site $site, array $freshness): array
    {
        $lines = ['Keyword Gaps — '.(string) $site->domain, ''];

        return $this->keywordPayload($freshness, self::IDLE_PROGRESS, [
            'text' => implode("\n", $lines),
            'gaps' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function keywordClusterDetail(Site $site, array $freshness, array $input): array
    {
        $limit = max(5, min(50, (int) ($input['limit'] ?? 12)));

        // Cluster landscape retired with KeywordClassificationService.
        return $this->keywordPayload($freshness, self::IDLE_PROGRESS, [
            'text' => 'Cluster not found.',
            'keywords' => Keyword::query()
                ->forSite((int) $site->id)
                ->with(KeywordTagResolver::tableEagerLoad())
                ->withCount(Keyword::linkMapCountRelations())
                ->orderBy('phrase')
                ->limit($limit)
                ->get()
                ->map(fn (Keyword $keyword): array => $this->keywordTags->mcpItem($keyword))
                ->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function keywordGenerationContext(Site $site, array $freshness, array $input): array
    {
        $context = $this->generationContext->build(self::EMPTY_LANDSCAPE, [
            'site' => (string) $site->domain,
            'max_topics' => (int) ($input['max_topics'] ?? 50),
            'max_exclusions' => (int) ($input['max_exclusions'] ?? 150),
        ]);

        return $this->keywordPayload($freshness, self::IDLE_PROGRESS, [
            'text' => $this->generationContext->toPromptBlock($context),
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $landscape
     * @return array{weak: int, missing: int, saturated: int}
     */
    private function gapStats(array $landscape): array
    {
        $weak = 0;
        $missing = 0;
        $saturated = 0;
        foreach ((array) ($landscape['clusters'] ?? []) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $cov = (string) ($cluster['coverage'] ?? '');
            if ($cov === 'weak') {
                $weak++;
            } elseif ($cov === 'missing') {
                $missing++;
            } elseif ($cov === 'saturated') {
                $saturated++;
            }
        }

        return ['weak' => $weak, 'missing' => $missing, 'saturated' => $saturated];
    }

    /**
     * @return array<string, int>
     */
    private function operationalTagCounts(int $siteId): array
    {
        $counts = [];
        foreach (KeywordTag::all() as $tag) {
            $query = Keyword::query()->forSite($siteId);
            $counts[$tag] = $this->keywordTagQuery->apply($query, [$tag])->count();
        }

        return $counts;
    }

    /**
     * @return list<array{phrase: string, tags: list<string>, cluster: string, tags_label: string}>
     */
    private function keywordTagSamples(int $siteId, int $limit = 12): array
    {
        return Keyword::query()
            ->forSite($siteId)
            ->with(KeywordTagResolver::tableEagerLoad())
            ->withCount(Keyword::linkMapCountRelations())
            ->orderBy('phrase')
            ->limit($limit)
            ->get()
            ->map(fn (Keyword $keyword): array => $this->keywordTags->mcpItem($keyword))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function keywordPayload(array $freshness, array $progress, array $data): array
    {
        $status = (string) ($progress['status'] ?? $freshness['classification_status'] ?? 'idle');
        $partial = in_array($status, ['queued', 'running'], true);

        return array_merge($data, [
            'generated_at' => $freshness['generated_at'],
            'classification_freshness' => $freshness['data_freshness']['classification'] ?? null,
            'dictionary_freshness' => $freshness['data_freshness']['dictionary'] ?? null,
            'stale' => $partial || (bool) ($freshness['stale'] ?? false),
            'partial' => $partial,
            'analysis_status' => $status !== '' ? $status : 'idle',
        ]);
    }
}
