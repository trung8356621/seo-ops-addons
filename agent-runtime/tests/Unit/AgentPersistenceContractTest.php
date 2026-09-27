<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

final class AgentPersistenceContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        }
    }

    public function test_app_registration_is_idempotent(): void
    {
        $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        
        $count = AgentApp::where('app_key', 'seo-ops')->count();
        self::assertSame(1, $count);
    }

    public function test_same_user_can_create_multiple_threads_for_same_scope(): void
    {
        $app = AgentApp::findByKey('seo-ops');
        
        AgentThread::create(['ulid' => 'u1', 'agent_app_id' => $app->id, 'principal_type' => 'user', 'principal_ref' => '1', 'scope_type' => 'site', 'scope_ref' => 'site:1']);
        AgentThread::create(['ulid' => 'u2', 'agent_app_id' => $app->id, 'principal_type' => 'user', 'principal_ref' => '1', 'scope_type' => 'site', 'scope_ref' => 'site:1']);
        AgentThread::create(['ulid' => 'u3', 'agent_app_id' => $app->id, 'principal_type' => 'user', 'principal_ref' => '1', 'scope_type' => 'site', 'scope_ref' => 'site:1']);

        $count = AgentThread::where('principal_ref', '1')->count();
        self::assertSame(3, $count);
    }

    public function test_another_user_cannot_access_thread(): void
    {
        $app = AgentApp::findByKey('seo-ops');
        $repo = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);

        $thread = $repo->createThread($app, 'user', '1', 1, 1, 'global', 'global');
        
        $found = $repo->findForPrincipal($thread->ulid, 'user', '2');
        self::assertNull($found);
    }

    public function test_thread_list_is_principal_scoped(): void
    {
        $app = AgentApp::findByKey('seo-ops');
        $repo = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);

        $repo->createThread($app, 'user', '1', 1, 1, 'global', 'global');
        $repo->createThread($app, 'user', '2', 2, 2, 'global', 'global');

        $list = $repo->listForPrincipal('user', '1');
        self::assertSame(1, $list->total());
    }

    private function turnRequest(array $payload, int $userId = 1): Request
    {
        $request = Request::create('/agent-runtime/turns', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        $user = new class($userId) {
            public function __construct(public int $id) {}
        };
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function setupRunDependencies()
    {
        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $sites->method('listActiveSites')->willReturn([['id' => 7, 'domain' => 'example.test']]);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        return [$sites, $coordinator];
    }

    public function test_send_persists_user_message(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi', [], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            true
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $message = AgentMessage::where('role', 'user')->first();
        self::assertNotNull($message);
        self::assertSame('Hello', $message->content);
    }

    public function test_successful_send_persists_assistant_response(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi there', [['type' => 'text']], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            true
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $message = AgentMessage::where('role', 'assistant')->first();
        self::assertNotNull($message);
        self::assertSame('Hi there', $message->content);
        self::assertSame('text', $message->response_payload['blocks'][0]['type']);
    }

    public function test_successful_send_marks_run_completed(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi', [], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            true
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $run = AgentRun::first();
        self::assertNotNull($run);
        self::assertSame('done', $run->status);
        self::assertNotNull($run->finished_at);
        self::assertSame('called', $run->answer_model);
    }

    public function test_failure_marks_run_failed_without_assistant_message(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $coordinator->method('send')->willThrowException(new \Exception('Test Error'));

        $controller = new AgentRuntimeController();
        try {
            $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
            self::fail('Expected exception');
        } catch (\Exception $e) {
            self::assertSame('Test Error', $e->getMessage());
        }

        $run = AgentRun::first();
        self::assertNotNull($run);
        self::assertSame('failed', $run->status);
        self::assertSame('error', $run->failure_code);
        self::assertNotNull($run->finished_at);

        $assistantMessageCount = AgentMessage::where('role', 'assistant')->count();
        self::assertSame(0, $assistantMessageCount);
    }

    public function test_structured_blocks_survive_persistence_round_trip(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi', [['type' => 'chart', 'data' => [1, 2, 3]]], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            true
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $message = AgentMessage::where('role', 'assistant')->first();
        self::assertSame('chart', $message->response_payload['blocks'][0]['type']);
        self::assertSame([1, 2, 3], $message->response_payload['blocks'][0]['data']);
    }

    public function test_copy_does_not_create_message_or_run(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi', [], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            false
        );
        $coordinator->method('copy')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->modelInput($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites);

        self::assertSame(0, AgentRun::count());
        self::assertSame(0, AgentMessage::count());
        self::assertSame(0, AgentThread::count());
    }

    public function test_all_sites_unsupported_persists_graceful_failure(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Hi', [['type' => 'text'], ['type' => 'warning', 'text' => 'global_access_unsupported']], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            false
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'global'], 'message' => 'Hello']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $run = AgentRun::first();
        self::assertNotNull($run);
        self::assertSame('done', $run->status);
        self::assertSame('global_access_unsupported', $run->failure_code);
    }

    public function test_site_scope_creates_thread_and_messages(): void
    {
        [$sites, $coordinator] = $this->setupRunDependencies();
        $result = new \Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult(
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponse('Site info', [], [], []),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            $this->createMock(\Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput::class),
            true
        );
        $coordinator->method('send')->willReturn($result);

        $controller = new AgentRuntimeController();
        $controller->turn($this->turnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Hello site']), $coordinator, $sites, app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));

        $thread = AgentThread::first();
        self::assertNotNull($thread);
        self::assertSame('site', $thread->scope_type);
        self::assertSame('site:7', $thread->scope_ref);
        self::assertSame(2, $thread->messages()->count());
    }
}
