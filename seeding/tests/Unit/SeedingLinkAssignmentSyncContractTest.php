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
 * Cross-user contract: Creator DB assignment → Topic snapshot → Seeder presentation.
 * Seeder daily progress remains local (not asserted against DB mutation).
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

        $provider = (string) file_get_contents($this->addonRoot().'/src/SeedingServiceProvider.php');
        self::assertStringContainsString('SeedingLinkAssignmentController', $provider);
        self::assertStringContainsString('/link-assignments', $provider);
        self::assertStringContainsString('import-local', $provider);

        self::assertFileExists($this->addonRoot().'/src/Models/SeedingLinkAssignment.php');
        self::assertFileExists($this->addonRoot().'/src/Services/SeedingLinkAssignmentService.php');
    }

    public function test_creator_ui_is_db_backed_not_personal_pool(): void
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
        $editor = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/AssignedLinksEditor.jsx'
        );
        $api = (string) file_get_contents($this->addonRoot().'/resources/js/seeding/api.js');
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );

        self::assertStringContainsString('Danh sách link', $panel);
        self::assertStringContainsString('createLinkAssignment', $panel);
        self::assertStringContainsString('fetchLinkAssignments', $panel);
        self::assertStringNotContainsString('Link Pool cá nhân', $panel);
        self::assertStringNotContainsString('Link của tôi', $toolbar);
        self::assertStringContainsString('Danh sách link', $toolbar);
        self::assertStringContainsString('Quản lý danh sách link', $sidebar);
        self::assertStringContainsString('fetchLinkAssignments', $editor);
        self::assertStringContainsString('data-assignment-catalog', $editor);
        self::assertStringContainsString('/api/seeding/link-assignments', $api);
        self::assertStringContainsString('linkAssignments', $workspace);
        self::assertStringContainsString('availableAssignments', $workspace);

        // Exact Seeder must not get assignment CRUD from workspace access alone.
        $auth = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/auth.js'
        );
        self::assertMatchesRegularExpression(
            '/export function canManageOwnSeedLinks[\s\S]*seeding\.seeder[\s\S]*return false/m',
            $auth,
        );
    }

    public function test_presenter_always_emits_title_and_target_per_day(): void
    {
        $links = SeedingTopicPresenter::normalizeLinks([
            [
                'id' => 'assign:42',
                'title' => 'Shopee 1',
                'url' => 'https://shopee.vn/item-1',
                'target_per_day' => 10,
            ],
            [
                'id' => 'tlink:legacy',
                'label' => 'Legacy',
                'url' => 'https://example.com/legacy',
                'target_per_day' => 3,
            ],
        ]);

        self::assertCount(2, $links);
        self::assertSame('assign:42', $links[0]['id']);
        self::assertSame('Shopee 1', $links[0]['title']);
        self::assertSame('https://shopee.vn/item-1', $links[0]['url']);
        self::assertSame(10, $links[0]['target_per_day']);
        self::assertSame('tlink:legacy', $links[1]['id']);
        self::assertSame(3, $links[1]['target_per_day']);
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

        $health = (string) file_get_contents(
            $this->addonRoot().'/src/Support/SeedingServiceHealth.php'
        );
        self::assertStringContainsString('canCreateTopics', $health);
        self::assertStringContainsString('can_manage_link_assignments', $health);
    }

    public function test_cross_user_assignment_snapshot_when_db_available(): void
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

        $ownerId = 900001;
        $seederId = 900002;
        $createdAssignment = null;
        $createdTopics = [];

        try {
            $createdAssignment = $assignments->create($ownerId, [
                'title' => 'Shopee 1',
                'url' => 'https://shopee.vn/item-contract-sync-'.uniqid('', true),
                'target_per_day' => 10,
                'is_active' => true,
            ]);

            self::assertSame('assign:'.(int) $createdAssignment->id, $createdAssignment->publicId());
            self::assertSame(10, (int) $createdAssignment->target_per_day);

            $createdTopics = $topics->share([
                'title' => 'Contract sync topic',
                'full_text' => 'Nội dung topic cho link assignment sync contract',
                'social_platform' => 'facebook',
                'target_comments' => 5,
                'links' => [
                    [
                        'id' => $createdAssignment->publicId(),
                        'url' => (string) $createdAssignment->url,
                        'title' => 'Shopee 1',
                        'target_per_day' => 10,
                    ],
                ],
                'created_by' => $ownerId,
                'created_by_display_name' => 'Creator A',
                'idempotency_key' => 'contract-link-sync-'.uniqid('', true),
            ]);

            self::assertNotEmpty($createdTopics);
            $topic = $createdTopics[0];
            self::assertInstanceOf(SeedingTopic::class, $topic);

            $json = is_array($topic->links_json) ? $topic->links_json : [];
            self::assertNotEmpty($json);
            $snap = $json[0];
            self::assertSame($createdAssignment->publicId(), $snap['id']);
            self::assertSame('Shopee 1', $snap['title'] ?? $snap['label'] ?? null);
            self::assertSame((string) $createdAssignment->url, $snap['url']);
            self::assertSame(10, (int) ($snap['target_per_day'] ?? 0));

            // Mutate master list — already-shared Topic must keep snapshot.
            $assignments->update($ownerId, (int) $createdAssignment->id, [
                'title' => 'Shopee RENAMED',
                'target_per_day' => 99,
            ]);
            $topic->refresh();
            $jsonAfter = is_array($topic->links_json) ? $topic->links_json : [];
            self::assertSame('Shopee 1', $jsonAfter[0]['title'] ?? $jsonAfter[0]['label'] ?? null);
            self::assertSame(10, (int) ($jsonAfter[0]['target_per_day'] ?? 0));

            $presented = SeedingTopicPresenter::feedItem($topic, $seederId, 0, true);
            self::assertArrayHasKey('links', $presented);
            self::assertSame('Shopee 1', $presented['links'][0]['title']);
            self::assertSame(10, (int) $presented['links'][0]['target_per_day']);
            self::assertSame($createdAssignment->publicId(), $presented['links'][0]['id']);

            // Master assignment target unchanged by any Seeder-local progress concept.
            $fresh = SeedingLinkAssignment::query()->whereKey($createdAssignment->id)->first();
            self::assertInstanceOf(SeedingLinkAssignment::class, $fresh);
            self::assertSame(99, (int) $fresh->target_per_day);
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

        self::assertStringContainsString('daily_link_progress[YYYY-MM-DD][assignment_id]', $progress);
        self::assertStringContainsString('bumpAssignmentProgress', $progress);
        self::assertStringContainsString('targetPerDay', $targets);
        self::assertStringContainsString("row.targetPerDay > 0 ? row.targetPerDay : '—'", $targets);
        self::assertStringContainsString('link.target_per_day', $selectors);
        self::assertStringContainsString('deriveTopicAssignedLinks', $selectors);
    }
}
