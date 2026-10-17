<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisException;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisFinalizer;

/**
 * One short poll. The next poll is queued before work starts, so a worker
 * restart does not drop the chain. The shared seo worker uses --tries=1.
 */
final class PollKeywordGroupAnalysisJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $timeout = 120;

    public int $tries = 1;

    public string $recoveryToken = '';

    public function __construct(
        public readonly int $siteId,
        public readonly ?string $language = null,
    ) {
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return 'keyword-group-analysis:'.$this->siteId.':'.($this->language ?? '');
    }

    public function handle(KeywordGroupAnalysisFinalizer $finalizer): void
    {
        $token = $this->scheduleFollowUp();
        try {
            $step = $finalizer->advance($this->siteId, $this->language);
        } catch (KeywordGroupAnalysisException $e) {
            if ($e->reason === 'transport') {
                $this->noteTransportFailure($token);

                return;
            }
            $this->cancelFollowUp($token);
            throw $e;
        }

        Cache::forget($this->transportKey());
        if (! $step->pollAgain) {
            $this->cancelFollowUp($token);
        }
    }

    private function scheduleFollowUp(): string
    {
        $token = bin2hex(random_bytes(8));
        $next = new self($this->siteId, $this->language);
        $next->recoveryToken = $token;
        dispatch($next)->delay(30);

        return $token;
    }

    private function noteTransportFailure(string $token): void
    {
        $tries = (int) Cache::increment($this->transportKey());
        Cache::put($this->transportKey(), $tries, 7200);
        if ($tries <= 8) {
            return;
        }

        Cache::forget($this->transportKey());
        $this->cancelFollowUp($token);
        $row = SeoKeywordGroupAnalysisRun::query()->where('site_id', $this->siteId)->first();
        if ($row instanceof SeoKeywordGroupAnalysisRun && $row->phase !== 'finalized') {
            $row->phase = 'failed';
            $row->last_error = 'semantic service unreachable';
            $row->save();
        }
    }

    private function cancelFollowUp(string $token): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->where('queue', 'seo')
                ->whereNull('reserved_at')
                ->where('payload', 'like', '%'.$token.'%')
                ->delete();
        }
        app(UniqueLock::class)->release(new self($this->siteId, $this->language));
    }

    private function transportKey(): string
    {
        return 'keyword-group-analysis-transport:'.$this->siteId;
    }
}
