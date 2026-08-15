<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SearchIntelligence\Jobs\ClassifyDirtyKeywordsJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\Seo\Models\SeoFinding;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\LinkAnalysisRunService;
use Omnichannel\Addons\SiteSync\Services\LinkHealth\LinkHealthRunService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * MCP reads prepared snapshots/findings. Never crawls.
 */
final class DomainSeoMcpService
{
    public function __construct(
        private readonly SeoFindingSyncService $findings,
        private readonly LinkHealthRunService $linkHealth,
        private readonly LinkAnalysisRunService $linkAnalysis,
        private readonly KeywordClassificationService $keywords,
        private readonly KeywordGenerationContextBuilder $generationContext,
        private readonly KeywordTagResolver $keywordTags,
        private readonly KeywordTagQuery $keywordTagQuery,
        private readonly \Omnichannel\Addons\Seo\Services\MonthlyMcp\DomainMonthlyIntelligenceService $monthlyIntelligence,
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
        if ($capability === 'domain.monthly_intelligence') {
            return $this->monthlyIntelligence->read((int) $site->id, $input);
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
            'domain.keyword_cannibalization' => $this->unavailableKeywords($freshness),
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

        if ($kind === 'keyword_refresh') {
            ClassifyDirtyKeywordsJob::dispatch((int) $site->id);
            $run = null;
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
        $open = SeoFinding::query()->where('site_id', (int) $site->id)->where('status', SeoFinding::STATUS_OPEN)->get();
        $critical = $open->where('severity', 'critical');
        $high = $open->where('severity', 'high');
        $lines = ['SEO Overview — '.(string) $site->domain, ''];
        $lines[] = 'Critical';
        if ($critical->isEmpty() && $high->isEmpty()) {
            $lines[] = '- None';
        } else {
            foreach ($critical->concat($high) as $finding) {
                $count = (int) (($finding->evidence['count'] ?? 0));
                $lines[] = '- '.$count.' '.$finding->type;
            }
        }
        $lines[] = '';
        $lines[] = 'Quick wins';
        $opps = $open->where('type', 'internal_link_opportunity')->first();
        $lines[] = $opps instanceof SeoFinding
            ? '- '.(int) ($opps->evidence['count'] ?? 0).' internal-link opportunities'
            : '- None prepared';
        $kw = $this->keywords->landscape((int) $site->id);
        $gaps = $this->gapStats($kw);
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
            'stale' => $freshness['stale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function unavailableKeywords(array $freshness): array
    {
        return [
            'text' => 'keyword_cannibalization = unavailable (insufficient target/rank evidence).',
            'keyword_cannibalization' => 'unavailable',
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
        $snap = SiteSyncSiteMeta::getJson($site, 'seo_link_analysis_snapshot') ?? [];

        return [
            'count' => (int) ($snap[$field] ?? 0),
            'snapshot' => $snap,
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'],
        ];
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function indexability(Site $site, array $freshness): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            return [
                'text' => 'Typed SEO snapshot unavailable.',
                'stale' => true,
                'generated_at' => $freshness['generated_at'],
                'data_freshness' => $freshness['data_freshness'],
            ];
        }

        $siteId = (int) $site->id;
        $indexable = SeoArticleProfile::query()->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))->where('is_indexable', true)->count();
        $noindex = SeoArticleProfile::query()->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))->where('is_indexable', false)->count();

        return [
            'indexable' => $indexable,
            'noindex' => $noindex,
            'unexpected_noindex' => $noindex,
            'generated_at' => $freshness['generated_at'],
            'data_freshness' => $freshness['data_freshness'],
            'stale' => $freshness['stale'],
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
        $kw = $this->keywords->landscape((int) $site->id);
        $gaps = $this->gapStats($kw);
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
        $progress = SiteSyncSiteMeta::getJson($site, KeywordClassificationService::META_PROGRESS) ?? [];
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
        $kw = $this->keywords->landscape((int) $site->id);
        $c = is_array($kw['classification'] ?? null) ? $kw['classification'] : [];
        $progress = $this->keywords->progress((int) $site->id);
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

        return $this->keywordPayload($freshness, $progress, [
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
        $kw = $this->keywords->landscape((int) $site->id);
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];
        $core = [];
        $saturated = [];
        $weak = [];
        $missing = [];
        foreach (array_slice($clusters, 0, 60) as $cluster) {
            $row = [
                'topic' => (string) ($cluster['primary'] ?? ''),
                'coverage' => (string) ($cluster['coverage'] ?? 'unknown'),
                'usable' => (int) ($cluster['usable_keyword_count'] ?? 0),
            ];
            $cov = $row['coverage'];
            if ($cov === 'saturated') {
                $saturated[] = $row;
            } elseif ($cov === 'missing') {
                $missing[] = $row;
            } elseif ($cov === 'weak') {
                $weak[] = $row;
            } else {
                $core[] = $row;
            }
        }
        $lines = ['Keyword Landscape — '.(string) $site->domain, '', 'Core topics'];
        foreach (array_slice($core, 0, 12) as $row) {
            $lines[] = '- '.$row['topic'];
        }
        $lines[] = '';
        $lines[] = 'Saturated topics';
        foreach (array_slice($saturated, 0, 8) as $row) {
            $lines[] = '- '.$row['topic'].' ('.$row['usable'].')';
        }
        $lines[] = '';
        $lines[] = 'Weak / missing topics';
        foreach (array_slice(array_merge($weak, $missing), 0, 12) as $row) {
            $lines[] = '- '.$row['topic'].' ['.$row['coverage'].']';
        }

        return $this->keywordPayload($freshness, $this->keywords->progress((int) $site->id), [
            'text' => implode("\n", $lines),
            'core_topics' => array_slice($core, 0, 20),
            'saturated_topics' => array_slice($saturated, 0, 20),
            'weak_topics' => array_slice($weak, 0, 20),
            'missing_topics' => array_slice($missing, 0, 20),
            'keyword_cannibalization' => 'unavailable',
            'cluster_count' => count($clusters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function keywordGaps(Site $site, array $freshness): array
    {
        $kw = $this->keywords->landscape((int) $site->id);
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];
        $gaps = [];
        foreach ($clusters as $cluster) {
            $coverage = (string) ($cluster['coverage'] ?? '');
            if (! in_array($coverage, ['missing', 'weak'], true) && ($cluster['intent_gaps'] ?? []) === []) {
                continue;
            }
            $gaps[] = [
                'cluster' => (string) ($cluster['primary'] ?? ''),
                'coverage' => $coverage,
                'reason' => $coverage === 'missing' ? 'no_target_content' : 'thin_coverage',
                'missing_directions' => $cluster['missing_directions'] ?? [],
                'intent_gaps' => $cluster['intent_gaps'] ?? [],
                'target_pages' => (int) ($cluster['target_pages'] ?? 0),
                'published' => (int) ($cluster['published'] ?? 0),
                'planned' => (int) ($cluster['planned'] ?? 0),
                'recommended_action' => (string) ($cluster['recommended_action'] ?? 'expand_keywords'),
            ];
        }
        $gaps = array_slice($gaps, 0, 40);
        $lines = ['Keyword Gaps — '.(string) $site->domain, ''];
        foreach (array_slice($gaps, 0, 15) as $gap) {
            $lines[] = '- '.$gap['cluster'].' ['.$gap['coverage'].'] → '.$gap['recommended_action'];
        }

        return $this->keywordPayload($freshness, $this->keywords->progress((int) $site->id), [
            'text' => implode("\n", $lines),
            'gaps' => $gaps,
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function keywordClusterDetail(Site $site, array $freshness, array $input): array
    {
        $key = trim((string) ($input['cluster'] ?? $input['cluster_key'] ?? $input['topic'] ?? ''));
        $limit = max(5, min(50, (int) ($input['limit'] ?? 12)));
        $kw = $this->keywords->landscape((int) $site->id);
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];
        $match = null;
        foreach ($clusters as $cluster) {
            $primary = (string) ($cluster['primary'] ?? '');
            $ck = (string) ($cluster['cluster'] ?? '');
            if ($key === '' || $key === $primary || $key === $ck || str_contains($primary, $key)) {
                $match = $cluster;
                if ($key !== '') {
                    break;
                }
            }
        }
        if (! is_array($match)) {
            return $this->keywordPayload($freshness, $this->keywords->progress((int) $site->id), [
                'text' => 'Cluster not found.',
            ]);
        }
        $lines = [
            'Cluster: '.(string) ($match['primary'] ?? ''),
            '',
            'Primary',
            (string) ($match['primary'] ?? ''),
            '',
            'Representative variants',
        ];
        foreach (array_slice((array) ($match['representative_variants'] ?? []), 0, $limit) as $v) {
            $lines[] = '- '.$v;
        }
        $lines[] = '';
        $lines[] = 'Queries';
        foreach ((array) ($match['queries'] ?? []) as $q) {
            $lines[] = '- '.$q;
        }
        $lines[] = '';
        $lines[] = 'Intent coverage: '.implode(', ', (array) ($match['intent_coverage'] ?? []));
        $lines[] = 'Target pages: '.(int) ($match['target_pages'] ?? 0);
        $lines[] = 'Coverage: '.(string) ($match['coverage'] ?? 'unknown');

        return $this->keywordPayload($freshness, $this->keywords->progress((int) $site->id), [
            'text' => implode("\n", $lines),
            'cluster' => $match,
            'keywords' => Keyword::query()
                ->forSite((int) $site->id)
                ->with(KeywordTagResolver::tableEagerLoad())
                ->withCount(Keyword::linkMapCountRelations())
                ->whereHas(
                    'seoClassification',
                    static function ($query) use ($match): void {
                        $ck = (string) ($match['cluster'] ?? '');
                        if ($ck !== '') {
                            $query->where('cluster_key', $ck);
                        }
                    },
                )
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
        $kw = $this->keywords->landscape((int) $site->id);
        $context = $this->generationContext->build($kw, [
            'site' => (string) $site->domain,
            'max_topics' => (int) ($input['max_topics'] ?? 50),
            'max_exclusions' => (int) ($input['max_exclusions'] ?? 150),
        ]);

        return $this->keywordPayload($freshness, $this->keywords->progress((int) $site->id), [
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
