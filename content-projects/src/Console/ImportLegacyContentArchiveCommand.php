<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ImportLegacyContentArchiveService;
use Illuminate\Console\Command;

/**
 * Import historical `seo_content_archive_items` into the canonical
 * “Legacy articles” archived Content Project.
 *
 * Does not call the normal strong archive() / workspace destroy path.
 */
final class ImportLegacyContentArchiveCommand extends Command
{
    protected $signature = 'content-project:import-legacy-archive
        {--dry-run : Report only; no mutations}
        {--apply : Materialize Legacy articles archived project + archive items}
        {--reconcile : Compare seo_content_archive_items vs imported SeoProjectArchiveItem}
        {--actor= : Optional archived_by / owner user id}';

    protected $description = 'Import historical seo_content_archive_items into canonical “Legacy articles” archived Content Project.';

    public function handle(ImportLegacyContentArchiveService $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        $reconcile = (bool) $this->option('reconcile');

        if (! $dryRun && ! $apply && ! $reconcile) {
            $this->error('Specify --dry-run, --apply, and/or --reconcile.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $report = $importer->dryRun();
            $this->info('DRY RUN — no mutations');
            $this->printImportReport($report);
        }

        if ($apply) {
            $actor = (int) ($this->option('actor') ?? 0);
            $report = $importer->apply($actor > 0 ? $actor : null);
            $this->info('APPLY complete');
            $this->printImportReport($report);
            $this->line('created_project: '.(($report['created_project'] ?? false) ? 'yes' : 'no'));
            $this->line('created_archive: '.(($report['created_archive'] ?? false) ? 'yes' : 'no'));
            $this->line('tasks_upserted: '.(int) ($report['tasks_upserted'] ?? 0));
            $this->line('items_upserted: '.(int) ($report['items_upserted'] ?? 0));
        }

        if ($reconcile || $apply) {
            $recon = $importer->reconcile();
            $this->newLine();
            $this->info('RECONCILE');
            $this->line('source_count: '.(int) $recon['source_count']);
            $this->line('source_unique_articles: '.(int) $recon['source_unique_articles']);
            $this->line('target_count: '.(int) $recon['target_count']);
            $this->line('target_unique_articles: '.(int) $recon['target_unique_articles']);
            $this->line('project_id: '.($recon['project_id'] ?? 'null'));
            $this->line('archive_id: '.($recon['archive_id'] ?? 'null'));
            $this->line('missing_in_target: '.count($recon['missing_in_target']));
            $this->line('extra_in_target: '.count($recon['extra_in_target']));
            $this->line('duplicate_target_articles: '.count($recon['duplicate_target_articles']));
            if ($recon['missing_in_target'] !== []) {
                $this->warn('missing sample: '.implode(',', $recon['missing_in_target']));
            }
            if ($recon['extra_in_target'] !== []) {
                $this->warn('extra sample: '.implode(',', $recon['extra_in_target']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printImportReport(array $report): void
    {
        $this->line('Legacy source rows:              '.(int) ($report['source_rows'] ?? 0));
        $this->line('Valid article IDs:               '.(int) ($report['valid_article_ids'] ?? 0));
        $this->line('Missing articles:                '.(int) ($report['missing_articles'] ?? 0));
        $this->line('Duplicate source article IDs:    '.(int) ($report['duplicate_source_article_ids'] ?? 0));
        $this->line('Already imported:                '.(int) ($report['already_imported'] ?? 0));
        $this->line('Current active CP memberships:   '.(int) ($report['active_cp_memberships'] ?? 0));
        $this->line('Historical/no active membership: '.(int) ($report['historical_no_active_membership'] ?? 0));
        $this->line('Target archive items after run:  '.(int) ($report['target_archive_items_after_run'] ?? 0));
        $this->line('project_id: '.($report['project_id'] ?? 'null'));
        $this->line('archive_id: '.($report['archive_id'] ?? 'null'));
    }
}
