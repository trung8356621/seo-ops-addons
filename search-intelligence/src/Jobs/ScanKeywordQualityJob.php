<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;

final class ScanKeywordQualityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $siteId,
    ) {
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return 'keyword-quality-scan:'.$this->siteId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    public function handle(KeywordQualityService $quality): void
    {
        $quality->scanSite($this->siteId, false);
    }

    public static function releaseLocks(int $siteId): void
    {
        $job = new self($siteId);
        app(UniqueLock::class)->release($job);
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof WithoutOverlapping) {
                Cache::lock($middleware->getLockKey($job))->forceRelease();
            }
        }
    }
}
