<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnProgress;
use Omnichannel\Addons\AgentRuntime\Runtime\InterceptedModelCall;
use Tests\TestCase;

final class TierRecordingGateway implements AnswerModelGateway
{
    public int $calls = 0;
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function complete(int $userId, PreparedModelInput $input, array $options = []): string
    {
        $this->calls++;
        $this->lastOptions = $options;

        return '{"message": "Answer completed", "blocks": [{"type": "markdown", "text": "Answer completed"}]}';
    }
}

final class TierRecordingResolver implements AssumedModelResolver
{
    public ?AiRoutingPolicy $lastRoutingPolicy = null;

    public function resolveDecisionModel(int $userId): AssumedModelMetadata
    {
        return new AssumedModelMetadata(
            stage: 'decision',
            profile: 'decision.route',
            provider: 'test',
            model: 'test-decision',
            displayName: 'Test Decision',
            fallbacks: [],
            routingMode: 'priority_order',
            status: 'available',
        );
    }

    public function resolveAnswerModel(int $userId, ?AiRoutingPolicy $routingPolicy = null): AssumedModelMetadata
    {
        $this->lastRoutingPolicy = $routingPolicy;

        $modelName = match ($routingPolicy) {
            AiRoutingPolicy::FreeOnly => 'free-model',
            AiRoutingPolicy::QuickFree => 'quick-free-model',
            AiRoutingPolicy::Normal => 'master-model',
            default => 'default-model',
        };

        return new AssumedModelMetadata(
            stage: 'answer',
            profile: 'text.reasoning',
            provider: 'test',
            model: $modelName,
            displayName: ucfirst($modelName),
            fallbacks: [],
            routingMode: 'text.reasoning',
            status: 'available',
        );
    }
}

class AgentTierRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        }
    }
    public function test_ai_routing_policy_free_only_never_allows_paid_routes(): void
    {
        $freePolicy = AiRoutingPolicy::FreeOnly;
        $this->assertFalse($freePolicy->allowsPaidRoutes());

        $proPolicy = AiRoutingPolicy::QuickFree;
        $this->assertTrue($proPolicy->allowsPaidRoutes());
        $this->assertSame(1, $proPolicy->freeAttemptCap());

        $masterPolicy = AiRoutingPolicy::Normal;
        $this->assertTrue($masterPolicy->allowsPaidRoutes());
        $this->assertNull($masterPolicy->freeAttemptCap());
    }

    public function test_turn_rejects_invalid_tier(): void
    {
        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create('/agent/turn', 'POST', [
            'message' => 'Hello test',
            'scope' => ['type' => 'site', 'ref' => 'site:1', 'siteId' => 1],
            'tier' => 'ultra_mega_tier',
        ]);
        $request->setUserResolver(fn () => $user);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threads = $this->createMock(AgentThreadRepository::class);
        $persistence = $this->createMock(AgentTurnPersistence::class);

        $response = $controller->turn($request, $coordinator, $sites, $threads, $persistence);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Invalid tier.', $data['message']);
    }

    public function test_coordinator_propagates_free_tier_and_routing_policy(): void
    {
        $gateway = new TierRecordingGateway();
        $resolver = new TierRecordingResolver();
        $this->app->instance(AssumedModelResolver::class, $resolver);

        $coordinatorMethod = new \ReflectionMethod(AgentTurnCoordinator::class, 'send');
        $params = $coordinatorMethod->getParameters();
        $this->assertCount(7, $params);
        $this->assertSame('routingPolicy', $params[5]->getName());
        $this->assertSame('tier', $params[6]->getName());

        $answerConfirmedMethod = new \ReflectionMethod(AgentTurnCoordinator::class, 'answerConfirmed');
        $acParams = $answerConfirmedMethod->getParameters();
        $this->assertCount(9, $acParams);
        $this->assertSame('routingPolicy', $acParams[7]->getName());
        $this->assertSame('tier', $acParams[8]->getName());
    }

    public function test_tier_mapping_consistency(): void
    {
        $map = [
            'free' => AiRoutingPolicy::FreeOnly,
            'pro' => AiRoutingPolicy::QuickFree,
            'master' => AiRoutingPolicy::Normal,
        ];

        foreach ($map as $tier => $expectedPolicy) {
            $mapped = match ($tier) {
                'free' => AiRoutingPolicy::FreeOnly,
                'pro' => AiRoutingPolicy::QuickFree,
                'master' => AiRoutingPolicy::Normal,
                default => null,
            };
            $this->assertSame($expectedPolicy, $mapped);
        }
    }

    public function test_pause_run_preserves_retrieval_summary_tier_and_routing_policy(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('pause-test');
        $run = $this->createRun($thread, $userMessage, 'run-pause-1', 'pro', 'quick_free');

        $persistence = new AgentTurnPersistence();
        $persistence->pauseRun($run, 'answer', ['state' => 'data']);

        $refreshed = $run->fresh();
        $this->assertSame('awaiting_model', $refreshed->status);
        $this->assertSame('pro', $refreshed->retrieval_summary['tier']);
        $this->assertSame('quick_free', $refreshed->retrieval_summary['routing_policy']);
        $this->assertSame('answer', $refreshed->retrieval_summary['model_call']);
        $this->assertSame(['state' => 'data'], $refreshed->retrieval_summary['runtime_state']);
    }

    private function createThreadAndMessage(string $ulidPrefix = 'tier-test'): array
    {
        $app = AgentApp::findByKey('seo-ops');
        if (! $app) {
            $app = AgentApp::create([
                'app_key' => 'seo-ops',
                'name' => 'SEO Operations',
            ]);
        }
        $ulid = $ulidPrefix . '-' . bin2hex(random_bytes(6));
        $thread = AgentThread::create([
            'ulid' => $ulid,
            'agent_app_id' => $app->id,
            'principal_type' => 'user',
            'principal_ref' => '1',
            'user_id' => 1,
            'owner_id' => 1,
            'scope_type' => 'site',
            'scope_ref' => 'site:1',
            'title' => 'Test Thread',
        ]);
        $message = AgentMessage::create([
            'ulid' => 'msg-' . bin2hex(random_bytes(6)),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Test query',
            'position' => 1,
        ]);

        return [$thread, $message];
    }

    private function createRun(AgentThread $thread, AgentMessage $message, string $ulid, ?string $tier = null, ?string $policy = null): AgentRun
    {
        $summary = [];
        if ($tier !== null) {
            $summary['tier'] = $tier;
        }
        if ($policy !== null) {
            $summary['routing_policy'] = $policy;
        }

        return AgentRun::create([
            'ulid' => $ulid,
            'app_key' => 'seo-ops',
            'thread_id' => $thread->id,
            'user_message_id' => $message->id,
            'scope_type' => $thread->scope_type,
            'scope_ref' => $thread->scope_ref,
            'user_id' => 1,
            'status' => 'done',
            'retrieval_summary' => $summary,
        ]);
    }

    public function test_rerun_preserves_free_tier_even_if_master_tier_supplied(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('free-run');
        $this->createRun($thread, $userMessage, 'run-free-1', 'free', 'free_only');

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        // Even if composer dropdown supplies 'master', rerun MUST preserve original 'free' tier
        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', [
            'tier' => 'master',
        ]);
        $request->setUserResolver(fn () => $user);

        $capturedPolicy = null;
        $capturedTier = null;
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($uid, $scope, $msg, $hist, $diag, $policy, $tier) use (&$capturedPolicy, &$capturedTier) {
                $capturedPolicy = $policy;
                $capturedTier = $tier;
                return new AgentTurnResult(
                    new AgentResponse('Done', [], [], []),
                    new PreparedModelInput('decision', []),
                    null,
                    false,
                );
            });

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('free', $capturedTier);
        $this->assertSame(AiRoutingPolicy::FreeOnly, $capturedPolicy);
    }

    public function test_rerun_preserves_pro_tier_even_if_free_tier_supplied(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('pro-run');
        $this->createRun($thread, $userMessage, 'run-pro-1', 'pro', 'quick_free');

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', [
            'tier' => 'free',
        ]);
        $request->setUserResolver(fn () => $user);

        $capturedPolicy = null;
        $capturedTier = null;
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($uid, $scope, $msg, $hist, $diag, $policy, $tier) use (&$capturedPolicy, &$capturedTier) {
                $capturedPolicy = $policy;
                $capturedTier = $tier;
                return new AgentTurnResult(
                    new AgentResponse('Done', [], [], []),
                    new PreparedModelInput('decision', []),
                    null,
                    false,
                );
            });

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pro', $capturedTier);
        $this->assertSame(AiRoutingPolicy::QuickFree, $capturedPolicy);
    }

    public function test_rerun_preserves_master_tier(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('master-run');
        $this->createRun($thread, $userMessage, 'run-master-1', 'master', 'normal');

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', []);
        $request->setUserResolver(fn () => $user);

        $capturedPolicy = null;
        $capturedTier = null;
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($uid, $scope, $msg, $hist, $diag, $policy, $tier) use (&$capturedPolicy, &$capturedTier) {
                $capturedPolicy = $policy;
                $capturedTier = $tier;
                return new AgentTurnResult(
                    new AgentResponse('Done', [], [], []),
                    new PreparedModelInput('decision', []),
                    null,
                    false,
                );
            });

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('master', $capturedTier);
        $this->assertSame(AiRoutingPolicy::Normal, $capturedPolicy);
    }

    public function test_rerun_of_rerun_preserves_original_tier(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('chain-run');
        // Initial run
        $this->createRun($thread, $userMessage, 'run-chain-1', 'free', 'free_only');
        // First rerun
        $this->createRun($thread, $userMessage, 'run-chain-2', 'free', 'free_only');

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        // Second rerun
        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', []);
        $request->setUserResolver(fn () => $user);

        $capturedTier = null;
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($uid, $scope, $msg, $hist, $diag, $policy, $tier) use (&$capturedTier) {
                $capturedTier = $tier;
                return new AgentTurnResult(
                    new AgentResponse('Done', [], [], []),
                    new PreparedModelInput('decision', []),
                    null,
                    false,
                );
            });

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('free', $capturedTier);
    }

    public function test_rerun_rejects_invalid_supplied_tier(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('invalid-tier-rerun');

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', [
            'tier' => 'super_ultra_tier',
        ]);
        $request->setUserResolver(fn () => $user);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Invalid tier.', $data['message']);
    }

    public function test_rerun_preserves_legacy_run_without_tier(): void
    {
        [$thread, $userMessage] = $this->createThreadAndMessage('legacy-run');
        $this->createRun($thread, $userMessage, 'run-legacy-1'); // Legacy run: no tier stored

        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create("/agent/threads/{$thread->ulid}/messages/{$userMessage->id}/rerun", 'POST', []);
        $request->setUserResolver(fn () => $user);

        $capturedPolicy = 'not-called';
        $capturedTier = 'not-called';
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($uid, $scope, $msg, $hist, $diag, $policy, $tier) use (&$capturedPolicy, &$capturedTier) {
                $capturedPolicy = $policy;
                $capturedTier = $tier;
                return new AgentTurnResult(
                    new AgentResponse('Done', [], [], []),
                    new PreparedModelInput('decision', []),
                    null,
                    false,
                );
            });

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = new AgentTurnPersistence();
        $resolver = new TierRecordingResolver();

        $response = $controller->rerun($request, $thread->ulid, (int) $userMessage->id, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($capturedTier);
        $this->assertNull($capturedPolicy);
    }

    public function test_debug_mode_preserves_tier_and_resolves_answer_model_with_routing_policy(): void
    {
        $controller = new AgentRuntimeController();
        $user = new class {
            public int $id = 1;
            public function accountOwnerId(): int { return 1; }
        };

        $request = Request::create('/agent/turn', 'POST', [
            'message' => 'Debug test',
            'scope' => ['type' => 'site', 'ref' => 'site:1', 'siteId' => 1],
            'debug_mode' => true,
            'tier' => 'free',
        ]);
        $request->setUserResolver(fn () => $user);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('startIntercepted')
            ->willReturn(AgentTurnProgress::paused(new InterceptedModelCall('answer', new PreparedModelInput('answer', []), [])));

        $sites = $this->createMock(SiteDirectory::class);
        $sites->method('isSiteVisible')->willReturn(true);
        $threadsRepo = app(AgentThreadRepository::class);
        $persistence = app(AgentTurnPersistence::class);
        $resolver = new TierRecordingResolver();

        $response = $controller->turn($request, $coordinator, $sites, $threadsRepo, $persistence, $resolver);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];

        $this->assertSame('paused', $data['status']);
        $this->assertSame(AiRoutingPolicy::FreeOnly, $resolver->lastRoutingPolicy);
        $this->assertSame('free-model', $data['model_call']['assumed_model']['model']);
    }
}

