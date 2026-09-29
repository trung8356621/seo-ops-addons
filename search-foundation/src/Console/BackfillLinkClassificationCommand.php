<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchFoundation\Services\LinkClassificationReconciliationService;

/**
 * Idempotent reconciliation of seo_link_maps classification.
 *
 * Stale external / needs_review rows are reclassified through LinkDestinationClassifier.
 * Rows that already use a current type only have incomplete metadata repaired, and only
 * when that repair matches the canonical classifier or the stored type's own metadata.
 * A second run writes nothing when the persisted fields already match.
 */
final class BackfillLinkClassificationCommand extends Command
{
    protected $signature = 'seo:backfill-link-classification
        {--dry-run : Print changes without persisting them}
        {--chunk=500 : Number of rows to process per chunk}';

    protected $description = 'Reconcile seo_link_maps link_type, destination_kind, target_site_id, and is_semantic_eligible';

    public function handle(LinkClassificationReconciliationService $reconciliationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be persisted.');
        }

        $this->info("Reconciling seo_link_maps in chunks of {$chunkSize}…");

        $result = $reconciliationService->reconcile(
            chunkSize: $chunkSize,
            dryRun: $dryRun,
            onProgress: function (int $total, int $updated, ?array $patch = null, ?object $row = null) use ($dryRun, $chunkSize): void {
                if ($dryRun && $patch !== null && $row !== null) {
                    $this->line(sprintf(
                        '  [DRY] id=%d  %s',
                        $row->id,
                        json_encode($patch, JSON_UNESCAPED_UNICODE),
                    ));
                }

                if ($patch === null && $row === null) {
                    $this->line(sprintf('  Processed %d rows so far (%d will be updated)…', $total, $updated));
                }
            },
        );

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info(sprintf('%s %d / %d rows.', $verb, $result['updated'], $result['total']));

        return self::SUCCESS;
    }
}
