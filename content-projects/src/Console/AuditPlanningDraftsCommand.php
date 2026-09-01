<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\LegacyPlanningDraftMergeService;

/**
 * Inventory Shared Planning Draft vs legacy per-site drafts (read-only).
 */
final class AuditPlanningDraftsCommand extends Command
{
    protected $signature = 'seo:audit-planning-drafts {--json : Print JSON only}';

    protected $description = 'Report canonical Shared Planning Draft and legacy per-site drafts';

    public function handle(LegacyPlanningDraftMergeService $merge): int
    {
        $payload = $merge->inventory();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Canonical Shared Draft: '.($payload['canonical_shared_draft_id'] ?? 'NONE'));
        $this->line('Shared draft item count: '.(int) $payload['shared_draft_item_count']);
        $this->line('Legacy draft count: '.count($payload['legacy_drafts']));
        $this->line('Total source items (legacy): '.(int) $payload['total_source_items']);
        $this->line('Duplicate/conflict count: '.(int) $payload['duplicate_conflict_count']);
        $this->line('Expected merged item count: '.(int) $payload['expected_merged_item_count']);

        if ($payload['legacy_drafts'] !== []) {
            $this->newLine();
            $this->table(
                ['id', 'name', 'site_id', 'domain', 'items'],
                array_map(static fn (array $r): array => [
                    $r['id'],
                    mb_substr((string) $r['name'], 0, 40),
                    $r['site_id'],
                    $r['domain'],
                    $r['item_count'],
                ], $payload['legacy_drafts']),
            );
        }

        $dist = $payload['shared_item_distribution_by_site'];
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

        $this->newLine();
        $this->line($payload['note']);

        return self::SUCCESS;
    }
}
