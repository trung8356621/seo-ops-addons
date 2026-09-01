<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-backed list bucket regressions (requires migrated seo_projects on omi_seo_ai).
 */
final class ContentProjectListBucketRegressionTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }
    }

    public function test_all_bucket_does_not_return_draft(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $month = '2026-08-01';

        $draft = $this->seedProject('draft', SeoProject::STATUS_DRAFT, null, $month);
        $active = $this->seedProject('active', SeoProject::STATUS_PENDING, null, $month);
        $archived = $this->seedProject('archived', SeoProject::STATUS_COMPLETED, now(), $month);

        $ids = $this->bucketIds(ContentProjectListBucket::ALL, $month);

        self::assertNotContains((int) $draft->id, $ids);
        self::assertContains((int) $active->id, $ids);
        self::assertContains((int) $archived->id, $ids);

        Carbon::setTestNow();
    }

    public function test_project_bucket_excludes_draft_and_archived(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $month = '2026-08-01';

        $draft = $this->seedProject('draft', SeoProject::STATUS_DRAFT, null, $month);
        $active = $this->seedProject('active', SeoProject::STATUS_PENDING, null, $month);
        $archived = $this->seedProject('archived', SeoProject::STATUS_COMPLETED, now(), $month);

        $ids = $this->bucketIds(ContentProjectListBucket::PROJECT, $month);

        self::assertNotContains((int) $draft->id, $ids);
        self::assertContains((int) $active->id, $ids);
        self::assertNotContains((int) $archived->id, $ids);

        Carbon::setTestNow();
    }

    public function test_archived_bucket_returns_archived_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $month = '2026-08-01';

        $draft = $this->seedProject('draft', SeoProject::STATUS_DRAFT, null, $month);
        $active = $this->seedProject('active', SeoProject::STATUS_PENDING, null, $month);
        $archived = $this->seedProject('archived', SeoProject::STATUS_COMPLETED, now(), $month);

        $ids = $this->bucketIds(ContentProjectListBucket::ARCHIVED, $month);

        self::assertNotContains((int) $draft->id, $ids);
        self::assertNotContains((int) $active->id, $ids);
        self::assertContains((int) $archived->id, $ids);

        Carbon::setTestNow();
    }

    public function test_legacy_project_type_draft_query_maps_to_all_without_draft_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $month = '2026-08-01';

        $draft = $this->seedProject('draft', SeoProject::STATUS_DRAFT, null, $month);
        $active = $this->seedProject('active', SeoProject::STATUS_PENDING, null, $month);

        $normalized = ContentProjectListBucket::normalize('draft');
        self::assertSame(ContentProjectListBucket::ALL, $normalized);

        $ids = $this->bucketIds($normalized, $month);

        self::assertNotContains((int) $draft->id, $ids);
        self::assertContains((int) $active->id, $ids);

        Carbon::setTestNow();
    }

    /**
     * @return list<int>
     */
    private function bucketIds(string $bucket, string $monthDate): array
    {
        $query = SeoProject::query()
            ->where(function ($builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            });

        $query = ContentProjectListBucket::apply($query, $bucket, $monthDate);

        return $query
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function seedProject(
        string $token,
        string $status,
        ?Carbon $archivedAt,
        string $month,
    ): SeoProject {
        $this->seq++;

        return SeoProject::query()->create([
            'name' => 'Bucket '.$token.' '.$this->seq,
            'user_id' => 990_000 + $this->seq,
            'site_id' => null,
            'month' => $month,
            'status' => $status,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => $archivedAt,
            'total_tasks' => 0,
        ]);
    }
}
