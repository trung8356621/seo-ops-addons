<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Merge legacy per-site Planning Drafts into one Shared Planning Draft (site_id NULL).
 * Never hard-deletes legacy drafts — archives after verify.
 */
final class LegacyPlanningDraftMergeService
{
    public function __construct(
        private readonly PlanningDraftResolver $resolver,
    ) {}

    /**
     * Read-only inventory for audit / dry-run.
     *
     * @return array{
     *     canonical_shared_draft_id: int|null,
     *     shared_draft_item_count: int,
     *     shared_item_distribution_by_site: array<int, array{site_id: int, domain: string, item_count: int}>,
     *     legacy_drafts: list<array{id: int, name: string, site_id: int, domain: string, item_count: int, distribution: array<int, int>}>,
     *     legacy_draft_ids: list<int>,
     *     legacy_draft_names: list<string>,
     *     total_source_items: int,
     *     expected_merged_item_count: int,
     *     duplicate_conflict_count: int,
     *     note: string
     * }
     */
    public function inventory(): array
    {
        $canonical = $this->resolver->findCanonicalSharedDraft();

        $sharedId = $canonical instanceof SeoProject ? (int) $canonical->getKey() : null;
        $sharedItems = $sharedId !== null
            ? $this->loadActiveItems($sharedId)
            : collect();

        $sharedKeys = [];
        foreach ($sharedItems as $item) {
            if ($item instanceof SeoProjectTask) {
                $key = $this->dedupeKey($item);
                if ($key !== null) {
                    $sharedKeys[$key] = true;
                }
            }
        }

        $legacyRows = [];
        $totalSource = 0;
        $wouldMove = 0;
        $duplicates = 0;
        $seenKeys = $sharedKeys;

        $legacy = $this->listLegacyDraftsWithRemainingItems();
        foreach ($legacy as $draft) {
            if (! $draft instanceof SeoProject) {
                continue;
            }
            $draftId = (int) $draft->getKey();
            $items = $this->loadActiveItems($draftId);
            $distribution = [];
            foreach ($items as $item) {
                if (! $item instanceof SeoProjectTask) {
                    continue;
                }
                $totalSource++;
                $siteId = $this->effectiveItemSiteId($item, $draft);
                $distribution[$siteId] = ($distribution[$siteId] ?? 0) + 1;
                $key = $this->dedupeKey($item, $siteId);
                if ($key !== null && isset($seenKeys[$key])) {
                    $duplicates++;
                } else {
                    if ($key !== null) {
                        $seenKeys[$key] = true;
                    }
                    $wouldMove++;
                }
            }

            $legacyRows[] = [
                'id' => $draftId,
                'name' => (string) $draft->name,
                'site_id' => (int) ($draft->site_id ?? 0),
                'domain' => $this->domainLabel((int) ($draft->site_id ?? 0), $draft),
                'item_count' => $items->count(),
                'distribution' => $distribution,
                'archived' => $draft->archived_at !== null,
            ];
        }

        $sharedCount = $sharedItems->count();
        $expectedMerged = $sharedCount + $wouldMove;

        return [
            'canonical_shared_draft_id' => $sharedId,
            'shared_draft_item_count' => $sharedCount,
            'shared_item_distribution_by_site' => $this->distributionRows($sharedItems, $canonical),
            'legacy_drafts' => $legacyRows,
            'legacy_draft_ids' => array_map(static fn (array $r): int => $r['id'], $legacyRows),
            'legacy_draft_names' => array_map(static fn (array $r): string => $r['name'], $legacyRows),
            'total_source_items' => $totalSource,
            'expected_merged_item_count' => $expectedMerged,
            'duplicate_conflict_count' => $duplicates,
            'note' => 'Audit only — identity dedupe only (article_id). Keyword/title never duplicates. Run seo:merge-legacy-planning-drafts --force to apply.',
        ];
    }

    /**
     * @return array{
     *     dry_run: bool,
     *     canonical_shared_draft_id: int|null,
     *     created_shared_draft: bool,
     *     moved: int,
     *     skipped_duplicates: int,
     *     shared_item_count_before: int,
     *     shared_item_count_after: int,
     *     expected_merged_item_count: int,
     *     archived_legacy_ids: list<int>,
     *     legacy_drafts: list<array{id: int, name: string, site_id: int, item_count: int}>,
     *     item_distribution_by_site: array<int, array{site_id: int, domain: string, item_count: int}>,
     *     verify_ok: bool
     * }
     */
    public function merge(bool $dryRun, ?int $actorId = null, int $bootstrapSiteId = 0): array
    {
        $inventory = $this->inventory();
        $legacyRemaining = $this->listLegacyDraftsWithRemainingItems();

        if ($legacyRemaining === [] && $inventory['canonical_shared_draft_id'] !== null) {
            return [
                'dry_run' => $dryRun,
                'canonical_shared_draft_id' => $inventory['canonical_shared_draft_id'],
                'created_shared_draft' => false,
                'moved' => 0,
                'skipped_duplicates' => 0,
                'shared_item_count_before' => $inventory['shared_draft_item_count'],
                'shared_item_count_after' => $inventory['shared_draft_item_count'],
                'expected_merged_item_count' => $inventory['expected_merged_item_count'],
                'archived_legacy_ids' => [],
                'legacy_drafts' => [],
                'item_distribution_by_site' => $inventory['shared_item_distribution_by_site'],
                'verify_ok' => true,
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'canonical_shared_draft_id' => $inventory['canonical_shared_draft_id'],
                'created_shared_draft' => false,
                'moved' => max(0, $inventory['expected_merged_item_count'] - $inventory['shared_draft_item_count']),
                'skipped_duplicates' => $inventory['duplicate_conflict_count'],
                'shared_item_count_before' => $inventory['shared_draft_item_count'],
                'shared_item_count_after' => $inventory['expected_merged_item_count'],
                'expected_merged_item_count' => $inventory['expected_merged_item_count'],
                'archived_legacy_ids' => $inventory['legacy_draft_ids'],
                'legacy_drafts' => array_map(static fn (array $r): array => [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'site_id' => $r['site_id'],
                    'item_count' => $r['item_count'],
                ], $inventory['legacy_drafts']),
                'item_distribution_by_site' => $inventory['shared_item_distribution_by_site'],
                'verify_ok' => true,
            ];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($actorId, $bootstrapSiteId): array {
            $created = false;
            $canonical = $this->resolver->findCanonicalSharedDraft();
            if (! $canonical instanceof SeoProject) {
                $siteId = $bootstrapSiteId > 0
                    ? $bootstrapSiteId
                    : $this->resolveBootstrapSiteIdFromLegacy();
                if ($siteId <= 0) {
                    throw new RuntimeException('Cannot create Shared Draft: no bootstrap site_id available.');
                }
                $canonical = $this->createSharedDraft($siteId, $actorId);
                $created = true;
            }

            // Invariant: Shared Draft must stay domain-free.
            if ($canonical->site_id !== null) {
                $canonical->forceFill(['site_id' => null])->save();
                $canonical = $canonical->fresh() ?? $canonical;
            }

            if (trim((string) $canonical->name) === '' || str_starts_with((string) $canonical->name, 'Content plan —')) {
                $canonical->forceFill(['name' => SeoProject::defaultDraftName()])->save();
            }

            $sharedId = (int) $canonical->getKey();
            $beforeCount = $this->loadActiveItems($sharedId)->count();

            $existingKeys = [];
            foreach ($this->loadActiveItems($sharedId) as $item) {
                if (! $item instanceof SeoProjectTask) {
                    continue;
                }
                $key = $this->dedupeKey($item);
                if ($key !== null) {
                    $existingKeys[$key] = true;
                }
            }

            $moved = 0;
            $skipped = 0;
            $archivedIds = [];
            $legacyRows = [];

            $legacyDrafts = $this->listLegacyDraftsWithRemainingItems();
            foreach ($legacyDrafts as $draft) {
                if (! $draft instanceof SeoProject) {
                    continue;
                }
                $draftId = (int) $draft->getKey();
                if ($draftId === $sharedId) {
                    continue;
                }

                $items = $this->loadActiveItems($draftId);
                $legacyRows[] = [
                    'id' => $draftId,
                    'name' => (string) $draft->name,
                    'site_id' => (int) ($draft->site_id ?? 0),
                    'item_count' => $items->count(),
                ];

                foreach ($items as $item) {
                    if (! $item instanceof SeoProjectTask) {
                        continue;
                    }
                    $siteId = $this->effectiveItemSiteId($item, $draft);
                    $key = $this->dedupeKey($item, $siteId);
                    if ($key !== null && isset($existingKeys[$key])) {
                        $skipped++;

                        continue;
                    }

                    $item->forceFill([
                        'project_id' => $sharedId,
                        'site_id' => $siteId > 0 ? $siteId : $item->site_id,
                    ])->save();

                    if ($key !== null) {
                        $existingKeys[$key] = true;
                    }
                    $moved++;
                }

                $draft->syncTotalTasksCounter();
                $this->archiveLegacyDraft($draft, $actorId);
                $archivedIds[] = $draftId;
            }

            $canonical->syncTotalTasksCounter();
            $afterCount = $this->loadActiveItems($sharedId)->count();
            $expected = $beforeCount + $moved;
            $verifyOk = $afterCount === $expected;

            $freshItems = $this->loadActiveItems($sharedId);

            return [
                'dry_run' => false,
                'canonical_shared_draft_id' => $sharedId,
                'created_shared_draft' => $created,
                'moved' => $moved,
                'skipped_duplicates' => $skipped,
                'shared_item_count_before' => $beforeCount,
                'shared_item_count_after' => $afterCount,
                'expected_merged_item_count' => $expected,
                'archived_legacy_ids' => $archivedIds,
                'legacy_drafts' => $legacyRows,
                'item_distribution_by_site' => $this->distributionRows($freshItems, $canonical),
                'verify_ok' => $verifyOk,
            ];
        });
    }

    /**
     * Identity-based duplicate key only.
     *
     * True duplicate = same stable source entity (e.g. same article_id) ingested twice.
     * Keyword / title / brief / text similarity are NEVER duplicates.
     *
     * @return string|null Null means "no identity" — item must always be kept/moved.
     */
    public function dedupeKey(SeoProjectTask $item, ?int $siteIdOverride = null): ?string
    {
        $articleId = (int) ($item->article_id ?? 0);
        if ($articleId > 0) {
            $siteId = $siteIdOverride ?? (int) ($item->site_id ?? 0);
            $type = SeoProjectTask::normalizeType($item->type ?? SeoProjectTask::TYPE_CREATE);

            return implode('|', ['article', (string) $siteId, $type, (string) $articleId]);
        }

        // Manual/keyword/create ideas without article_id (or pending_link/source entity):
        // do not content-dedupe — each persisted row is a valid planning task.
        return null;
    }

    /**
     * Legacy per-site drafts that still hold active items (active or archived shell).
     * Used so identity-policy reruns can rescue items left behind by keyword dedupe.
     *
     * @return list<SeoProject>
     */
    public function listLegacyDraftsWithRemainingItems(): array
    {
        $drafts = SeoProject::query()
            ->with('site:id,domain')
            ->where('status', SeoProject::STATUS_DRAFT)
            ->whereNotNull('site_id')
            ->where(function ($q): void {
                $q->whereNull('kind')->orWhere('kind', '!=', SeoProject::KIND_ARCHIVE);
            })
            ->orderBy('site_id')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (mixed $p): bool => $p instanceof SeoProject)
            ->values();

        $withItems = [];
        foreach ($drafts as $draft) {
            if ($this->loadActiveItems((int) $draft->getKey())->isEmpty()) {
                continue;
            }
            $withItems[] = $draft;
        }

        return $withItems;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SeoProjectTask>
     */
    private function loadActiveItems(int $projectId)
    {
        return SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();
    }

    private function effectiveItemSiteId(SeoProjectTask $item, SeoProject $legacyDraft): int
    {
        $itemSite = (int) ($item->site_id ?? 0);
        if ($itemSite > 0) {
            return $itemSite;
        }

        return (int) ($legacyDraft->site_id ?? 0);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectTask>  $items
     * @return array<int, array{site_id: int, domain: string, item_count: int}>
     */
    private function distributionRows($items, ?SeoProject $draft): array
    {
        $counts = [];
        foreach ($items as $item) {
            if (! $item instanceof SeoProjectTask) {
                continue;
            }
            $siteId = (int) ($item->site_id ?? 0);
            if ($siteId <= 0 && $draft instanceof SeoProject) {
                $siteId = (int) ($draft->site_id ?? 0);
            }
            $counts[$siteId] = ($counts[$siteId] ?? 0) + 1;
        }

        $rows = [];
        foreach ($counts as $siteId => $count) {
            $rows[$siteId] = [
                'site_id' => (int) $siteId,
                'domain' => $this->domainLabel((int) $siteId, null),
                'item_count' => $count,
            ];
        }
        ksort($rows);

        return $rows;
    }

    private function domainLabel(int $siteId, ?SeoProject $draft): string
    {
        if ($draft instanceof SeoProject && $draft->relationLoaded('site') && $draft->site !== null) {
            $domain = trim((string) ($draft->site->domain ?? ''));
            if ($domain !== '') {
                return $domain;
            }
        }

        if ($siteId <= 0) {
            return '(no site)';
        }

        $site = Site::query()->find($siteId);
        if ($site !== null) {
            $domain = trim((string) ($site->domain ?? ''));
            if ($domain !== '') {
                return $domain;
            }
        }

        return '#'.$siteId;
    }

    private function resolveBootstrapSiteIdFromLegacy(): int
    {
        foreach ($this->listLegacyDraftsWithRemainingItems() as $draft) {
            if ($draft instanceof SeoProject) {
                $siteId = (int) ($draft->site_id ?? 0);
                if ($siteId > 0) {
                    return $siteId;
                }
            }
        }

        foreach ($this->resolver->listLegacyPerSiteDrafts() as $draft) {
            if ($draft instanceof SeoProject) {
                $siteId = (int) ($draft->site_id ?? 0);
                if ($siteId > 0) {
                    return $siteId;
                }
            }
        }

        return 0;
    }

    private function createSharedDraft(int $bootstrapSiteId, ?int $actorId): SeoProject
    {
        return SeoProject::query()->create([
            'name' => SeoProject::defaultDraftName(),
            'site_id' => null,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'user_id' => $actorId !== null && $actorId > 0 ? $actorId : null,
            'total_tasks' => 0,
        ]);
    }

    private function archiveLegacyDraft(SeoProject $draft, ?int $actorId): void
    {
        if ($draft->archived_at !== null) {
            return;
        }

        $draft->forceFill([
            'archived_at' => now(),
            'archived_by' => $actorId !== null && $actorId > 0 ? $actorId : null,
        ])->save();
    }
}
