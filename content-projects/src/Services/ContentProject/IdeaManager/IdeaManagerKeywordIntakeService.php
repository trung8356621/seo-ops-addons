<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use App\Models\Site;
use Illuminate\Support\Collection;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Narrow intake: Keyword UI → Idea Manager workspace (manual_seed Ideas).
 *
 * - Does NOT write seo_project_tasks / Shared Planning Draft
 * - Does NOT Generate / Publish / create Topic Core
 * - Reuses IdeaWorkspacePersistenceService + IdeaWorkspaceItemOps dedupe
 * - Per-site ownership; provenance stamped at intake (source_site_id / source_domain)
 */
final class IdeaManagerKeywordIntakeService
{
    public function __construct(
        private readonly IdeaWorkspacePersistenceService $workspaces,
    ) {}

    /**
     * @param  Collection<int, Keyword>|iterable<int, Keyword>  $keywords
     * @param  list<int>  $siteIds
     */
    public function addKeywords(iterable $keywords, array $siteIds, ?int $actorId = null): IdeaManagerKeywordIntakeResult
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $records = Collection::make($keywords)
            ->filter(static fn (mixed $row): bool => $row instanceof Keyword)
            ->values();

        $normalizedSites = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $siteIds),
            static fn (int $id): bool => $id > 0,
        )));

        $keywordIds = $records->map(static fn (Keyword $k): int => (int) $k->getKey())->all();

        if ($records->isEmpty() || $normalizedSites === []) {
            return new IdeaManagerKeywordIntakeResult(
                IdeaManagerKeywordIntakeResult::STATUS_FAILED,
                message: (string) __('seo-content-ai::filament.articles_optimal.assign_failed'),
                keywordIds: $keywordIds,
            );
        }

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        $merged = [
            'added' => 0,
            'duplicate' => 0,
            'failed' => 0,
            'forbidden' => 0,
            'sites' => [],
        ];

        foreach ($normalizedSites as $siteId) {
            if (! SeoAccessControl::canAccessSite($siteId)) {
                $merged['forbidden'] += $records->count();
                $merged['failed'] += $records->count();
                continue;
            }

            $siteResult = $this->ingestIntoSite($siteId, $records, $actorId);
            $merged['added'] += (int) ($siteResult['added'] ?? 0);
            $merged['duplicate'] += (int) ($siteResult['duplicate'] ?? 0);
            $merged['failed'] += (int) ($siteResult['failed'] ?? 0);
            $merged['sites'][] = [
                'site_id' => $siteId,
                'domain' => (string) ($siteResult['domain'] ?? ''),
                'added' => (int) ($siteResult['added'] ?? 0),
                'duplicate' => (int) ($siteResult['duplicate'] ?? 0),
                'failed' => (int) ($siteResult['failed'] ?? 0),
            ];
        }

        return $this->toResult($merged, $keywordIds, $normalizedSites);
    }

    /**
     * @param  Collection<int, Keyword>  $keywords
     * @return array{added: int, duplicate: int, failed: int, domain: string}
     */
    private function ingestIntoSite(int $siteId, Collection $keywords, ?int $actorId): array
    {
        $domain = (string) (Site::query()->whereKey($siteId)->value('domain') ?? '');
        $added = 0;
        $duplicate = 0;
        $failed = 0;

        try {
            $snap = $this->workspaces->load($siteId, $actorId);
        } catch (Throwable) {
            return ['added' => 0, 'duplicate' => 0, 'failed' => $keywords->count(), 'domain' => $domain];
        }

        $items = is_array($snap['note_items'] ?? null) ? $snap['note_items'] : [];
        $revision = (int) ($snap['revision'] ?? 0);
        $toAppend = [];

        foreach ($keywords as $keyword) {
            $phrase = trim((string) ($keyword->phrase ?? $keyword->keyword ?? ''));
            if ($phrase === '') {
                $failed++;
                continue;
            }

            $made = IdeaWorkspaceItemOps::makeManualSeed($phrase, array_merge($items, $toAppend));
            if ($made['duplicate'] || $made['item'] === null) {
                $duplicate++;
                continue;
            }

            $item = $made['item'];
            $item['source_site_id'] = $siteId;
            if ($domain !== '') {
                $item['source_domain'] = $domain;
            }
            // Re-normalize so provenance survives AuditNoteDnaNormalizer allow-list.
            $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
            if ($normalized === null) {
                $failed++;
                continue;
            }
            $toAppend[] = $normalized;
            $added++;
        }

        if ($toAppend === []) {
            return ['added' => 0, 'duplicate' => $duplicate, 'failed' => $failed, 'domain' => $domain];
        }

        $merged = array_merge($items, $toAppend);
        $save = $this->workspaces->save($siteId, $merged, $revision, $actorId);
        $status = (string) ($save['status'] ?? 'error');

        if ($status === IdeaWorkspacePersistenceService::STATUS_CONFLICT) {
            try {
                $snap = $this->workspaces->load($siteId, $actorId);
            } catch (Throwable) {
                return ['added' => 0, 'duplicate' => $duplicate, 'failed' => $failed + $added, 'domain' => $domain];
            }
            $items = is_array($snap['note_items'] ?? null) ? $snap['note_items'] : [];
            $revision = (int) ($snap['revision'] ?? 0);
            $retryAppend = [];
            $retryAdded = 0;
            $retryDup = 0;
            foreach ($toAppend as $candidate) {
                $name = (string) ($candidate['cluster_name_snapshot'] ?? $candidate['seed_text'] ?? '');
                if (IdeaWorkspaceItemOps::findDuplicate($items, null, $name) !== null
                    || IdeaWorkspaceItemOps::findDuplicate($retryAppend, null, $name) !== null) {
                    $retryDup++;
                    continue;
                }
                $retryAppend[] = $candidate;
                $retryAdded++;
            }
            if ($retryAppend === []) {
                return [
                    'added' => 0,
                    'duplicate' => $duplicate + $retryDup,
                    'failed' => $failed,
                    'domain' => $domain,
                ];
            }
            $save = $this->workspaces->save($siteId, array_merge($items, $retryAppend), $revision, $actorId);
            $status = (string) ($save['status'] ?? 'error');
            if (in_array($status, [
                IdeaWorkspacePersistenceService::STATUS_OK,
                IdeaWorkspacePersistenceService::STATUS_NOOP,
            ], true)) {
                return [
                    'added' => $retryAdded,
                    'duplicate' => $duplicate + $retryDup,
                    'failed' => $failed,
                    'domain' => $domain,
                ];
            }

            return [
                'added' => 0,
                'duplicate' => $duplicate + $retryDup,
                'failed' => $failed + $retryAdded,
                'domain' => $domain,
            ];
        }

        if (! in_array($status, [
            IdeaWorkspacePersistenceService::STATUS_OK,
            IdeaWorkspacePersistenceService::STATUS_NOOP,
        ], true)) {
            return [
                'added' => 0,
                'duplicate' => $duplicate,
                'failed' => $failed + $added,
                'domain' => $domain,
            ];
        }

        return ['added' => $added, 'duplicate' => $duplicate, 'failed' => $failed, 'domain' => $domain];
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  list<int>  $keywordIds
     * @param  list<int>  $siteIds
     */
    private function toResult(array $merged, array $keywordIds, array $siteIds): IdeaManagerKeywordIntakeResult
    {
        $added = (int) ($merged['added'] ?? 0);
        $duplicate = (int) ($merged['duplicate'] ?? 0);
        $failed = (int) ($merged['failed'] ?? 0);

        if ($added > 0 && $failed === 0 && $duplicate === 0) {
            return new IdeaManagerKeywordIntakeResult(
                IdeaManagerKeywordIntakeResult::STATUS_ADDED,
                $merged,
                (string) __('seo-content-ai::filament.article_list.add_to_idea_manager_completed_body', [
                    'added' => $added,
                ]),
                $keywordIds,
                $siteIds,
            );
        }

        if ($added === 0 && $duplicate > 0 && $failed === 0) {
            return new IdeaManagerKeywordIntakeResult(
                IdeaManagerKeywordIntakeResult::STATUS_ALREADY,
                $merged,
                (string) __('seo-content-ai::filament.article_list.already_in_idea_manager'),
                $keywordIds,
                $siteIds,
            );
        }

        if ($added > 0) {
            return new IdeaManagerKeywordIntakeResult(
                IdeaManagerKeywordIntakeResult::STATUS_PARTIAL,
                $merged,
                (string) __('seo-content-ai::filament.article_list.add_to_idea_manager_summary_body', [
                    'added' => $added,
                    'duplicate' => $duplicate,
                    'failed' => $failed,
                ]),
                $keywordIds,
                $siteIds,
            );
        }

        return new IdeaManagerKeywordIntakeResult(
            IdeaManagerKeywordIntakeResult::STATUS_FAILED,
            $merged,
            (string) __('seo-content-ai::filament.articles_optimal.assign_failed'),
            $keywordIds,
            $siteIds,
        );
    }
}
