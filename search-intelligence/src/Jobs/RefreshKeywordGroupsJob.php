<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupSemanticRefreshService;

/**
 * Group semantic refresh. Persists Groups only — never Topic apply.
 */
final class RefreshKeywordGroupsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function __construct(
        public readonly int $siteId,
        public readonly ?string $language = null,
        public readonly ?array $languageVariants = null,
    ) {
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return 'keyword-group-refresh:'.$this->siteId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('keyword-group-refresh:'.$this->siteId))
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    public function handle(KeywordGroupSemanticRefreshService $refresh): void
    {
        $refresh->refreshSite($this->siteId, $this->language, $this->languageVariants);
    }
}
