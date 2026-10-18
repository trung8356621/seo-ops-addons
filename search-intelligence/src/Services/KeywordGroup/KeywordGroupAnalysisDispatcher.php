<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Jobs\PollKeywordGroupAnalysisJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;

/**
 * Queues one async keyword-group analysis. The HTTP caller does not sync or cluster.
 */
final class KeywordGroupAnalysisDispatcher
{
    /** @var list<string> */
    public const ACTIVE = ['queued', 'syncing', 'running', 'finalizing', 'completed'];

    public function enqueue(int $siteId, ?string $language = null): string
    {
        if ($siteId <= 0) {
            return 'invalid';
        }
        $language = $this->language($language);
        if ((string) config('queue.default') === 'sync') {
            return 'sync_driver';
        }

        $lock = Cache::lock('keyword-group-analysis-dispatch:'.$siteId.':'.($language ?? ''), 15);
        if (! $lock->get()) {
            return 'busy';
        }

        try {
            $current = $this->run($siteId, $language);
            if ($current instanceof SeoKeywordGroupAnalysisRun && in_array($current->phase, self::ACTIVE, true)) {
                if ($this->queueJobExists($siteId, $language) !== false) {
                    return 'busy';
                }
                $current->phase = 'queued';
                $current->last_error = null;
                $current->save();
                PollKeywordGroupAnalysisJob::dispatch($siteId, $language ?? $current->language);

                return 'queued';
            }

            $values = [
                'run_id' => '',
                'language' => $language,
                'dataset_revision' => 0,
                'dataset_hash' => '',
                'fingerprint' => '',
                'algorithm' => '',
                'phase' => 'queued',
                'group_count' => 0,
                'member_count' => 0,
                'last_error' => null,
                'finalized_at' => null,
            ];
            if ($current instanceof SeoKeywordGroupAnalysisRun) {
                $current->fill($values);
                $current->save();
            } else {
                SeoKeywordGroupAnalysisRun::query()->create($values + ['site_id' => $siteId]);
            }

            PollKeywordGroupAnalysisJob::dispatch($siteId, $language);

            return 'queued';
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{phase: string, error: ?string, group_count: int, member_count: int, run_id: ?string, active: bool, retry: bool, worker_warning: bool}
     */
    public function status(int $siteId, ?string $language = null): array
    {
        $language = $this->language($language);
        $row = $siteId > 0 ? $this->run($siteId, $language) : null;
        $phase = $row instanceof SeoKeywordGroupAnalysisRun ? (string) $row->phase : 'idle';
        $runId = $row instanceof SeoKeywordGroupAnalysisRun && $row->run_id !== '' ? (string) $row->run_id : null;
        $orphaned = in_array($phase, self::ACTIVE, true) && $this->queueJobExists($siteId, $language) === false;

        return [
            'phase' => $phase,
            'error' => $row instanceof SeoKeywordGroupAnalysisRun ? $row->last_error : null,
            'group_count' => $row instanceof SeoKeywordGroupAnalysisRun ? (int) $row->group_count : 0,
            'member_count' => $row instanceof SeoKeywordGroupAnalysisRun ? (int) $row->member_count : 0,
            'run_id' => $runId,
            'active' => in_array($phase, self::ACTIVE, true) && ! $orphaned,
            'retry' => $orphaned || in_array($phase, ['failed', 'stale', 'superseded'], true),
            'worker_warning' => $this->workerIsLate($siteId, $language, $phase),
        ];
    }

    private function run(int $siteId, ?string $language): ?SeoKeywordGroupAnalysisRun
    {
        $query = SeoKeywordGroupAnalysisRun::query()->where('site_id', $siteId);
        if ($language === null) {
            $query->whereNull('language');
        } else {
            $query->where('language', $language);
        }
        $row = $query->first();

        return $row instanceof SeoKeywordGroupAnalysisRun ? $row : null;
    }

    private function language(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }

    private function workerIsLate(int $siteId, ?string $language, string $phase): bool
    {
        if ($siteId <= 0 || ! in_array($phase, self::ACTIVE, true) || ! Schema::hasTable('jobs')) {
            return false;
        }

        $cutoff = now()->subSeconds(20)->getTimestamp();

        return $this->jobQuery($siteId, $language)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $cutoff)
            ->exists();
    }

    private function queueJobExists(int $siteId, ?string $language): ?bool
    {
        if ($siteId <= 0 || ! Schema::hasTable('jobs')) {
            return null;
        }

        return $this->jobQuery($siteId, $language)->exists();
    }

    private function jobQuery(int $siteId, ?string $language): Builder
    {
        $query = DB::table('jobs')
            ->where('queue', 'seo')
            ->where('payload', 'like', '%PollKeywordGroupAnalysisJob%')
            ->where(function (Builder $payload) use ($siteId): void {
                foreach ($this->payloadNeedles('siteId";i:'.$siteId.';') as $needle) {
                    $payload->orWhere('payload', 'like', '%'.$needle.'%');
                }
            });
        if ($language !== null) {
            $encoded = 'language";s:'.strlen($language).':"'.$language.'";';
            $query->where(function (Builder $payload) use ($encoded): void {
                foreach ($this->payloadNeedles($encoded) as $needle) {
                    $payload->orWhere('payload', 'like', '%'.$needle.'%');
                }
            });
        }

        return $query;
    }

    /**
     * Queue payloads store PHP serialization inside JSON, so quotes are escaped.
     *
     * @return list<string>
     */
    private function payloadNeedles(string $serializedFragment): array
    {
        $escaped = str_replace('"', '\\"', $serializedFragment);
        if (DB::connection()->getDriverName() === 'mysql') {
            $escaped = str_replace('\\', '\\\\', $escaped);
        }

        return [$serializedFragment, $escaped];
    }
}
