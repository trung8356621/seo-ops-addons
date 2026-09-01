<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use App\Models\Site;
use Illuminate\Console\Command;
use Omnichannel\Addons\SiteSync\Services\Audit\FocusKeywordSyncIntegrityReportService;

/**
 * Evidence-only Focus Keyword coverage + WP/V3/Laravel integrity report.
 * Does not patch Site Sync importer/exporter.
 */
final class AuditFocusKeywordCoverageCommand extends Command
{
    protected $signature = 'seo:audit-focus-keyword-coverage
        {site_id : Core sites.id}
        {--live : Fetch live Site Sync V3 records from WordPress}
        {--json : Print JSON only}';

    protected $description = 'Audit Focus Keyword article coverage and optional WP→V3→Laravel integrity (read-only)';

    public function handle(FocusKeywordSyncIntegrityReportService $reports): int
    {
        $siteId = (int) $this->argument('site_id');
        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error('Site not found: '.$siteId);

            return self::FAILURE;
        }

        $report = $reports->report($site, (bool) $this->option('live'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $coverage = is_array($report['coverage'] ?? null) ? $report['coverage'] : [];
        $audit = is_array($report['audit'] ?? null) ? $report['audit'] : [];
        $stages = is_array($audit['stages'] ?? null) ? $audit['stages'] : [];
        $classification = is_array($audit['classification'] ?? null) ? $audit['classification'] : [];
        $diffs = is_array($audit['set_diffs'] ?? null) ? $audit['set_diffs'] : [];
        $ui142 = is_array($report['ui_142_semantics'] ?? null) ? $report['ui_142_semantics'] : [];
        $breakdown = is_array($coverage['source_breakdown'] ?? null) ? $coverage['source_breakdown'] : [];

        $this->info('A. UI Focus keywords tab semantics');
        $this->line((string) ($ui142['meaning'] ?? ''));
        $this->line('Path: '.(string) ($ui142['path'] ?? ''));

        $this->newLine();
        $this->info('B. Article coverage');
        $this->line('Eligible articles = '.(int) ($coverage['eligible_article_count'] ?? 0));
        $this->line('With effective focus keyword = '.(int) ($coverage['articles_with_focus_keyword'] ?? 0));
        $this->line('Missing = '.(int) ($coverage['missing_focus_keyword_articles'] ?? 0));
        $this->line('Coverage % = '.(($coverage['coverage_pct'] ?? null) !== null ? (string) $coverage['coverage_pct'] : 'n/a'));

        $this->newLine();
        $this->info('C. Keyword inventory');
        $this->line('Unique effective focus phrases = '.(int) ($coverage['unique_effective_focus_phrases'] ?? 0));
        $this->line('Total focus/article relations = '.(int) ($coverage['focus_article_relations'] ?? 0));

        $this->newLine();
        $this->info('D. Source breakdown (priority manual > provider > workspace)');
        $this->line('manual = '.(int) ($breakdown['manual'] ?? 0));
        $this->line('provider = '.(int) ($breakdown['provider'] ?? 0));
        $this->line('workspace = '.(int) ($breakdown['workspace'] ?? 0));

        $this->newLine();
        $this->info('E. WP vs V3 vs Laravel');
        $this->table(
            ['Stage', 'Articles with focus'],
            [
                ['WordPress provider', (int) ($stages['wordpress_provider'] ?? 0)],
                ['V3 payload', (int) ($stages['v3_payload'] ?? 0)],
                ['Laravel provider relation', (int) ($stages['laravel_provider_relation'] ?? 0)],
                ['Effective coverage', (int) ($stages['effective_coverage'] ?? 0)],
            ],
        );
        $live = is_array($report['live'] ?? null) ? $report['live'] : [];
        $this->line('Live fetch: '.json_encode($live, JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('F. Missing classification');
        $this->table(
            ['Reason', 'Count'],
            [
                ['WP truly missing', (int) ($classification['wp_truly_missing'] ?? 0)],
                ['WP→V3 loss', (int) ($classification['wp_to_v3_loss'] ?? 0)],
                ['V3→Laravel loss', (int) ($classification['v3_to_laravel_loss'] ?? 0)],
                ['resolver/UI', (int) ($classification['resolver_ui'] ?? 0)],
                ['manual/workspace edge', (int) ($classification['manual_workspace_edge'] ?? 0)],
            ],
        );

        $this->newLine();
        $this->info('G. Exact bad IDs');
        $wpMinusV3 = is_array($diffs['wp_minus_v3'] ?? null) ? $diffs['wp_minus_v3'] : [];
        $v3MinusLaravel = is_array($diffs['v3_minus_laravel_provider'] ?? null) ? $diffs['v3_minus_laravel_provider'] : [];
        $this->line('WP→V3 loss WP IDs: '.($wpMinusV3 === [] ? '(none)' : implode(', ', $wpMinusV3)));
        $this->line('V3→Laravel loss WP IDs: '.($v3MinusLaravel === [] ? '(none)' : implode(', ', $v3MinusLaravel)));

        $this->newLine();
        $this->info('H. Domain card');
        $eligible = (int) ($coverage['eligible_article_count'] ?? 0);
        $with = (int) ($coverage['articles_with_focus_keyword'] ?? 0);
        $missing = (int) ($coverage['missing_focus_keyword_articles'] ?? 0);
        $this->line($missing === 0
            ? "✓ Focus Keywords\n{$with} / {$eligible} articles\nComplete"
            : "⚠ Focus Keywords\n{$with} / {$eligible} articles\n{$missing} missing");

        $this->newLine();
        $this->info('I. Posts filter');
        $this->line('Missing Focus results count must equal card missing = '.$missing);
        if (filled($coverage['filter_url'] ?? null)) {
            $this->line((string) $coverage['filter_url']);
        }

        $this->newLine();
        $this->warn('STOP: no Site Sync keyword importer/exporter patch in this command.');

        return self::SUCCESS;
    }
}
