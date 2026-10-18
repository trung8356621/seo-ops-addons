<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceLanguageScope;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;

/**
 * Dataset sync, short run polling, and MySQL finalize.
 *
 * One call polls once. It does not wait for clustering to finish.
 */
final class KeywordGroupAnalysisFinalizer
{
    public function __construct(
        private readonly SemanticAnalyticsClient $client,
        private readonly KeywordGroupDatasetSyncService $sync,
        private readonly KeywordGroupDatasetSnapshotBuilder $snapshots,
        private readonly KeywordGroupSemanticRefreshService $publisher,
        private readonly ?KeywordGroupSemanticReadiness $readiness = null,
    ) {}

    public function advance(int $siteId, ?string $language = null): KeywordGroupAnalysisStep
    {
        $language = $this->language($language);
        $current = $this->current($siteId, $language);
        if ($current === null || $current->run_id === '' || in_array($current->phase, ['failed', 'stale', 'superseded'], true)) {
            return $this->start($siteId, $language);
        }
        if ($current->phase === 'finalized') {
            return $this->step($current, false);
        }

        return $this->poll($current, $language);
    }

    public function start(int $siteId, ?string $language = null): KeywordGroupAnalysisStep
    {
        $language = $this->language($language);
        $this->touchPhase($siteId, 'syncing', $language);
        try {
            $synced = $this->sync->syncSite($siteId, $language, $this->variants($language));
        } catch (KeywordGroupDatasetSyncException $e) {
            if ($e->reason === 'transport') {
                throw new KeywordGroupAnalysisException($e->getMessage(), 'transport', $e);
            }

            return $this->failCurrent($siteId, $e->getMessage(), $language);
        }
        if ($synced->status === 'empty' || $synced->datasetHash === null) {
            return $this->failCurrent($siteId, 'no eligible keywords', $language);
        }

        $current = $this->current($siteId, $language);
        if (
            $current !== null
            && $current->phase === 'finalized'
            && $current->dataset_hash === $synced->datasetHash
        ) {
            return $this->step($current, false);
        }

        $runBody = ['scope_ref' => (string) $siteId];
        if ($language !== null) {
            $runBody['language'] = $language;
        }
        $remote = $this->attempt(fn (): array => $this->client->postJson(
            '/v1/keyword-groups/runs',
            $runBody,
            'keyword-group-run-'.$siteId.':'.($language ?? ''),
            $this->timeoutSeconds(),
        ));
        $run = $this->store($siteId, $remote, $current, $language);
        if ($run->phase === 'completed') {
            return $this->poll($run, $language);
        }

        return $this->step($run, $this->waits($run->phase));
    }

    private function poll(SeoKeywordGroupAnalysisRun $run, ?string $language): KeywordGroupAnalysisStep
    {
        try {
            $remote = $this->attempt(fn (): array => $this->client->getJson(
                '/v1/keyword-groups/runs/'.$run->run_id,
                'keyword-group-run-'.$run->run_id,
                $this->timeoutSeconds(),
            ));
        } catch (SemanticTransportException $e) {
            if ($e->httpStatus === 404) {
                return $this->fail($run, 'python run is missing');
            }
            throw new KeywordGroupAnalysisException($e->getMessage(), 'transport', $e);
        } catch (SemanticHttpException $e) {
            throw new KeywordGroupAnalysisException($e->getMessage(), 'transport', $e);
        }

        if ((string) ($remote['scope_ref'] ?? '') !== (string) $run->site_id) {
            return $this->fail($run, 'run scope does not match the site');
        }
        if ((string) ($remote['dataset_hash'] ?? '') !== $run->dataset_hash) {
            return $this->fail($run, 'run dataset hash does not match the stored run');
        }
        if ((int) ($remote['dataset_revision'] ?? 0) !== (int) $run->dataset_revision) {
            return $this->fail($run, 'run dataset revision does not match the stored run');
        }
        if ((string) ($remote['fingerprint'] ?? '') !== $run->fingerprint) {
            return $this->fail($run, 'run fingerprint does not match the stored run');
        }

        $status = (string) ($remote['status'] ?? '');
        if ($status === 'queued' || $status === 'running') {
            $run->phase = $status;
            $run->last_error = null;
            $run->save();

            return $this->step($run, true);
        }
        if ($status === 'failed') {
            return $this->fail($run, (string) ($remote['error'] ?? 'python analysis failed'));
        }
        if ($status !== 'completed') {
            return $this->fail($run, 'unexpected run status');
        }

        $latest = $this->current($run->site_id, $run->language);
        if ($latest === null || $latest->run_id !== $run->run_id) {
            return new KeywordGroupAnalysisStep('superseded', $run->run_id, false);
        }

        $run->phase = 'finalizing';
        $run->save();
        try {
            $groups = $this->pages($run, $remote);
        } catch (KeywordGroupAnalysisException $e) {
            return $this->fail($run, $e->getMessage());
        } catch (SemanticHttpException $e) {
            throw new KeywordGroupAnalysisException($e->getMessage(), 'transport', $e);
        }

        $lock = Cache::lock('keyword-group-analysis-finalize:'.$run->site_id, 120);
        if (! $lock->get()) {
            return $this->step($run, true);
        }
        try {
            $fresh = $this->current($run->site_id, $run->language);
            if ($fresh === null || $fresh->run_id !== $run->run_id) {
                return new KeywordGroupAnalysisStep('superseded', $run->run_id, false);
            }
            if ($fresh->phase === 'finalized') {
                return $this->step($fresh, false);
            }
            $items = $this->snapshots->build($run->site_id, $run->language, $this->variants($run->language));
            $hash = KeywordGroupDatasetCanonical::datasetHash((string) $run->site_id, $run->language, $items);
            if ($hash !== $run->dataset_hash) {
                $fresh->phase = 'stale';
                $fresh->last_error = 'dataset changed before finalize';
                $fresh->save();

                return $this->step($fresh, false);
            }
            $known = [];
            foreach ($items as $item) {
                $known[(int) $item['ref']] = true;
            }
            $written = $this->publisher->publishAnalysisResponse($run->site_id, [
                'status' => 'completed',
                'input_hash' => $run->dataset_hash,
                'diagnostics' => ['algorithm' => $run->algorithm],
                'groups' => $groups,
            ], $known, $run->language);
            $fresh->phase = 'finalized';
            $fresh->group_count = $written['groups'];
            $fresh->member_count = $written['members'];
            $fresh->last_error = null;
            $fresh->finalized_at = now();
            $fresh->save();
            $this->readiness?->markSucceeded($run->site_id);

            return $this->step($fresh, false);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return list<array<string, mixed>>
     */
    private function pages(SeoKeywordGroupAnalysisRun $run, array $remote): array
    {
        $expectedGroups = (int) ($remote['group_count'] ?? -1);
        $expectedMembers = (int) ($remote['member_count'] ?? -1);
        $groups = [];
        $seenGroups = [];
        $seenMembers = [];
        $cursor = 0;
        $guard = 0;
        do {
            $page = $this->attempt(fn (): array => $this->client->getJson(
                '/v1/keyword-groups/runs/'.$run->run_id.'/groups?cursor='.$cursor.'&limit=100',
                null,
                $this->timeoutSeconds(),
            ));
            if ((string) ($page['status'] ?? '') !== 'completed') {
                throw new KeywordGroupAnalysisException('group page is not completed', 'page');
            }
            if ((string) ($page['scope_ref'] ?? '') !== (string) $run->site_id) {
                throw new KeywordGroupAnalysisException('group page scope mismatch', 'page');
            }
            if ((string) ($page['input_hash'] ?? '') !== $run->dataset_hash) {
                throw new KeywordGroupAnalysisException('group page hash mismatch', 'page');
            }
            if ((string) ($page['algorithm'] ?? '') !== $run->algorithm) {
                throw new KeywordGroupAnalysisException('group page algorithm mismatch', 'page');
            }
            $batch = $page['groups'] ?? null;
            if (! is_array($batch)) {
                throw new KeywordGroupAnalysisException('group page has no groups array', 'page');
            }
            foreach ($batch as $group) {
                if (! is_array($group)) {
                    throw new KeywordGroupAnalysisException('group page entry is malformed', 'page');
                }
                $ref = (string) ($group['group_ref'] ?? '');
                if ($ref === '' || isset($seenGroups[$ref])) {
                    throw new KeywordGroupAnalysisException('duplicate or empty group ref', 'page');
                }
                $seenGroups[$ref] = true;
                foreach ($group['members'] ?? [] as $member) {
                    if (! is_array($member)) {
                        throw new KeywordGroupAnalysisException('group member is malformed', 'page');
                    }
                    $keywordRef = (string) ($member['ref'] ?? '');
                    if ($keywordRef === '' || isset($seenMembers[$keywordRef])) {
                        throw new KeywordGroupAnalysisException('duplicate keyword ref in pages', 'page');
                    }
                    $seenMembers[$keywordRef] = true;
                }
                $groups[] = $group;
            }
            $next = $page['next_cursor'] ?? null;
            if ($next !== null && (int) $next <= $cursor) {
                throw new KeywordGroupAnalysisException('group page cursor did not advance', 'page');
            }
            $cursor = $next === null ? null : (int) $next;
            $guard++;
        } while ($cursor !== null && $guard < 1000);

        if (count($groups) !== $expectedGroups || count($seenMembers) !== $expectedMembers) {
            throw new KeywordGroupAnalysisException('downloaded groups do not match run counts', 'page');
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function store(int $siteId, array $remote, ?SeoKeywordGroupAnalysisRun $current, ?string $language): SeoKeywordGroupAnalysisRun
    {
        $phase = (string) ($remote['status'] ?? '');
        if (! in_array($phase, ['queued', 'running', 'completed', 'failed'], true)) {
            $phase = 'queued';
        }
        $values = [
            'run_id' => (string) ($remote['run_id'] ?? ''),
            'language' => $this->language($language),
            'dataset_revision' => (int) ($remote['dataset_revision'] ?? 0),
            'dataset_hash' => (string) ($remote['dataset_hash'] ?? ''),
            'fingerprint' => (string) ($remote['fingerprint'] ?? ''),
            'algorithm' => (string) ($remote['algorithm'] ?? ''),
            'phase' => $phase,
            'group_count' => (int) ($remote['group_count'] ?? 0),
            'member_count' => (int) ($remote['member_count'] ?? 0),
            'last_error' => null,
            'finalized_at' => null,
        ];
        if ($values['run_id'] === '' || $values['dataset_hash'] === '' || $values['fingerprint'] === '') {
            throw new KeywordGroupAnalysisException('run response is missing identity fields', 'contract');
        }
        if ($current === null) {
            return SeoKeywordGroupAnalysisRun::query()->create($values + ['site_id' => $siteId]);
        }
        $current->fill($values);
        $current->save();

        return $current;
    }

    private function touchPhase(int $siteId, string $phase, ?string $language): void
    {
        $row = $this->current($siteId, $language);
        if ($row === null || $row->phase === 'finalized') {
            return;
        }
        $row->phase = $phase;
        $row->save();
    }

    private function failCurrent(int $siteId, string $error, ?string $language): KeywordGroupAnalysisStep
    {
        $row = $this->current($siteId, $language);
        if ($row === null) {
            return new KeywordGroupAnalysisStep('failed', null, false, error: $error);
        }

        return $this->fail($row, $error);
    }

    private function fail(SeoKeywordGroupAnalysisRun $run, string $error): KeywordGroupAnalysisStep
    {
        $run->phase = 'failed';
        $run->last_error = mb_substr(trim($error), 0, 255);
        $run->save();

        return $this->step($run, false);
    }

    private function current(int $siteId, ?string $language): ?SeoKeywordGroupAnalysisRun
    {
        $language = $this->language($language);
        $query = SeoKeywordGroupAnalysisRun::query()->where('site_id', $siteId);
        if ($language === null) {
            $query->whereNull('language');
        } else {
            $query->where('language', $language);
        }
        $row = $query->first();

        return $row instanceof SeoKeywordGroupAnalysisRun ? $row : null;
    }

    /**
     * @return list<string>|null
     */
    private function variants(?string $language): ?array
    {
        if ($language === null || $language === '') {
            return null;
        }

        return KeywordWorkspaceLanguageScope::variantsForCode($language);
    }

    private function step(SeoKeywordGroupAnalysisRun $run, bool $pollAgain): KeywordGroupAnalysisStep
    {
        return new KeywordGroupAnalysisStep(
            $run->phase,
            $run->run_id,
            $pollAgain,
            (int) $run->group_count,
            (int) $run->member_count,
            $run->last_error,
        );
    }

    private function waits(string $phase): bool
    {
        return $phase === 'queued' || $phase === 'running';
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function attempt(callable $call): array
    {
        $last = null;
        for ($try = 1; $try <= 3; $try++) {
            try {
                return $call();
            } catch (SemanticUnavailableException|SemanticTimeoutException $e) {
                $last = $e;
            }
        }

        throw new KeywordGroupAnalysisException(
            $last instanceof SemanticHttpException ? $last->getMessage() : 'semantic request failed',
            'transport',
            $last,
        );
    }

    private function language(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('semantic.keyword_group_dataset_timeout', 60));
    }
}
