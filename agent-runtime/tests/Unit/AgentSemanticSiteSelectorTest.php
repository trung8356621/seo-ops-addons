<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Projects\AgentSemanticSiteReadiness;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class AgentSemanticSiteSelectorTest extends TestCase
{
    public function test_projects_keep_authorized_domains_before_readiness(): void
    {
        $readiness = $this->bindReadiness();
        $response = (new AgentRuntimeController())->projects($this->request(), new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
            ['id' => 8, 'domain' => 'waiting.test', 'user_id' => 1],
        ]));
        $json = json_encode($response->getData(true), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('ready.test', $json);
        self::assertStringContainsString('waiting.test', $json);
        self::assertSame([], $readiness->refreshCalls);
    }

    public function test_projects_hide_only_a_processing_domain(): void
    {
        $this->bindReadiness(processing: [8]);
        $response = (new AgentRuntimeController())->projects($this->request(), new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
            ['id' => 8, 'domain' => 'running.test', 'user_id' => 1],
        ]));
        $json = json_encode($response->getData(true), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('ready.test', $json);
        self::assertStringNotContainsString('running.test', $json);
        self::assertStringNotContainsString('"siteId":8', $json);
    }

    public function test_unready_turn_asks_for_confirmation_and_does_not_dispatch(): void
    {
        $readiness = $this->bindReadiness();
        $sites = new MemorySites([
            ['id' => 8, 'domain' => 'waiting.test', 'user_id' => 1],
        ]);
        $response = (new AgentRuntimeController())->turn(
            $this->request([
                'scope' => ['type' => 'site', 'siteId' => 8],
                'message' => 'audit',
                'semantic_ready' => true,
            ]),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator::class),
            $sites,
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class),
        );
        $data = $response->getData(true)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('semantic_grouping_required', $data['status']);
        self::assertSame('Website này chưa hoàn tất phân nhóm từ khóa ngữ nghĩa. Bạn có muốn đồng bộ ngay để sử dụng AI Agent không?', $data['message']);
        self::assertSame(['sync', 'later'], array_column($data['actions'], 'action'));
        self::assertSame([], $readiness->refreshCalls);
    }

    public function test_confirming_grouping_dispatches_once_and_a_repeat_stays_processing(): void
    {
        $readiness = $this->bindReadiness();
        $sites = new MemorySites([
            ['id' => 8, 'domain' => 'waiting.test', 'user_id' => 1],
        ]);
        $controller = new AgentRuntimeController();

        $first = $controller->prepareSemanticGrouping($this->request(), 8, $sites)->getData(true)['data'];
        $second = $controller->prepareSemanticGrouping($this->request(), 8, $sites)->getData(true)['data'];

        self::assertSame('accepted', $first['status']);
        self::assertSame('processing', $second['status']);
        self::assertSame([8, 8], $readiness->refreshCalls);
        self::assertTrue($readiness->isProcessing(8));
    }

    public function test_unauthorized_site_is_rejected_before_grouping(): void
    {
        $readiness = $this->bindReadiness(ready: [7]);
        $sites = new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
        ]);
        $controller = new AgentRuntimeController();
        $turn = $controller->turn(
            $this->request(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'audit'], 2),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator::class),
            $sites,
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class),
        );
        $prepare = $controller->prepareSemanticGrouping($this->request([], 2), 7, $sites);

        self::assertSame(403, $turn->getStatusCode());
        self::assertSame(403, $prepare->getStatusCode());
        self::assertSame([], $readiness->refreshCalls);
    }

    public function test_content_manager_catalog_stays_assigned_without_site_selector(): void
    {
        $this->bindReadiness();
        $this->app->instance(SeoRoleAssignment::class, new class {
            public function resolveShortRank(mixed $user): string
            {
                return SeoAccessControl::ROLE_CONTENT_MANAGER;
            }
        });
        $user = new User();
        $user->id = 4;
        $user->role = 'staff';
        $response = (new AgentRuntimeController())->projects($this->request([], $user), new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 4],
        ]));
        $projects = $response->getData(true)['data']['projects'];

        self::assertSame('assigned', $projects[0]['type']);
        self::assertCount(1, $projects);
        self::assertStringNotContainsString('ready.test', json_encode($projects, JSON_THROW_ON_ERROR));
    }

    public function test_manager_and_planner_authorization_still_gates_before_readiness(): void
    {
        $resolver = (string) file_get_contents(dirname(__DIR__, 2).'/src/Access/AgentActorAccessResolver.php');
        $access = (string) file_get_contents(dirname(__DIR__, 3).'/seo/src/Support/SeoAccessControl.php');
        self::assertStringNotContainsString('AgentSemanticSiteReadiness', $resolver);
        self::assertStringNotContainsString('KeywordGroupSemanticReadiness', $access);

        foreach ([SeoAccessControl::ROLE_MANAGER, SeoAccessControl::ROLE_PLANNER] as $role) {
            $this->app->instance(SeoRoleAssignment::class, new class($role) {
                public function __construct(private string $role) {}

                public function resolveShortRank(mixed $user): string
                {
                    return $this->role;
                }
            });
            $this->bindReadiness();
            $user = new User();
            $user->id = 1;
            $user->role = 'owner';
            $response = (new AgentRuntimeController())->projects($this->request([], $user), new MemorySites([
                ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
                ['id' => 9, 'domain' => 'other.test', 'user_id' => 99],
            ]));
            $json = json_encode($response->getData(true), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('ready.test', $json);
            self::assertStringNotContainsString('other.test', $json);
        }
    }

    public function test_widget_confirms_grouping_without_hiding_unready_domains(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/widget/AgentWidget.jsx');
        self::assertStringContainsString('semantic_grouping_required', $source);
        self::assertStringContainsString("action.action === 'later'", $source);
        self::assertStringContainsString('/semantic-grouping', $source);
        self::assertStringContainsString('setWatchedSiteId(siteId)', $source);
        self::assertStringNotContainsString('semanticGroupingEmpty', $source);
        self::assertStringNotContainsString('semantic_ready', $source);
    }

    /**
     * @param  list<int>  $ready
     * @param  list<int>  $processing
     */
    private function bindReadiness(array $ready = [], array $processing = []): RecordingSemanticReadiness
    {
        $readiness = new RecordingSemanticReadiness($ready, $processing);
        $this->app->instance(AgentSemanticSiteReadiness::class, $readiness);

        return $readiness;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload = [], User|int|null $user = 1): Request
    {
        $request = Request::create('/agent-runtime/projects', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
        if ($user instanceof User) {
            $request->setUserResolver(static fn () => $user);
        } elseif (is_int($user)) {
            $request->setUserResolver(static fn () => new class($user) {
                public function __construct(public int $id) {}
            });
        }

        return $request;
    }
}

final class RecordingSemanticReadiness implements AgentSemanticSiteReadiness
{
    /** @var list<int> */
    public array $refreshCalls = [];

    /**
     * @param  list<int>  $readyIds
     * @param  list<int>  $processingIds
     */
    public function __construct(
        private array $readyIds = [],
        private array $processingIds = [],
    ) {}

    public function isReady(int $siteId): bool
    {
        return in_array($siteId, $this->readyIds, true);
    }

    public function filterReadySiteIds(array $siteIds): array
    {
        return array_values(array_filter($siteIds, fn (int $siteId): bool => $this->isReady($siteId)));
    }

    public function isProcessing(int $siteId): bool
    {
        return in_array($siteId, $this->processingIds, true);
    }

    public function filterProcessingSiteIds(array $siteIds): array
    {
        return array_values(array_filter($siteIds, fn (int $siteId): bool => $this->isProcessing($siteId)));
    }

    public function enforcementAvailable(): bool
    {
        return true;
    }

    public function requestRefresh(int $siteId): string
    {
        $this->refreshCalls[] = $siteId;
        if ($this->isReady($siteId)) {
            return 'ready';
        }
        if ($this->isProcessing($siteId)) {
            return 'processing';
        }
        $this->processingIds[] = $siteId;

        return 'accepted';
    }
}

final class MemorySites implements SiteDirectory
{
    /**
     * @param  list<array{id: int, domain: string, user_id?: int}>  $sites
     */
    public function __construct(private array $sites) {}

    public function listActiveSites(?int $userId = null): array
    {
        $out = [];
        foreach ($this->sites as $site) {
            if ($userId !== null && isset($site['user_id']) && (int) $site['user_id'] !== $userId) {
                continue;
            }
            $out[] = ['id' => (int) $site['id'], 'domain' => (string) $site['domain']];
        }

        return $out;
    }

    public function isSiteVisible(int $siteId, ?int $userId = null): bool
    {
        foreach ($this->listActiveSites($userId) as $site) {
            if ($site['id'] === $siteId) {
                return true;
            }
        }

        return false;
    }
}
