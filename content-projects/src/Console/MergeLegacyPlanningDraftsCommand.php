<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\LegacyPlanningDraftMergeService;

/**
 * Merge legacy per-site Planning Drafts into the Shared Planning Draft.
 */
final class MergeLegacyPlanningDraftsCommand extends Command
{
    protected $signature = 'seo:merge-legacy-planning-drafts
        {--dry-run : Report only; do not write DB (default when --force is absent)}
        {--force : Apply merge + archive legacy drafts}
        {--bootstrap-site= : Site id used only when creating a missing Shared Draft}
        {--actor= : Optional actor user id for archive stamp}
        {--json : Print JSON only}';

    protected $description = 'Merge legacy per-site Content plan drafts into one Shared Planning Draft';

    public function handle(LegacyPlanningDraftMergeService $merge): int
    {
        $force = (bool) $this->option('force');
        $dryRun = ! $force || (bool) $this->option('dry-run');

        if ($dryRun && $force && (bool) $this->option('dry-run')) {
            $this->warn('--dry-run wins over --force; no DB writes.');
            $dryRun = true;
        }

        $bootstrap = (int) ($this->option('bootstrap-site') ?? 0);
        $actor = (int) ($this->option('actor') ?? 0);

        if ($dryRun) {
            $this->info('DRY-RUN — no DB writes. Pass --force to apply.');
        } else {
            $this->warn('FORCE — will move items and archive legacy drafts (no hard delete).');
        }

        $report = $merge->merge(
            dryRun: $dryRun,
            actorId: $actor > 0 ? $actor : null,
            bootstrapSiteId: $bootstrap,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($report['verify_ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info('Shared Draft id: '.($report['canonical_shared_draft_id'] ?? 'NONE'));
        $this->line('Created Shared Draft: '.(($report['created_shared_draft'] ?? false) ? 'yes' : 'no'));
        $this->line('Items before: '.(int) ($report['shared_item_count_before'] ?? 0));
        $this->line('Moved: '.(int) ($report['moved'] ?? 0));
        $this->line('Skipped duplicates: '.(int) ($report['skipped_duplicates'] ?? 0));
        $this->line('Items after: '.(int) ($report['shared_item_count_after'] ?? 0));
        $this->line('Expected: '.(int) ($report['expected_merged_item_count'] ?? 0));
        $this->line('Verify: '.(($report['verify_ok'] ?? false) ? 'OK' : 'MISMATCH'));

        $legacy = is_array($report['legacy_drafts'] ?? null) ? $report['legacy_drafts'] : [];
        if ($legacy !== []) {
            $this->newLine();
            $this->table(
                ['id', 'name', 'site_id', 'items'],
                array_map(static fn (array $r): array => [
                    $r['id'],
                    mb_substr((string) $r['name'], 0, 48),
                    $r['site_id'],
                    $r['item_count'],
                ], $legacy),
            );
        }

        $archived = is_array($report['archived_legacy_ids'] ?? null) ? $report['archived_legacy_ids'] : [];
        if ($archived !== []) {
            $this->line('Archived legacy ids: '.implode(', ', $archived));
        }

        $dist = is_array($report['item_distribution_by_site'] ?? null) ? $report['item_distribution_by_site'] : [];
        if ($dist !== []) {
            $this->newLine();
            $this->info('Shared Draft distribution by item.site_id:');
            $this->table(
                ['site_id', 'domain', 'items'],
                array_map(static fn (array $r): array => [
                    $r['site_id'],
                    $r['domain'],
                    $r['item_count'],
                ], array_values($dist)),
            );
        }

        return ($report['verify_ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
