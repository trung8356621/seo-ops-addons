<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupMembershipService;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;

final class RecomputeKeywordGroupMembershipsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $siteId,
        public readonly int $afterId = 0,
    ) {
        $this->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'keyword-group-recompute-'.$this->siteId.'-'.$this->afterId;
    }

    public function handle(KeywordGroupMembershipService $memberships): void
    {
        $result = $memberships->recomputeSiteChunk($this->siteId, $this->afterId, 200);
        if (! $result['done'] && $result['processed'] > 0) {
            self::dispatch($this->siteId, (int) $result['next_id']);
        }
    }
}
