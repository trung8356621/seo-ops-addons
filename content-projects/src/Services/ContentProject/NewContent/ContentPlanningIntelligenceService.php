<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Throwable;

/**
 * Canonical Planning Intelligence — deterministic read/context only (0 AI calls).
 *
 * @phpstan-type PlanningContext array{
 *   site: array{id: int, domain: string, primary_language: string},
 *   coverage: array{covered: int, weakly_covered: int, uncovered: int, unknown: int},
     *   principal_keywords: list<array{keyword_id: int, phrase: string, score: float, coverage: string, source: string}>,
 *   clusters: list<array{
 *     label: string,
 *     keyword_count: int,
 *     article_count: int,
 *     coverage: string,
 *     core_articles?: int,
 *     covered_dna?: list<array{value: string, count?: int, articles?: int, coverage?: string}>
 *   }>,
 *   missing_directions: list<array{topic: string, signal: string}>,
 *   existing_topics: list<array{title: string, coverage: string}>,
 *   planned_topics: list<array{keyword: string, title: string, type: string, fingerprint: string}>,
 *   rejected_topics: list<array{keyword: string, title: string, fingerprint: string}>,
 *   mcp_signals: list<array{type: string, label: string}>,
 *   gsc_signals: list<array{type: string, label: string, query?: string, lane?: string}>,
 *   mcp_period: string|null,
 *   covered_keyword_norms: array<string, true>, // published/covered only — not weakly_covered / bare KI
 *   planned_fingerprints: array<string, true>,
 *   rejected_fingerprints: array<string, true>,
 *   diagnostics: array{
 *     principal_keywords_count: int,
 *     cluster_count: int,
 *     missing_direction_count: int,
 *     mcp_period: string|null,
 *     covered_keyword_count: int
 *   }
 * }
 */
final class ContentPlanningIntelligenceService
{
    public function __construct(
        private readonly ?KeywordLandscapeGateway $landscape = null,
        private readonly ?GscContextGateway $gsc = null,
    ) {}

    /**
     * Compact Agent/UI summary (no writes).
     *
     * @return array<string, mixed>
     */
    public function summarize(SeoProject $project, Site $site, string $primaryLanguage = ''): array
    {
        $ctx = $this->build($project, $site, [
            'use_site_context' => true,
            'use_keyword_intelligence' => true,
            'use_mcp_context' => true,
        ], $primaryLanguage);

        return [
            'principal_keyword_count' => $ctx['diagnostics']['principal_keywords_count'],
            'cluster_count' => $ctx['diagnostics']['cluster_count'],
            'missing_direction_count' => $ctx['diagnostics']['missing_direction_count'],
            'covered_clusters' => array_values(array_filter(
                $ctx['clusters'],
                static fn (array $c): bool => in_array($c['coverage'], ['strong', 'medium'], true),
            )),
            'weak_clusters' => array_values(array_filter(
                $ctx['clusters'],
                static fn (array $c): bool => $c['coverage'] === 'weak',
            )),
            'missing_directions' => $ctx['missing_directions'],
            'mcp_period' => $ctx['mcp_period'],
            'coverage' => $ctx['coverage'],
            'gsc_signal_count' => count($ctx['gsc_signals']),
            'gsc_signals' => array_slice($ctx['gsc_signals'], 0, 20),
            'improvement_signals' => array_values(array_filter(
                $ctx['gsc_signals'],
                static fn (array $s): bool => ($s['lane'] ?? '') === 'improvement_signal',
            )),
            'new_content_gsc_signals' => array_values(array_filter(
                $ctx['gsc_signals'],
                static fn (array $s): bool => ($s['lane'] ?? '') === 'new_content_signal',
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return PlanningContext
     */
    public function build(SeoProject $project, Site $site, array $options, string $primaryLanguage): array
    {
        $options = NewContentSuggestionOptions::normalize($options);
        $siteId = (int) $site->getKey();
        $domain = trim((string) ($site->domain ?? ''));

        $planned = $this->plannedTopics($project);
        $rejected = $this->rejectedTopics($project);
        $plannedFingerprints = [];
        foreach ($planned as $row) {
            $plannedFingerprints[$row['fingerprint']] = true;
        }
        $rejectedFingerprints = [];
        foreach ($rejected as $row) {
            $rejectedFingerprints[$row['fingerprint']] = true;
        }

        $principal = [];
        $coveredNorms = [];
        $coverageCounts = ['covered' => 0, 'weakly_covered' => 0, 'uncovered' => 0, 'unknown' => 0];
        $clusters = [];
        $missing = [];
        $existingTopics = [];
        $mcpSignals = [];
        $gscSignals = [];
        $mcpPeriod = null;

        if ($options['use_keyword_intelligence'] && $siteId > 0) {
            $principal = $this->principalKeywords($siteId);
            [$coveredNorms, $coverageCounts] = $this->annotateCoverage($principal, $siteId);
            $clusters = $this->clusterSummaries($siteId);
            foreach ($clusters as $cluster) {
                if ($cluster['coverage'] === 'weak' && count($missing) < ContentPlanningIntelligenceCaps::MISSING_DIRECTIONS) {
                    $missing[] = [
                        'topic' => $cluster['label'],
                        'signal' => 'cluster_gap',
                    ];
                }
            }
        }

        if ($options['use_site_context'] && $siteId > 0) {
            $existingTopics = $this->existingPublishedTitles($siteId);
        }

        if ($options['use_mcp_context'] && $siteId > 0) {
            [$mcpSignals, $mcpPeriod, $mcpMissing] = $this->mcpSignals($siteId);
            foreach ($mcpMissing as $dir) {
                if (count($missing) >= ContentPlanningIntelligenceCaps::MISSING_DIRECTIONS) {
                    break;
                }
                $missing[] = $dir;
            }
            [$gscSignals, $gscPeriod, $gscMissing] = $this->gscSignals($siteId, $mcpPeriod);
            if ($mcpPeriod === null && $gscPeriod !== null) {
                $mcpPeriod = $gscPeriod;
            }
            foreach ($gscMissing as $dir) {
                if (count($missing) >= ContentPlanningIntelligenceCaps::MISSING_DIRECTIONS) {
                    break;
                }
                $missing[] = $dir;
            }
            $this->applyGscCoverageToPrincipal($principal, $coveredNorms, $coverageCounts, $gscSignals);
        }

        // Active Draft Create items are excluded via planned_fingerprints / planned_keyword_norms
        // in the dedup layer — not via covered_keyword_norms (content coverage SSOT).

        return [
            'site' => [
                'id' => $siteId,
                'domain' => $domain,
                'primary_language' => $primaryLanguage,
            ],
            'coverage' => $coverageCounts,
            'principal_keywords' => $principal,
            'clusters' => $clusters,
            'missing_directions' => array_slice($missing, 0, ContentPlanningIntelligenceCaps::MISSING_DIRECTIONS),
            'existing_topics' => $existingTopics,
            'planned_topics' => $planned,
            'rejected_topics' => $rejected,
            'mcp_signals' => $mcpSignals,
            'gsc_signals' => $gscSignals,
            'mcp_period' => $mcpPeriod,
            'covered_keyword_norms' => $coveredNorms,
            'planned_fingerprints' => $plannedFingerprints,
            'rejected_fingerprints' => $rejectedFingerprints,
            'diagnostics' => [
                'principal_keywords_count' => count($principal),
                'cluster_count' => count($clusters),
                'missing_direction_count' => count($missing),
                'mcp_period' => $mcpPeriod,
                'covered_keyword_count' => count($coveredNorms),
                'gsc_signal_count' => count($gscSignals),
            ],
        ];
    }

    /**
     * Render compact brief for keyword.discovery.structured (still one AI call later).
     * Content-type aware: Post keeps article planning semantics; Product requests product-page planning fields.
     *
     * @param  PlanningContext  $ctx
     * @param  array<string, mixed>  $options
     */
    public function renderBrief(array $ctx, array $options): string
    {
        $options = NewContentSuggestionOptions::normalize($options);
        $isProduct = $options['content_type'] === NewContentSuggestionOptions::CONTENT_TYPE_PRODUCT;

        $lines = $isProduct
            ? $this->productPlanningBriefHeader($ctx, $options)
            : $this->postPlanningBriefHeader($ctx, $options);

        $noteItems = is_array($options['note_items'] ?? null) ? $options['note_items'] : [];
        if ($noteItems !== []) {
            $policy = new NewContentAutoDnaPolicy;
            foreach ($policy->instructionLines((int) $options['quantity'], $noteItems) as $policyLine) {
                $lines[] = $policyLine;
            }
            foreach ((new AuditNotePromptSectionBuilder)->lines($noteItems) as $noteLine) {
                $lines[] = $noteLine;
            }
        }

        $notes = trim((string) ($options['notes'] ?? ''));
        if ($notes === '') {
            $notes = trim((string) ($options['focus'] ?? ''));
        }
        if ($notes !== '') {
            $lines[] = 'Additional user instructions:';
            $lines[] = $notes;
        }
        $taxonomy = trim((string) ($options['taxonomy'] ?? ''));
        if ($taxonomy !== '') {
            $lines[] = 'Taxonomy direction (planning only, do not create terms): '.$taxonomy;
        }

        if ($ctx['missing_directions'] !== []) {
            $lines[] = 'Priority gaps / missing directions:';
            foreach (array_slice($ctx['missing_directions'], 0, 12) as $row) {
                $lines[] = '- '.$row['topic'].' ('.$row['signal'].')';
            }
        }

        $coverageUnit = $isProduct ? 'published pages' : 'articles';
        if ($ctx['clusters'] !== []) {
            $weak = array_values(array_filter($ctx['clusters'], static fn (array $c): bool => $c['coverage'] === 'weak'));
            if ($weak !== []) {
                $lines[] = 'Weak clusters (prefer filling, not duplicating strong clusters):';
                foreach (array_slice($weak, 0, 10) as $c) {
                    $lines[] = '- '.$c['label'].' · keywords '.$c['keyword_count'].' · '.$coverageUnit.' '.$c['article_count'];
                }
            }
            $strong = array_values(array_filter($ctx['clusters'], static fn (array $c): bool => $c['coverage'] === 'strong'));
            if ($strong !== []) {
                $lines[] = 'Strong coverage (avoid new create duplicates):';
                foreach (array_slice($strong, 0, 8) as $c) {
                    $lines[] = '- '.$c['label'].' · '.$coverageUnit.' '.$c['article_count'];
                }
            }
        }

        if ($ctx['principal_keywords'] !== []) {
            $lines[] = 'Principal SEO keywords (planning signals; uncovered preferred):';
            $i = 0;
            foreach ($ctx['principal_keywords'] as $kw) {
                if ($i >= 25) {
                    break;
                }
                if (($kw['coverage'] ?? '') === 'covered') {
                    continue;
                }
                $lines[] = '- '.$kw['phrase'].' ['.$kw['coverage'].']';
                $i++;
            }
        }

        if ($ctx['planned_topics'] !== []) {
            $lines[] = 'Already planned keywords in this Draft (do not repeat):';
            foreach (array_slice($ctx['planned_topics'], 0, 20) as $row) {
                $kw = trim((string) ($row['keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }
                $lines[] = '- '.$kw;
            }
        }

        if ($ctx['rejected_topics'] !== []) {
            $lines[] = 'Rejected keywords in this Draft (do not suggest again):';
            foreach (array_slice($ctx['rejected_topics'], 0, 15) as $row) {
                $kw = trim((string) ($row['keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }
                $lines[] = '- '.$kw;
            }
        }

        if ($ctx['existing_topics'] !== []) {
            $lines[] = $isProduct
                ? 'Existing published product/content titles (coverage evidence):'
                : 'Existing published article titles (coverage evidence):';
            foreach (array_slice($ctx['existing_topics'], 0, 20) as $row) {
                $lines[] = '- '.$row['title'];
            }
        }

        if ($ctx['mcp_signals'] !== []) {
            $period = $ctx['mcp_period'] ?? 'unknown';
            $lines[] = 'MCP signals ('.$period.'):';
            foreach (array_slice($ctx['mcp_signals'], 0, 12) as $sig) {
                $lines[] = '- ['.$sig['type'].'] '.$sig['label'];
            }
        }

        if (($ctx['gsc_signals'] ?? []) !== []) {
            $period = $ctx['mcp_period'] ?? 'unknown';
            $lines[] = 'GSC signals ('.$period.'; Search Console impressions ≠ search volume):';
            foreach (array_slice($ctx['gsc_signals'], 0, 12) as $sig) {
                $lane = (string) ($sig['lane'] ?? '');
                $lines[] = '- ['.$sig['type'].']'.($lane !== '' ? ' {'.$lane.'}' : '').' '.$sig['label'];
            }
        }

        return implode("\n", $lines)."\n".NewContentSuggestionStructuredResult::outputContractFooter(
            (string) $options['content_type'],
            (int) $options['quantity'],
            (new NewContentClusterAttributionValidator)->allowedClusterRefs($noteItems),
        );
    }

    /**
     * @param  PlanningContext  $ctx
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function postPlanningBriefHeader(array $ctx, array $options): array
    {
        return [
            'Primary language (write ALL keyword and suggested_title values in this language): '.$ctx['site']['primary_language'],
            'Domain: '.($ctx['site']['domain'] !== '' ? $ctx['site']['domain'] : '(unknown)'),
            'Direction: '.$options['direction'],
            'Content type: '.$options['content_type'],
            'Post type target: '.$options['post_type'],
            'Return JSON only — see OUTPUT CONTRACT at the end of this brief.',
            'description = concise 1–3 sentence article brief that disambiguates short SEO keywords so later writing understands intended scope (not a full outline).',
            'suggestion_reason = short why this topic was suggested (gap/signal). Keep distinct from description. No chain-of-thought.',
            'Prefer uncovered / weak-coverage directions. Do not invent ranking/volume data.',
            'Prefer NOT to create duplicates for topics already covered by existing published articles — those belong to Rewrite/Improve lanes.',
            'GSC falling/weak CTR on an existing published page is an improvement signal, not a Create duplicate.',
            'GSC impressions are Search Console impressions, not global search volume. Do not invent keyword difficulty.',
            'Do not repeat items already planned or rejected below.',
            'Do not invent articles; propose planning suggestions only.',
        ];
    }

    /**
     * Product planning brief: Draft items that later become WooCommerce/product pages — not blog topics.
     *
     * @param  PlanningContext  $ctx
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function productPlanningBriefHeader(array $ctx, array $options): array
    {
        return [
            'Primary language (write ALL keyword and suggested_title values in this language): '.$ctx['site']['primary_language'],
            'Domain: '.($ctx['site']['domain'] !== '' ? $ctx['site']['domain'] : '(unknown)'),
            'Direction: '.$options['direction'],
            'Content type: product',
            'Post type target: product',
            'Mode: PRODUCT PLANNING — create Draft planning items that will LATER become WooCommerce/product pages. Do NOT create or publish a WordPress Product during planning.',
            'Suggestions must represent concrete product-page opportunities, NOT blog/article topics about products.',
            'suggested_title must be suitable as a product / product-page title, not a blog headline.',
            'Prefer commercial/product intent where supported by the supplied site/keyword evidence.',
            'Do not fabricate prices, SKU, inventory, dimensions, technical specifications, availability, certifications, or other factual product data not present in context.',
            'Do not create taxonomy terms.',
            'Return JSON only — see OUTPUT CONTRACT at the end of this brief.',
            'description = concise 1–3 sentence PRODUCT CONTENT BRIEF explaining what the future product page should cover/position (NOT an article brief, NOT a full outline).',
            'product_type = concise product type/category description for planning (loai_san_pham). Do not invent WordPress taxonomy IDs.',
            'gallery_description = concise gallery/image direction for the future Product (angles/context) without fabricating factual product attributes.',
            'suggestion_reason = short user-facing why this product opportunity was suggested (gap/signal). Keep distinct from description. No chain-of-thought.',
            'Prefer uncovered / weak-coverage directions. Do not invent ranking/volume data.',
            'Prefer NOT to create duplicates for opportunities already covered by existing published product/content — those belong to Rewrite/Improve lanes.',
            'GSC falling/weak CTR on an existing published page is an improvement signal, not a Create duplicate.',
            'GSC impressions are Search Console impressions, not global search volume. Do not invent keyword difficulty.',
            'Do not repeat items already planned or rejected below.',
            'Propose Product planning items only. Do not fabricate product facts and do not create/publish Products at planning stage.',
        ];
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, score: float, coverage: string, source: string}>
     */
    private function principalKeywords(int $siteId): array
    {
        unset($siteId);

        // seo_keyword_classifications / cluster scoring retired.
        return [];
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string, score: float, coverage: string, source: string}>  $principal
     * @return array{0: array<string, true>, 1: array{covered: int, weakly_covered: int, uncovered: int, unknown: int}}
     */
    private function annotateCoverage(array &$principal, int $siteId): array
    {
        $coveredNorms = [];
        $counts = ['covered' => 0, 'weakly_covered' => 0, 'uncovered' => 0, 'unknown' => 0];

        if ($principal === []) {
            return [$coveredNorms, $counts];
        }

        $linkedPublish = [];
        $linkedAny = [];
        $ids = array_values(array_filter(array_map(
            static fn (array $r): int => (int) ($r['keyword_id'] ?? 0),
            $principal,
        ), static fn (int $id): bool => $id > 0));

        if ($ids !== [] && Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            try {
                $anyLinks = DB::connection('omi_seo_ai')
                    ->table('seo_link_maps')
                    ->whereIn('keyword_id', $ids)
                    ->where(function ($q): void {
                        $q->whereNotNull('source_article_id')->orWhereNotNull('target_article_id');
                    })
                    ->distinct()
                    ->pluck('keyword_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $linkedAny = array_fill_keys($anyLinks, true);

                if (Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')
                    && Schema::connection('omi_seo_ai')->hasTable('articles')
                ) {
                    $withPublish = DB::connection('omi_seo_ai')
                        ->table('seo_link_maps as lm')
                        ->join('articles as a', function ($join): void {
                            $join->on('a.id', '=', 'lm.source_article_id')
                                ->orOn('a.id', '=', 'lm.target_article_id');
                        })
                        ->join('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
                        ->whereIn('lm.keyword_id', $ids)
                        ->where('wal.observed_post_status', 'publish')
                        ->whereNull('a.deleted_at')
                        ->distinct()
                        ->pluck('lm.keyword_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();
                    $linkedPublish = array_fill_keys($withPublish, true);
                }
            } catch (Throwable) {
                $linkedPublish = [];
                $linkedAny = [];
            }
        }

        foreach ($principal as &$row) {
            $kid = (int) ($row['keyword_id'] ?? 0);
            if ($kid > 0 && isset($linkedPublish[$kid])) {
                $row['coverage'] = 'covered';
                $counts['covered']++;
                $norm = NewContentSuggestionIdentity::normalize($row['phrase']);
                if ($norm !== '') {
                    $coveredNorms[$norm] = true;
                }
            } elseif ($kid > 0 && isset($linkedAny[$kid])) {
                // Weak link / KI presence is a planning signal — not Create hard-blocker coverage.
                $row['coverage'] = 'weakly_covered';
                $counts['weakly_covered']++;
            } else {
                $row['coverage'] = 'uncovered';
                $counts['uncovered']++;
            }
        }
        unset($row);

        return [$coveredNorms, $counts];
    }

    /**
     * @return list<array{label: string, keyword_count: int, article_count: int, coverage: string}>
     */
    private function clusterSummaries(int $siteId): array
    {
        unset($siteId);

        // KeywordClusterQuery / TopicIdeaCoverage / DNA retired.
        return [];
    }

    /**
     * @return list<array{keyword: string, title: string, type: string, fingerprint: string}>
     */
    private function plannedTopics(SeoProject $project): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('archived_at')
            ->whereIn('type', [
                SeoProjectTask::TYPE_CREATE,
                SeoProjectTask::TYPE_REWRITE,
                SeoProjectTask::TYPE_IMPROVE,
            ])
            ->orderBy('id')
            ->limit(ContentPlanningIntelligenceCaps::PLANNED_TOPICS)
            ->get(['id', 'type', 'keyword', 'title']);

        $out = [];
        foreach ($tasks as $task) {
            $keyword = trim((string) ($task->keyword ?? ''));
            $title = trim((string) ($task->title ?? ''));
            if ($keyword === '' && $title === '') {
                continue;
            }
            $out[] = [
                'keyword' => $keyword !== '' ? $keyword : $title,
                'title' => $title !== '' ? $title : $keyword,
                'type' => (string) ($task->type ?? 'create'),
                'fingerprint' => NewContentSuggestionIdentity::fingerprint(
                    $keyword !== '' ? $keyword : $title,
                    $title !== '' ? $title : $keyword,
                ),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{keyword: string, title: string, fingerprint: string}>
     */
    private function rejectedTopics(SeoProject $project): array
    {
        $rows = SeoContentProjectSuggestionDecision::query()
            ->where('project_id', (int) $project->getKey())
            ->where('source_type', SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT)
            ->where('decision', SeoContentProjectSuggestionDecision::DECISION_DISMISSED)
            ->orderByDesc('id')
            ->limit(ContentPlanningIntelligenceCaps::REJECTED_TOPICS)
            ->get(['source_key', 'meta']);

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row->source_key ?? '');
            if (! str_starts_with($key, 'fp:')) {
                continue;
            }
            $fp = substr($key, 3);
            if ($fp === '') {
                continue;
            }
            $meta = is_array($row->meta) ? $row->meta : [];
            $out[] = [
                'keyword' => (string) ($meta['keyword'] ?? ''),
                'title' => (string) ($meta['title'] ?? ''),
                'fingerprint' => $fp,
            ];
        }

        return $out;
    }

    /**
     * Keyword planning signals from live Keyword Landscape (deterministic, 0 AI).
     *
     * @return array{0: list<array{type: string, label: string}>, 1: string|null, 2: list<array{topic: string, signal: string}>}
     */
    private function mcpSignals(int $siteId): array
    {
        $gateway = $this->landscape ?? (app()->bound(KeywordLandscapeGateway::class) ? app(KeywordLandscapeGateway::class) : null);
        if (! $gateway instanceof KeywordLandscapeGateway) {
            return [[], null, []];
        }

        $periodKey = now()->format('Y-m');

        try {
            $landscape = $gateway->forSite($siteId, true);
        } catch (Throwable) {
            return [[], null, []];
        }

        $signals = [];
        $missing = [];

        foreach ($landscape->topics as $topic) {
            if (count($signals) >= ContentPlanningIntelligenceCaps::MCP_SIGNALS) {
                break;
            }
            $label = trim($topic->name);
            if ($label === '') {
                continue;
            }

            $coverage = $topic->coverage;
            if ($coverage === 'weak') {
                $signals[] = ['type' => 'weak_cluster', 'label' => $label];
                continue;
            }

            if ($coverage === 'unknown' || $topic->articleCount <= 0) {
                $signals[] = ['type' => 'missing_direction', 'label' => $label];
                $missing[] = ['topic' => $label, 'signal' => 'mcp_signal'];
            }
        }

        return [$signals, $periodKey, $missing];
    }

    /**
     * Read GSC planning signals from GscContextGateway. Absent GSC → empty list (non-blocking).
     *
     * @return array{0: list<array{type: string, label: string, query?: string, lane?: string}>, 1: string|null, 2: list<array{topic: string, signal: string}>}
     */
    private function gscSignals(int $siteId, ?string $preferredPeriod): array
    {
        $gateway = $this->gsc ?? (app()->bound(GscContextGateway::class) ? app(GscContextGateway::class) : null);
        if (! $gateway instanceof GscContextGateway) {
            return [[], null, []];
        }

        $periodKey = $preferredPeriod;
        if ($periodKey === null || preg_match('/^\d{4}-\d{2}$/', $periodKey) !== 1) {
            $periodKey = now()->format('Y-m');
        }

        try {
            $ctx = $gateway->forSite($siteId, $periodKey);
        } catch (Throwable) {
            return [[], null, []];
        }

        if (! $ctx->available()) {
            return [[], null, []];
        }

        $context = $ctx->context;
        $summary = $ctx->summary;
        $raw = is_array($context['planning_signals'] ?? null) ? $context['planning_signals'] : [];
        if ($raw === []) {
            foreach (['falling_queries', 'rising_queries', 'high_impression_low_ctr', 'near_page_one', 'content_decay', 'possible_cannibalization', 'new_content_opportunities'] as $bucket) {
                foreach ((array) ($summary[$bucket] ?? []) as $row) {
                    if (is_array($row)) {
                        $raw[] = $row;
                    }
                }
            }
        }

        $signals = [];
        $missing = [];
        foreach ($raw as $row) {
            if (! is_array($row) || count($signals) >= ContentPlanningIntelligenceCaps::GSC_SIGNALS) {
                break;
            }
            $type = (string) ($row['type'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));
            $query = trim((string) ($row['query'] ?? ''));
            if ($label === '' && $query !== '') {
                $label = $query;
            }
            if ($type === '' || $label === '') {
                continue;
            }
            $lane = (string) ($row['lane'] ?? '');
            $signals[] = [
                'type' => $type,
                'label' => $label,
                'query' => $query,
                'lane' => $lane,
            ];
            if ($lane === 'new_content_signal' && $query !== '') {
                $missing[] = ['topic' => $query, 'signal' => 'gsc_signal'];
            }
        }

        return [$signals, $periodKey, $missing];
    }

    /**
     * GSC observed published coverage: improvement-lane signals mark matching principal phrases covered.
     * Does not invent coverage for unresolved / new-content-only queries.
     *
     * @param  list<array{keyword_id: int, phrase: string, score: float, coverage: string, source: string}>  $principal
     * @param  array<string, true>  $coveredNorms
     * @param  array{covered: int, weakly_covered: int, uncovered: int, unknown: int}  $counts
     * @param  list<array{type: string, label: string, query?: string, lane?: string}>  $gscSignals
     */
    private function applyGscCoverageToPrincipal(
        array &$principal,
        array &$coveredNorms,
        array &$counts,
        array $gscSignals,
    ): void {
        $coveredQueries = [];
        foreach ($gscSignals as $sig) {
            if (($sig['lane'] ?? '') !== 'improvement_signal') {
                continue;
            }
            $norm = NewContentSuggestionIdentity::normalize((string) ($sig['query'] ?? ''));
            if ($norm !== '') {
                $coveredQueries[$norm] = true;
            }
        }
        if ($coveredQueries === []) {
            return;
        }

        foreach ($principal as &$row) {
            if (($row['coverage'] ?? '') === 'covered') {
                continue;
            }
            $norm = NewContentSuggestionIdentity::normalize((string) ($row['phrase'] ?? ''));
            if ($norm === '' || ! isset($coveredQueries[$norm])) {
                continue;
            }
            $prev = (string) ($row['coverage'] ?? 'uncovered');
            if ($prev === 'uncovered' && isset($counts['uncovered']) && $counts['uncovered'] > 0) {
                $counts['uncovered']--;
            } elseif ($prev === 'weakly_covered' && isset($counts['weakly_covered']) && $counts['weakly_covered'] > 0) {
                $counts['weakly_covered']--;
            } elseif ($prev === 'unknown' && isset($counts['unknown']) && $counts['unknown'] > 0) {
                $counts['unknown']--;
            }
            $row['coverage'] = 'covered';
            $counts['covered'] = (int) ($counts['covered'] ?? 0) + 1;
            $coveredNorms[$norm] = true;
        }
        unset($row);
    }
}
