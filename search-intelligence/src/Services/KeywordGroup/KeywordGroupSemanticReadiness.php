<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AgentRuntime\Projects\AgentSemanticSiteReadiness;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupSemanticState;
use Throwable;

/**
 * Persistent result of RefreshKeywordGroupsJob.
 *
 * A site is ready only when ready_at is set and invalidated_at is empty.
 * A later refresh failure does not clear ready_at. Invalidation does.
 * An empty eligible keyword set is a completed run and becomes ready.
 */
final class KeywordGroupSemanticReadiness implements AgentSemanticSiteReadiness
{
    /**
     * Matches RefreshKeywordGroupsJob uniqueness. Older pending/running rows
     * are recoverable and must not hide a site forever.
     */
    private const PROCESSING_TTL_SECONDS = 3600;
    public function markPending(int $siteId): void
    {
        $this->mutate($siteId, static function (SeoKeywordGroupSemanticState $row): void {
            $row->phase = SeoKeywordGroupSemanticState::PHASE_PENDING;
        });
    }

    public function markRunning(int $siteId): void
    {
        $this->mutate($siteId, static function (SeoKeywordGroupSemanticState $row): void {
            $row->phase = SeoKeywordGroupSemanticState::PHASE_RUNNING;
        });
    }

    public function markSucceeded(int $siteId): void
    {
        $this->mutate($siteId, static function (SeoKeywordGroupSemanticState $row): void {
            $row->phase = SeoKeywordGroupSemanticState::PHASE_SUCCEEDED;
            $row->ready_at = now();
            $row->invalidated_at = null;
            $row->last_error = null;
        });
    }

    public function markFailed(int $siteId, string $error): void
    {
        $error = trim($error);
        $this->mutate($siteId, static function (SeoKeywordGroupSemanticState $row) use ($error): void {
            $row->phase = SeoKeywordGroupSemanticState::PHASE_FAILED;
            $row->last_error = $error === '' ? null : mb_substr($error, 0, 255);
        });
    }

    public function invalidate(int $siteId): void
    {
        $this->mutate($siteId, static function (SeoKeywordGroupSemanticState $row): void {
            $row->ready_at = null;
            $row->invalidated_at = now();
        });
    }

    public function revokeIfNoSemanticGroups(int $siteId): void
    {
        if ($siteId <= 0 || ! $this->tableReady() || ! $this->groupsReady()) {
            return;
        }

        $semanticRemains = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->where('source', KeywordGroupSource::SEMANTIC)
            ->exists();
        if ($semanticRemains) {
            return;
        }

        $this->invalidate($siteId);
    }

    /**
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    public function run(int $siteId, callable $work): mixed
    {
        $this->markRunning($siteId);
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->markFailed($siteId, $e->getMessage());
            throw $e;
        }

        $this->markSucceeded($siteId);

        return $result;
    }

    public function isReady(int $siteId): bool
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return false;
        }

        return SeoKeywordGroupSemanticState::query()
            ->where('site_id', $siteId)
            ->whereNotNull('ready_at')
            ->whereNull('invalidated_at')
            ->exists();
    }

    public function isProcessing(int $siteId): bool
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return false;
        }

        return SeoKeywordGroupSemanticState::query()
            ->where('site_id', $siteId)
            ->whereIn('phase', [
                SeoKeywordGroupSemanticState::PHASE_PENDING,
                SeoKeywordGroupSemanticState::PHASE_RUNNING,
            ])
            ->where('updated_at', '>=', now()->subSeconds(self::PROCESSING_TTL_SECONDS))
            ->exists();
    }

    public function filterProcessingSiteIds(array $siteIds): array
    {
        $ids = [];
        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            if ($siteId > 0) {
                $ids[$siteId] = $siteId;
            }
        }
        if ($ids === [] || ! $this->tableReady()) {
            return [];
        }

        return SeoKeywordGroupSemanticState::query()
            ->whereIn('site_id', array_values($ids))
            ->whereIn('phase', [
                SeoKeywordGroupSemanticState::PHASE_PENDING,
                SeoKeywordGroupSemanticState::PHASE_RUNNING,
            ])
            ->where('updated_at', '>=', now()->subSeconds(self::PROCESSING_TTL_SECONDS))
            ->orderBy('site_id')
            ->pluck('site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function enforcementAvailable(): bool
    {
        return $this->tableReady();
    }

    public function requestRefresh(int $siteId): string
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return 'unavailable';
        }

        $decision = DB::connection('omi_seo_ai')->transaction(function () use ($siteId): string {
            $row = SeoKeywordGroupSemanticState::query()
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->first();
            if ($row instanceof SeoKeywordGroupSemanticState && $row->ready_at !== null && $row->invalidated_at === null) {
                return 'ready';
            }
            if ($row instanceof SeoKeywordGroupSemanticState && $this->rowIsFreshProcessing($row)) {
                return 'processing';
            }
            if (! $row instanceof SeoKeywordGroupSemanticState) {
                $row = SeoKeywordGroupSemanticState::query()->create([
                    'site_id' => $siteId,
                    'phase' => SeoKeywordGroupSemanticState::PHASE_PENDING,
                ]);
            }
            $row->phase = SeoKeywordGroupSemanticState::PHASE_PENDING;
            $row->save();

            return 'accepted';
        });

        if ($decision !== 'accepted') {
            return $decision;
        }

        try {
            RefreshKeywordGroupsJob::dispatch($siteId);
        } catch (Throwable $e) {
            $this->markFailed($siteId, $e->getMessage());

            throw $e;
        }

        return 'accepted';
    }

    public function filterReadySiteIds(array $siteIds): array
    {
        $ids = [];
        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            if ($siteId > 0) {
                $ids[$siteId] = $siteId;
            }
        }
        if ($ids === [] || ! $this->tableReady()) {
            return [];
        }

        return SeoKeywordGroupSemanticState::query()
            ->whereIn('site_id', array_values($ids))
            ->whereNotNull('ready_at')
            ->whereNull('invalidated_at')
            ->orderBy('site_id')
            ->pluck('site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function rowIsFreshProcessing(SeoKeywordGroupSemanticState $row): bool
    {
        if (! in_array($row->phase, [
            SeoKeywordGroupSemanticState::PHASE_PENDING,
            SeoKeywordGroupSemanticState::PHASE_RUNNING,
        ], true)) {
            return false;
        }

        $updatedAt = $row->updated_at;

        return $updatedAt !== null && $updatedAt->greaterThanOrEqualTo(now()->subSeconds(self::PROCESSING_TTL_SECONDS));
    }

    private function tableReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_group_semantic_states');
        } catch (Throwable) {
            return false;
        }
    }

    private function groupsReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_groups');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  callable(SeoKeywordGroupSemanticState): void  $change
     */
    private function mutate(int $siteId, callable $change): void
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $change): void {
            $row = SeoKeywordGroupSemanticState::query()
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->first();
            if (! $row instanceof SeoKeywordGroupSemanticState) {
                $row = SeoKeywordGroupSemanticState::query()->create([
                    'site_id' => $siteId,
                    'phase' => SeoKeywordGroupSemanticState::PHASE_PENDING,
                ]);
                $row = SeoKeywordGroupSemanticState::query()
                    ->whereKey($row->getKey())
                    ->lockForUpdate()
                    ->first();
            }
            if (! $row instanceof SeoKeywordGroupSemanticState) {
                return;
            }

            $change($row);
            $row->save();
        });
    }
}
