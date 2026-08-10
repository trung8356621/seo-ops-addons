<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Backfill;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\KeywordNormalizationService;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

/**
 * Safe legacy → Site Sync V2 backfill. Never deletes legacy. Never invents provider.
 */
final class SiteSyncV2BackfillService
{
    public function __construct(
        private readonly SiteLinkCatalogReconciler $links,
        private readonly KeywordNormalizationService $normalizer,
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * @param  list<string>  $only
     * @return array<string, mixed>
     */
    public function run(Site $site, array $only = ['all'], bool $dryRun = true, int $batch = 200, ?int $resumeId = null): array
    {
        $modes = in_array('all', $only, true)
            ? ['links', 'keywords', 'scores', 'articles', 'profile']
            : $only;

        $report = [
            'site_id' => (int) $site->id,
            'dry_run' => $dryRun,
            'modes' => $modes,
            'will_create' => 0,
            'will_update' => 0,
            'duplicates' => 0,
            'conflicts' => 0,
            'manual_preserved' => 0,
            'unknown_source' => 0,
            'invalid' => 0,
            'skipped' => 0,
            'sections' => [],
        ];

        foreach ($modes as $mode) {
            $section = match ($mode) {
                'links' => $this->backfillLinks($site, $dryRun),
                'keywords' => $this->backfillKeywords($site, $dryRun, $batch, $resumeId),
                'scores' => $this->backfillScores($site, $dryRun, $batch),
                'articles' => $this->backfillArticles($site, $dryRun),
                'profile' => $this->backfillProfile($site, $dryRun),
                default => ['skipped' => 1, 'note' => 'unknown mode '.$mode],
            };
            $report['sections'][$mode] = $section;
            foreach (['will_create', 'will_update', 'duplicates', 'conflicts', 'manual_preserved', 'unknown_source', 'invalid', 'skipped'] as $k) {
                $report[$k] += (int) ($section[$k] ?? 0);
            }
        }

        if (! $dryRun) {
            SiteSyncSiteMeta::putJson($site, SiteSyncSchema::META_BACKFILL_REPORT, [
                'finished_at' => now()->toIso8601String(),
                'report' => $report,
            ]);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillLinks(Site $site, bool $dryRun): array
    {
        $ctx = $this->promptContext->getForSite($site);
        $rows = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        $create = 0;
        $dup = 0;
        $invalid = 0;
        foreach ($rows as $row) {
            $url = trim((string) ($row['link'] ?? ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid++;
                continue;
            }
            $hash = hash('sha256', mb_strtolower($url));
            $exists = \Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink::query()
                ->where('site_id', (int) $site->id)
                ->where('url_hash', $hash)
                ->exists();
            if ($exists) {
                $dup++;
                continue;
            }
            $create++;
        }

        if (! $dryRun && $rows !== []) {
            $this->links->syncManualLinksFromSettings($site, $rows);
        }

        return [
            'will_create' => $create,
            'duplicates' => $dup,
            'invalid' => $invalid,
            'note' => 'Domain link list → Manual Site Links (never WordPress source)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillKeywords(Site $site, bool $dryRun, int $batch, ?int $resumeId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source')) {
            return ['skipped' => 1, 'note' => 'keywords.source column missing'];
        }

        $q = Keyword::query()->orderBy('id')->limit($batch);
        if ($resumeId !== null) {
            $q->where('id', '>', $resumeId);
        }
        // Scope by user when possible
        if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id') && $site->user_id) {
            $q->where('user_id', (int) $site->user_id);
        }

        $create = 0;
        $update = 0;
        $manual = 0;
        $unknown = 0;
        $dup = 0;

        foreach ($q->get() as $kw) {
            $phrase = $this->normalizer->normalize((string) $kw->phrase);
            if ($phrase === '') {
                continue;
            }
            $existingSource = (string) ($kw->source ?? '');
            if ($existingSource === SiteSyncSchema::SOURCE_MANUAL || (bool) ($kw->source_locked ?? false)) {
                $manual++;
                continue;
            }
            if ($existingSource !== '') {
                $dup++;
                continue;
            }

            $source = SiteSyncSchema::SOURCE_LEGACY_UNKNOWN;
            // Do not invent Rank Math/Yoast — only mark legacy_unknown unless locked/manual already set.
            $unknown++;
            $update++;
            if (! $dryRun) {
                $kw->forceFill([
                    'phrase' => $phrase,
                    'source' => $source,
                    'source_locked' => false,
                ])->save();
            }
            $create += 0;
        }

        return [
            'will_update' => $update,
            'manual_preserved' => $manual,
            'unknown_source' => $unknown,
            'duplicates' => $dup,
            'will_create' => $create,
            'note' => 'Unset source → legacy_unknown; manual locked preserved; no HTML parse; no AI',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillScores(Site $site, bool $dryRun, int $batch): array
    {
        $articles = SeoArticle::query()
            ->leftJoin('seo_article_profiles as sap_backfill', 'sap_backfill.article_id', '=', 'articles.id')
            ->leftJoin('wordpress_article_links as wal_backfill', 'wal_backfill.article_id', '=', 'articles.id')
            ->where('articles.site_id', (int) $site->id)
            ->whereNotNull('sap_backfill.seo_score')
            ->orderBy('articles.id')
            ->limit($batch)
            ->get([
                'articles.id as id',
                'sap_backfill.seo_score as seo_score',
                'wal_backfill.wp_post_id as wp_post_id',
            ]);

        $create = 0;
        $skip = 0;
        foreach ($articles as $article) {
            $exists = SeoArticleScoreSource::query()
                ->where('site_id', (int) $site->id)
                ->where('article_id', (int) $article->id)
                ->where('source', SiteSyncSchema::SOURCE_LEGACY_UNKNOWN)
                ->exists();
            if ($exists) {
                $skip++;
                continue;
            }
            $create++;
            if (! $dryRun) {
                SeoArticleScoreSource::query()->create([
                    'site_id' => (int) $site->id,
                    'article_id' => (int) $article->id,
                    'wordpress_id' => $article->wordpressLink?->wp_post_id,
                    'source' => SiteSyncSchema::SOURCE_LEGACY_UNKNOWN,
                    'score' => (int) $article->seoProfile?->seo_score,
                    'raw' => ['note' => 'legacy score — provider unknown'],
                ]);
            }
        }

        return [
            'will_create' => $create,
            'skipped' => $skip,
            'note' => 'Legacy scores stored as legacy_unknown — never labeled Rank Math/Yoast',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillArticles(Site $site, bool $dryRun): array
    {
        $count = SeoArticle::query()->where('site_id', (int) $site->id)->count();

        return [
            'skipped' => $count,
            'note' => $dryRun
                ? "{$count} articles already local — bootstrap snapshot reconciles body; no destructive rewrite"
                : "{$count} articles left intact",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backfillProfile(Site $site, bool $dryRun): array
    {
        return [
            'skipped' => 1,
            'manual_preserved' => 1,
            'note' => $dryRun
                ? 'Profile suggestions handled by handshake/profile service — manual tone/CTA preserved'
                : 'Profile backfill deferred to suggestion accept flow',
        ];
    }
}
