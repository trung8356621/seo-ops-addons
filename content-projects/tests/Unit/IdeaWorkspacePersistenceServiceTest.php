<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

/**
 * @group mysql
 */
final class IdeaWorkspacePersistenceServiceTest extends TestCase
{
    protected $connectionsToTransact = ['omi_seo_ai'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! filter_var((string) env('SEO_TEST_USE_MYSQL', ''), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_idea_workspaces')) {
            $this->markTestSkipped('Missing seo_content_project_idea_workspaces — run migration first.');
        }
    }

    public function test_save_load_noop_and_conflict(): void
    {
        $siteId = 910001;
        SeoContentProjectIdeaWorkspace::query()->where('site_id', $siteId)->delete();

        $this->partialMockAccess($siteId);

        $svc = app(IdeaWorkspacePersistenceService::class);
        $empty = $svc->load($siteId, 1);
        self::assertSame(0, $empty['revision']);
        self::assertSame([], $empty['note_items']);

        $made = IdeaWorkspaceItemOps::makeManualSeed('persistence idea', []);
        self::assertNotNull($made['item']);
        $items = [$made['item']];

        $saved = $svc->save($siteId, $items, 0, 1);
        self::assertSame(IdeaWorkspacePersistenceService::STATUS_OK, $saved['status']);
        self::assertSame(1, $saved['revision']);

        $noop = $svc->save($siteId, $items, 1, 1);
        self::assertSame(IdeaWorkspacePersistenceService::STATUS_NOOP, $noop['status']);
        self::assertSame(1, $noop['revision']);

        $conflict = $svc->save($siteId, $items, 0, 1);
        self::assertSame(IdeaWorkspacePersistenceService::STATUS_CONFLICT, $conflict['status']);
        self::assertSame(1, $conflict['revision']);

        $loaded = $svc->load($siteId, 1);
        self::assertSame(1, $loaded['revision']);
        self::assertCount(1, $loaded['note_items']);

        SeoContentProjectIdeaWorkspace::query()->where('site_id', $siteId)->delete();
    }

    private function partialMockAccess(int $siteId): void
    {
        // SeoAccessControl is static — rely on authenticated test user with site access when available.
        // If canAccessSite fails in this environment, skip rather than invent auth.
        if (! SeoAccessControl::canManageContentProjectWorkflow() || ! SeoAccessControl::canAccessSite($siteId)) {
            $this->markTestSkipped('Test actor lacks Content Project workflow / site access for synthetic site_id.');
        }
    }
}
