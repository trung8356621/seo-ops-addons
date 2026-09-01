<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthChartPresenter;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Domain chart must list every accessible site, including zero-count rows.
 *
 * @requires extension pdo_mysql
 */
final class ContentProjectMonthlyWorkloadDomainInventoryTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local mysql + omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }
    }

    public function test_six_accessible_sites_with_five_having_items_returns_six_rows_with_zero_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $month = '2026-08-01';

        $owner = User::query()->create([
            'name' => 'Domain Inventory Owner',
            'email' => 'domain-inventory-'.uniqid('', true).'@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $sites = [];
        for ($i = 0; $i < 6; $i++) {
            $sites[] = Site::query()->create([
                'user_id' => $owner->id,
                'domain' => 'inventory-'.$i.'-'.uniqid('', true).'.test',
                'status' => 'active',
            ]);
        }

        $this->actingAs($owner);

        $project = SeoProject::query()->create([
            'name' => 'Inventory project',
            'user_id' => $owner->id,
            'site_id' => null,
            'month' => $month,
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        foreach (array_slice($sites, 0, 5) as $site) {
            SeoProjectTask::query()->create([
                'project_id' => (int) $project->id,
                'site_id' => (int) $site->id,
                'type' => SeoProjectTask::TYPE_CREATE,
                'source_content' => 'inv-'.(++$this->seq),
                'keyword' => 'inv-'.$this->seq,
                'title' => 'inv-'.$this->seq,
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'target_date' => $month,
                'status' => SeoProjectTask::STATUS_PENDING,
                'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            ]);
        }

        $service = app(ContentProjectMonthlyWorkloadService::class);
        $payload = $service->forMonth($month);
        $chart = app(ContentProjectMonthChartPresenter::class)->presentDomain($payload);

        self::assertCount(6, $payload['by_domain']);
        self::assertCount(6, $chart['rows']);
        self::assertCount(6, $chart['visible_rows']);

        $zeroRows = array_values(array_filter(
            $chart['rows'],
            static fn (array $row): bool => (int) ($row['total_count'] ?? 0) === 0,
        ));
        self::assertCount(1, $zeroRows);
        self::assertSame(0.0, $zeroRows[0]['share_pct']);
        self::assertSame((int) $sites[5]->id, $zeroRows[0]['site_id']);
        self::assertSame(5, $chart['total']);

        Carbon::setTestNow();
    }
}
