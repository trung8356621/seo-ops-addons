<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ArticlePendingInternalLinkServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_replace_placeholder_in_html_swaps_hash_href(): void
    {
        $service = app(ArticlePendingInternalLinkService::class);

        $html = '<p>Đọc thêm <a href="#a1b2c3d4">thời trang bền vững</a> nhé.</p>';
        $next = $service->replacePlaceholderInHtml($html, 'a1b2c3d4', 'https://shop.test/thoi-trang-ben-vung');

        $this->assertStringContainsString('href="https://shop.test/thoi-trang-ben-vung"', $next);
        $this->assertStringNotContainsString('#a1b2c3d4', $next);
    }

    public function test_assign_from_editor_fails_when_keyword_already_in_other_project(): void
    {
        $this->requireSeoDatabaseConnection();

        \Illuminate\Support\Carbon::setTestNow('2026-07-13 10:00:00');

        $siteId = 2;
        $sourceArticle = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => 'Source article',
            'slug' => 'source-article',
            'body' => '<p>anchor text here</p>',
        ]);

        $otherProject = SeoProject::query()->create([
            'name' => 'Other project',
            'site_id' => $siteId,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'user_id' => 1,
            'total_tasks' => 1,
        ]);

        $phrase = 'thời trang bền vững';

        SeoProjectTask::query()->create([
            'project_id' => (int) $otherProject->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'source_content' => $phrase,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-07-01',
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        $targetProject = SeoProject::query()->create([
            'name' => 'Target project',
            'site_id' => $siteId,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'user_id' => 1,
            'total_tasks' => 0,
        ]);

        $result = app(ArticlePendingInternalLinkService::class)->assignFromEditor(
            $sourceArticle,
            $phrase,
            (int) $targetProject->id,
        );

        $this->assertFalse($result['success'] ?? true);
        $this->assertNotSame('', trim((string) ($result['message'] ?? '')));

        \Illuminate\Support\Carbon::setTestNow();
    }
}
