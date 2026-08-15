<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder;

final class KeywordIntelligenceReportCommand extends Command
{
    protected $signature = 'seo:keywords:intelligence-report
        {--site= : Site ID}
        {--classify : Classify dirty keywords before reporting}
        {--limit=2000}';

    protected $description = 'Print keyword intelligence distribution, top clusters, gaps, and compact generation context.';

    public function handle(
        KeywordClassificationService $classification,
        KeywordGenerationContextBuilder $builder,
    ): int {
        $siteId = (int) $this->option('site');
        if ($siteId <= 0) {
            $this->error('--site is required');

            return self::FAILURE;
        }
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        if ((bool) $this->option('classify')) {
            $result = $classification->classifyBatch($siteId, max(1, (int) $this->option('limit')), false, false);
            $this->info('classified processed='.$result['processed'].' skipped='.$result['skipped']);
        }

        $landscape = $classification->refreshLandscapeSnapshot($siteId);
        $c = is_array($landscape['classification'] ?? null) ? $landscape['classification'] : [];
        $this->line('=== Classification distribution ===');
        $this->line('Raw: '.(int) ($landscape['raw_keywords'] ?? 0));
        $this->line('Usable SEO: '.(int) ($landscape['usable_seo_keywords'] ?? 0));
        $this->line('keyword_phrase: '.(int) ($c['keyword_phrase'] ?? 0));
        $this->line('query: '.(int) ($c['query'] ?? 0));
        $this->line('brand_entity: '.(int) ($c['brand_entity'] ?? 0));
        $this->line('descriptive_phrase: '.(int) ($c['descriptive_phrase'] ?? 0));
        $this->line('sentence: '.(int) ($c['sentence'] ?? 0));
        $this->line('url_domain: '.(int) ($c['url_domain'] ?? 0));
        $this->line('noise: '.(int) ($c['noise'] ?? 0));
        $this->line('ambiguous: '.(int) ($c['ambiguous'] ?? 0));

        $clusters = is_array($landscape['clusters'] ?? null) ? $landscape['clusters'] : [];
        $this->line('');
        $this->line('=== Top 20 clusters ===');
        foreach (array_slice($clusters, 0, 20) as $cluster) {
            $this->line(sprintf(
                '%s | usable=%d | intents=%s | pages=%d | %s',
                (string) ($cluster['primary'] ?? ''),
                (int) ($cluster['usable_keyword_count'] ?? 0),
                implode(',', (array) ($cluster['intent_coverage'] ?? [])),
                (int) ($cluster['target_pages'] ?? 0),
                (string) ($cluster['coverage'] ?? ''),
            ));
        }

        $this->line('');
        $this->line('=== Top 20 gaps ===');
        $n = 0;
        foreach ($clusters as $cluster) {
            if (! in_array((string) ($cluster['coverage'] ?? ''), ['missing', 'weak'], true)) {
                continue;
            }
            $this->line(sprintf(
                '%s | %s | %s | %s',
                implode(',', (array) ($cluster['missing_directions'] ?? [])),
                (string) ($cluster['primary'] ?? ''),
                (string) ($cluster['coverage'] ?? ''),
                (string) ($cluster['recommended_action'] ?? ''),
            ));
            $n++;
            if ($n >= 20) {
                break;
            }
        }

        $context = $builder->build($landscape, [
            'site' => (string) $site->domain,
            'max_topics' => 50,
            'max_exclusions' => 150,
        ]);
        $this->line('');
        $this->line('=== Generation context ===');
        $this->line((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
