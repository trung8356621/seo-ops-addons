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
    public function test_projects_hide_unready_domains_and_do_not_leak_them(): void
    {
        $this->bindReadiness([7]);
        $controller = new AgentRuntimeController();
        $response = $controller->projects($this->request(), new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
            ['id' => 8, 'domain' => 'hidden.test', 'user_id' => 1],
        ]));
        $json = json_encode($response->getData(true), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('ready.test', $json);
        self::assertStringNotContainsString('hidden.test', $json);
        self::assertStringNotContainsString('"siteId":8', $json);
        self::assertStringNotContainsString('site:8', $json);
    }

    public function test_empty_ready_catalog_returns_no_site_entries(): void
    {
        $this->bindReadiness([]);
        $response = (new AgentRuntimeController())->projects($this->request(), new MemorySites([
            ['id' => 8, 'domain' => 'hidden.test', 'user_id' => 1],
        ]));
        $projects = $response->getData(true)['data']['projects'];
        $types = array_column($projects, 'type');

        self::assertNotContains('site', $types);
        self::assertStringNotContainsString('hidden.test', json_encode($projects, JSON_THROW_ON_ERROR));
    }

    public function test_unready_and_unauthorized_site_id_cannot_bypass_turn(): void
    {
        $this->bindReadiness([7]);
        $sites = new MemorySites([
            ['id' => 7, 'domain' => 'ready.test', 'user_id' => 1],
            ['id' => 8, 'domain' => 'hidden.test', 'user_id' => 1],
        ]);
        $controller = new AgentRuntimeController();

        $unready = $controller->turn(
            $this->request(['scope' => ['type' => 'site', 'siteId' => 8], 'message' => 'audit']),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator::class),
            $sites,
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class),
        );
        self::assertSame(403, $unready->getStatusCode());
        self::assertStringNotContainsString('hidden', (string) $unready->getContent());

        $foreign = $controller->turn(
            $this->request(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'audit'], 2),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator::class),
            $sites,
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class),
        );
        self::assertSame(403, $foreign->getStatusCode());
    }

    public function test_content_manager_catalog_stays_assigned_without_site_selector(): void
    {
        $this->bindReadiness([]);
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
            $this->bindReadiness([7]);
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

    public function test_widget_explains_empty_semantic_catalog_without_disabled_domains(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/widget/AgentWidget.jsx');
        self::assertStringContainsString('Keywords → Keyword Groups', $source);
        self::assertStringContainsString('semanticGroupingEmpty', $source);
        self::assertStringNotContainsString('disabled={semanticGroupingEmpty}', $source);
    }

    /**
     * @param  list<int>  $readyIds
     */
    private function bindReadiness(array $readyIds): void
    {
        $this->app->instance(AgentSemanticSiteReadiness::class, new class($readyIds) implements AgentSemanticSiteReadiness {
            /** @param  list<int>  $readyIds */
            public function __construct(private array $readyIds) {}

            public function isReady(int $siteId): bool
            {
                return in_array($siteId, $this->readyIds, true);
            }

            public function filterReadySiteIds(array $siteIds): array
            {
                return array_values(array_filter(
                    $siteIds,
                    fn (int $siteId): bool => $this->isReady($siteId),
                ));
            }
        });
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
