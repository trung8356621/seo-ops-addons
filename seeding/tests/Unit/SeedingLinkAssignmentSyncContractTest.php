<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Models\SeedingLinkAssignment;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Services\SeedingLinkAssignmentService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * FLOW B contract: shared assignments are DB SSOT, independent of Topic/feed.
 * Seeder reads installation shared list; local progress stays in browser.
 */
final class SeedingLinkAssignmentSyncContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_migration_and_routes_exist(): void
    {
        $migration = $this->addonRoot().'/database/migrations/2026_09_24_120000_create_seeding_link_assignments_table.php';
        self::assertFileExists($migration);
        $sql = (string) file_get_contents($migration);
        self::assertStringContainsString('seeding_link_assignments', $sql);
        self::assertStringContainsString('target_per_day', $sql);
        self::assertStringContainsString('omi_seeding', $sql);
        self::assertStringNotContainsString('snapshotted into seeding_topics.links_json', $sql);

        $provider = (string) file_get_contents($this->addonRoot().'/src/SeedingServiceProvider.php');
        self::assertStringContainsString('SeedingLinkAssignmentController', $provider);
        self::assertStringContainsString('/link-assignments', $provider);

        self::assertFileExists($this->addonRoot().'/src/Models/SeedingLinkAssignment.php');
        self::assertFileExists($this->addonRoot().'/src/Services/SeedingLinkAssignmentService.php');
        $service = (string) file_get_contents($this->addonRoot().'/src/Services/SeedingLinkAssignmentService.php');
        self::assertStringContainsString('function listActiveShared', $service);
    }

    public function test_creator_ui_is_db_backed_and_seeder_reads_shared(): void
    {
        $panel = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/LinkPoolPanel.jsx'
        );
        $sidebar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/SeedingSidebar.jsx'
        );
        $toolbar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedToolbar.jsx'
        );
        $api = (string) file_get_contents($this->addonRoot().'/resources/js/seeding/api.js');
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $composer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        );
        $sharePanel = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ShareGeneratePanel.jsx'
        );

        self::assertStringContainsString('Danh sách link', $panel);
        self::assertStringContainsString('createLinkAssignment', $panel);
        self::assertStringContainsString('fetchMyLinkAssignments', $panel);
        self::assertStringNotContainsString('Link Pool cá nhân', $panel);
        self::assertStringNotContainsString('Link của tôi', $toolbar);
        self::assertStringContainsString('Danh sách link', $toolbar);
        self::assertStringContainsString('Quản lý danh sách link', $sidebar);
        self::assertStringContainsString('data-shared-assignments', $sidebar);
        self::assertStringContainsString('sharedAssignments', $sidebar);
        self::assertStringContainsString("scope', scope === 'mine'", $api);
        self::assertStringContainsString('sharedAssignments', $workspace);
        self::assertStringContainsString("fetchLinkAssignments(true, 'shared')", $workspace);
        self::assertStringContainsString('sharedAssignments={sharedAssignments}', $workspace);

        // Topic composer must not attach assignments to Topic.
        self::assertStringNotContainsString('AssignedLinksEditor', $composer);
        self::assertStringNotContainsString('availableAssignments', $composer);

        // Copy uses sharedAssignments, not topic.links.
        self::assertStringContainsString('sharedAssignmentsAsSelectable', $sharePanel);
        self::assertStringContainsString('sharedAssignments', $sharePanel);
        self::assertStringNotContainsString('topicAssignedLinksAsSelectable(topic?.links', $sharePanel);
        self::assertStringNotContainsString('topic?.links || []', $sharePanel);

        $auth = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/auth.js'
        );
        self::assertMatchesRegularExpression(
            '/export function canManageOwnSeedLinks[\s\S]*seeding\.seeder[\s\S]*return false/m',
            $auth,
        );
    }

    public function test_controller_separates_shared_read_from_mine_crud(): void
    {
        $controller = (string) file_get_contents(
            $this->addonRoot().'/src/Http/Controllers/SeedingLinkAssignmentController.php'
        );
        self::assertStringContainsString('assertCanAccess()', $controller);
        self::assertStringContainsString("scope === 'mine'", $controller);
        self::assertStringContainsString('listActiveShared', $controller);
        self::assertStringContainsString('assertCanManageLinkAssignments', $controller);

        // Mutating endpoints still gated.
        self::assertGreaterThan(
            1,
            substr_count($controller, 'assertCanManageLinkAssignments'),
        );
    }

    public function test_share_does_not_snapshot_assignments_into_topic(): void
    {
        $share = (string) file_get_contents(
            $this->addonRoot().'/src/Services/SeedingSharedTopicService.php'
        );
        self::assertStringContainsString('do not snapshot shared assignments into Topic', $share);
        self::assertStringContainsString('$links = [];', $share);

        $model = (string) file_get_contents(
            $this->addonRoot().'/src/Models/SeedingLinkAssignment.php'
        );
        self::assertStringNotContainsString('Snapshotted into Topic.links_json at share time', $model);
    }

    public function test_access_separates_create_topics_from_manager_only(): void
    {
        $access = (string) file_get_contents($this->addonRoot().'/src/Support/SeedingAccess.php');
        self::assertStringContainsString('function canCreateTopics', $access);
        self::assertStringContainsString('function canManageLinkAssignments', $access);
        self::assertStringContainsString('ROLE_TOPIC_CREATOR', $access);

        $share = (string) file_get_contents(
            $this->addonRoot().'/src/Http/Controllers/SeedingShareTopicController.php'
        );
        self::assertStringContainsString('assertCanCreateTopics', $share);
        self::assertStringNotContainsString('assertCanManage();', $share);
    }

    public function test_cross_user_shared_list_without_topic_mutation(): void
    {
        if (! class_exists(\Illuminate\Foundation\Application::class)) {
            self::markTestSkipped('Laravel application not bootstrapped in this PHPUnit path');
        }

        try {
            $app = require dirname(__DIR__, 4).'/omnichannel-client/bootstrap/app.php';
            if (! $app instanceof \Illuminate\Foundation\Application) {
                self::markTestSkipped('Could not boot omnichannel-client');
            }
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        } catch (Throwable $e) {
            self::markTestSkipped('Bootstrap unavailable: '.$e->getMessage());
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::connection(SeedingServiceConfig::CONNECTION)
                ->hasTable('seeding_link_assignments')) {
                self::markTestSkipped('seeding_link_assignments table missing — run migrations on omi_seeding');
            }
            if (! \Illuminate\Support\Facades\Schema::connection(SeedingServiceConfig::CONNECTION)
                ->hasTable('seeding_topics')) {
                self::markTestSkipped('seeding_topics table missing');
            }
        } catch (Throwable $e) {
            self::markTestSkipped('omi_seeding unavailable: '.$e->getMessage());
        }

        /** @var SeedingLinkAssignmentService $assignments */
        $assignments = app(SeedingLinkAssignmentService::class);
        /** @var SeedingSharedTopicService $topics */
        $topics = app(SeedingSharedTopicService::class);

        $ownerId = 900011;
        $createdAssignment = null;
        $createdTopics = [];

        try {
            // Existing Topic T before assignments exist.
            $createdTopics = $topics->share([
                'title' => 'Pre-existing topic before assignments',
                'full_text' => 'Topic T exists before shared assignments',
                'social_platform' => 'facebook',
                'target_comments' => 5,
                'links' => [
                    [
                        'id' => 'assign:999999',
                        'url' => 'https://example.com/should-not-persist',
                        'title' => 'STALE',
                        'target_per_day' => 3,
                    ],
                ],
                'created_by' => $ownerId,
                'created_by_display_name' => 'Creator A',
                'idempotency_key' => 'contract-pre-topic-'.uniqid('', true),
            ]);
            self::assertNotEmpty($createdTopics);
            $topic = $createdTopics[0];
            self::assertInstanceOf(SeedingTopic::class, $topic);
            $json = is_array($topic->links_json) ? $topic->links_json : [];
            self::assertSame([], $json, 'New share must not snapshot assignment payload into links_json');

            $createdAssignment = $assignments->create($ownerId, [
                'title' => 'shopee link 1',
                'url' => 'https://shopee.vn/item-contract-shared-'.uniqid('', true),
                'target_per_day' => 8,
                'is_active' => true,
            ]);
            self::assertSame('assign:'.(int) $createdAssignment->id, $createdAssignment->publicId());

            $shared = $assignments->listActiveShared(true);
            $ids = array_column($shared, 'id');
            self::assertContains($createdAssignment->publicId(), $ids);

            $hit = null;
            foreach ($shared as $row) {
                if (($row['id'] ?? '') === $createdAssignment->publicId()) {
                    $hit = $row;
                    break;
                }
            }
            self::assertNotNull($hit);
            self::assertSame(8, (int) ($hit['target_per_day'] ?? 0));

            // Mutate master — Topic must stay untouched; shared list reflects new target.
            $assignments->update($ownerId, (int) $createdAssignment->id, [
                'target_per_day' => 10,
            ]);
            $topic->refresh();
            self::assertSame([], is_array($topic->links_json) ? $topic->links_json : []);

            $sharedAfter = $assignments->listActiveShared(true);
            $hitAfter = null;
            foreach ($sharedAfter as $row) {
                if (($row['id'] ?? '') === $createdAssignment->publicId()) {
                    $hitAfter = $row;
                    break;
                }
            }
            self::assertNotNull($hitAfter);
            self::assertSame(10, (int) ($hitAfter['target_per_day'] ?? 0));

            // Legacy presenter may still read empty links_json — must not invent shared assignments.
            $presented = SeedingTopicPresenter::feedItem($topic, 900012, 0, true);
            self::assertSame([], $presented['links'] ?? []);
        } finally {
            foreach ($createdTopics as $t) {
                if ($t instanceof SeedingTopic) {
                    $t->delete();
                }
            }
            if ($createdAssignment instanceof SeedingLinkAssignment) {
                $createdAssignment->delete();
            }
        }
    }

    public function test_seeder_progress_helpers_use_assignment_id(): void
    {
        $progress = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/dailyLinkProgress.js'
        );
        $targets = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicWorkTargets.jsx'
        );
        $selectors = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/selectors.js'
        );
        $sidebar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/SeedingSidebar.jsx'
        );

        self::assertStringContainsString('daily_link_progress[YYYY-MM-DD][assignment_id]', $progress);
        self::assertStringContainsString('bumpAssignmentProgress', $progress);
        self::assertStringContainsString('sharedAssignmentsAsSelectable', $progress);
        self::assertStringContainsString('deriveSharedAssignmentRows', $selectors);
        self::assertStringContainsString('deriveSharedAssignmentRows', $sidebar);
        self::assertStringContainsString('data-topic-social', $targets);
        self::assertStringNotContainsString('deriveTopicAssignedLinks', $targets);
    }
}
