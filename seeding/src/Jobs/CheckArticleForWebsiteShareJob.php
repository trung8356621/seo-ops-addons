<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Models\WebsiteShareJob;
use Omnichannel\Addons\Seeding\Services\WebsiteShareJobService;
use Throwable;

/**
 * After delay: recheck article index via SEO DB (no Content class import), then promote.
 */
final class CheckArticleForWebsiteShareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $websiteShareJobId,
    ) {}

    public function handle(WebsiteShareJobService $service): void
    {
        $job = WebsiteShareJob::query()->find($this->websiteShareJobId);
        if (! $job instanceof WebsiteShareJob) {
            return;
        }

        $stillIndexed = $this->recheckIndexed((int) $job->article_id);
        $service->promoteIfStillIndexed($this->websiteShareJobId, $stillIndexed);
    }

    private function recheckIndexed(int $articleId): bool
    {
        if ($articleId <= 0) {
            return false;
        }

        try {
            $connection = 'omi_seo_ai';
            if (! Schema::connection($connection)->hasTable('articles')
                && ! Schema::connection($connection)->hasTable('seo_article_profiles')) {
                return false;
            }

            if (Schema::connection($connection)->hasTable('seo_article_profiles')) {
                $indexedAt = DB::connection($connection)
                    ->table('seo_article_profiles')
                    ->where('article_id', $articleId)
                    ->value('indexed_at');
                if ($indexedAt !== null && $indexedAt !== '') {
                    return true;
                }
            }

            if (Schema::connection($connection)->hasTable('articles')
                && Schema::connection($connection)->hasColumn('articles', 'indexed_at')) {
                $indexedAt = DB::connection($connection)
                    ->table('articles')
                    ->where('id', $articleId)
                    ->value('indexed_at');

                return $indexedAt !== null && $indexedAt !== '';
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
