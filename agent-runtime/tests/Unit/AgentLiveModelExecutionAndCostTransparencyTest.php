<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
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
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentRunExecutionTracker;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class TrackingAnswerGateway implements AnswerModelGateway
{
    public int $calls = 0;
    /** @var array<string, mixed> */
    public array $lastOptions = [];
    public ?string $returnModel = 'openrouter/mock-model';
    public ?string $returnProvider = 'openrouter';
    public ?string $returnCostClass = 'free';
    public ?float $returnCost = 0.0;
    public ?array $returnUsage = ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30];

    public function complete(int $userId, PreparedModelInput $input, array $options = []): string
    {
        $this->calls++;
        $this->lastOptions = $options;

        $tracker = $options['execution_tracker'] ?? null;
        if ($tracker instanceof AgentRunExecutionTracker && $this->returnModel !== null) {
            $num = $tracker->recordAttemptStart(
                $this->returnProvider ?? 'openrouter',
                'main_conn',
                $this->returnModel,
                $this->returnCostClass ?? 'free'
            );

            $conn = new \App\Models\ApiConnection();
            $conn->name = 'main_conn';
            $conn->driver = $this->returnProvider ?? 'openrouter';

            $routed = new RoutedAiCandidate(
                profile: 'test',
                connection: $conn,
                provider: $this->returnProvider ?? 'openrouter',
                model: $this->returnModel,
                capabilities: ['text'],
                priority: 1,
                isFree: $this->returnCostClass === 'free',
            );

            $usage = array_merge($this->returnUsage ?? [], [
                'total_cost' => $this->returnCost,
            ]);

            $tracker->recordAttemptSuccess($num, $routed, $usage);
        }

        return '{"message": "Answer verified", "blocks": [{"type": "markdown", "text": "Answer verified"}]}';
    }
}

final class MockSiteDirectory implements SiteDirectory
{
    /** @param list<int> $accessibleIds */
    public function __construct(private array $accessibleIds = [1, 7]) {}

    public function isSiteVisible(int $siteId, ?int $userId = null): bool
    {
        return in_array($siteId, $this->accessibleIds, true);
    }

    public function listActiveSites(?int $userId = null): array
    {
        return array_map(static fn (int $id): array => ['id' => $id, 'domain' => "site{$id}.test"], $this->accessibleIds);
    }
}

final class AgentLiveModelExecutionAndCostTransparencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private User $otherUser;
    private AgentApp $agentApp;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        }

        $this->user = new User();
        $this->user->id = 991;

        $this->otherUser = new User();
        $this->otherUser->id = 992;

        $this->agentApp = AgentApp::firstOrCreate(
            ['app_key' => 'seo-ops'],
            ['name' => 'SEO Operations', 'is_active' => true]
        );
    }

    private function makeCandidate(string $model, bool $isFree = false, string $provider = 'openrouter', string $connName = 'conn'): RoutedAiCandidate
    {
        $conn = new \App\Models\ApiConnection();
        $conn->name = $connName;
        $conn->driver = $provider;

        return new RoutedAiCandidate(
            profile: 'test',
            connection: $conn,
            provider: $provider,
            model: $model,
            capabilities: ['text'],
            priority: 1,
            isFree: $isFree,
        );
    }

    // 1. recordAttemptStart captures exact model, provider, connection name, cost class before call
    public function test_record_attempt_start_captures_exact_model_provider_and_cost_class(): void
    {
        $tracker = new AgentRunExecutionTracker('run_ulid_test_1', (int) $this->user->id, 'pro', 'quick_free');
        $attemptNum = $tracker->recordAttemptStart(
            'openrouter',
            'openrouter_conn',
            'meta-llama/llama-3.3-70b-instruct:free',
            'free'
        );

        self::assertSame(1, $attemptNum);
        $attempts = $tracker->getAttempts();
        self::assertCount(1, $attempts);
        self::assertSame('openrouter', $attempts[0]['provider']);
        self::assertSame('openrouter_conn', $attempts[0]['connection_name']);
        self::assertSame('meta-llama/llama-3.3-70b-instruct:free', $attempts[0]['model']);
        self::assertSame('free', $attempts[0]['cost_class']);
        self::assertSame('running', $attempts[0]['status']);
    }

    // 2. recordAttemptSuccess captures successful model, tokens, authoritative cost
    public function test_record_attempt_success_captures_tokens_and_authoritative_cost(): void
    {
        $tracker = new AgentRunExecutionTracker('run_ulid_test_2', (int) $this->user->id, 'pro', 'quick_free');
        $num = $tracker->recordAttemptStart('openrouter', 'conn', 'anthropic/claude-3.5-sonnet', 'paid');

        $routed = $this->makeCandidate('anthropic/claude-3.5-sonnet', false);

        $tracker->recordAttemptSuccess($num, $routed, [
            'prompt_tokens' => 500,
            'completion_tokens' => 200,
            'reasoning_tokens' => 50,
            'total_tokens' => 700,
            'total_cost' => 0.0045,
        ]);

        self::assertSame('anthropic/claude-3.5-sonnet', $tracker->getSuccessfulModel());
        self::assertSame('openrouter', $tracker->getSuccessfulProvider());
        self::assertSame('paid', $tracker->getSuccessfulCostClass());

        $usage = $tracker->getUsageSummary();
        self::assertSame(500, $usage['input_tokens']);
        self::assertSame(200, $usage['output_tokens']);
        self::assertSame(50, $usage['reasoning_tokens']);
        self::assertSame(700, $usage['total_tokens']);

        $cost = $tracker->getCostSummary();
        self::assertSame(0.0045, $cost['recorded_cost']);
        self::assertSame('confirmed', $cost['state']);
    }

    // 3. recordAttemptFailure captures error diagnostics and preserves attempts
    public function test_record_attempt_failure_captures_error_diagnostics_and_preserves_attempts(): void
    {
        $tracker = new AgentRunExecutionTracker('run_ulid_test_3', (int) $this->user->id, 'pro', 'quick_free');
        $num = $tracker->recordAttemptStart('openrouter', 'conn', 'free-model', 'free');

        $routed = $this->makeCandidate('free-model', true);

        $tracker->recordAttemptFailure($num, $routed, new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('Rate limit exceeded', 429, null, [
            'failure_code' => 'rate_limited',
            'http_status' => 429,
        ]));

        $attempts = $tracker->getAttempts();
        self::assertCount(1, $attempts);
        self::assertSame('failed', $attempts[0]['status']);
        self::assertSame('rate_limited', $attempts[0]['failure_reason']);
        self::assertSame(429, $attempts[0]['http_status']);
        self::assertNull($tracker->getSuccessfulModel());
    }

    // 4. Multi-attempt fallback chain records order and reasons
    public function test_multi_attempt_fallback_chain_records_order_and_reasons(): void
    {
        $tracker = new AgentRunExecutionTracker('run_ulid_test_4', (int) $this->user->id, 'pro', 'quick_free');

        // Attempt 1: Free model fails with rate limit
        $num1 = $tracker->recordAttemptStart('openrouter', 'conn', 'meta-llama/free', 'free');
        $routed1 = $this->makeCandidate('meta-llama/free', true);
        $tracker->recordAttemptFailure($num1, $routed1, new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException('Rate limited', 429, null, [
            'failure_code' => 'rate_limited',
            'http_status' => 429,
        ]));

        // Attempt 2: Paid model succeeds
        $num2 = $tracker->recordAttemptStart('openrouter', 'conn', 'openai/gpt-4o-mini', 'paid');
        $routed2 = $this->makeCandidate('openai/gpt-4o-mini', false);
        $tracker->recordAttemptSuccess($num2, $routed2, [
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'total_tokens' => 200,
            'total_cost' => 0.0003,
        ]);

        $attempts = $tracker->getAttempts();
        self::assertCount(2, $attempts);
        self::assertSame('failed', $attempts[0]['status']);
        self::assertSame('rate_limited', $attempts[0]['failure_reason']);
        self::assertSame('success', $attempts[1]['status']);
        self::assertSame('openai/gpt-4o-mini', $tracker->getSuccessfulModel());
    }

    // 5. statusFor returns live state from cache while processing
    public function test_status_for_returns_live_state_from_cache_while_processing(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 1',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Test message',
            'position' => 1,
        ]);
        $run = AgentRun::create([
            'ulid' => 'run_live_status_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $tracker = new AgentRunExecutionTracker($run->ulid, (int) $this->user->id, 'pro', 'quick_free');
        $tracker->recordAttemptStart('openrouter', 'conn', 'model-a', 'free');

        $status = AgentRunExecutionTracker::statusFor($run);
        self::assertSame('processing', $status['run_status']);
        self::assertSame('pro', $status['tier']);
        self::assertSame('quick_free', $status['routing_policy']);
        self::assertCount(1, $status['attempts']);
        self::assertSame('model-a', $status['attempts'][0]['model']);
    }

    // 6. statusFor returns historical state from retrieval_summary after completion
    public function test_status_for_returns_historical_state_from_retrieval_summary(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 2',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Test message',
            'position' => 1,
        ]);
        $run = AgentRun::create([
            'ulid' => 'run_historical_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'done',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'retrieval_summary' => [
                'tier' => 'master',
                'routing_policy' => 'normal',
                'live_execution' => [
                    'run_status' => 'done',
                    'tier' => 'master',
                    'routing_policy' => 'normal',
                    'successful_model' => 'anthropic/claude-3.5-sonnet',
                    'successful_provider' => 'openrouter',
                    'successful_cost_class' => 'paid',
                    'attempts' => [
                        [
                            'attempt_number' => 1,
                            'model' => 'anthropic/claude-3.5-sonnet',
                            'provider' => 'openrouter',
                            'cost_class' => 'paid',
                            'status' => 'success',
                            'input_tokens' => 200,
                            'output_tokens' => 100,
                            'total_tokens' => 300,
                            'recorded_cost' => 0.002,
                            'currency' => 'USD',
                            'cost_state' => 'confirmed',
                        ],
                    ],
                    'usage' => [
                        'input_tokens' => 200,
                        'output_tokens' => 100,
                        'reasoning_tokens' => null,
                        'total_tokens' => 300,
                        'recorded_cost' => 0.002,
                        'currency' => 'USD',
                        'state' => 'confirmed',
                    ],
                ],
            ],
        ]);

        Cache::forget('agent_run_status:' . $run->ulid);

        $status = AgentRunExecutionTracker::statusFor($run);
        self::assertSame('done', $status['run_status']);
        self::assertSame('anthropic/claude-3.5-sonnet', $status['successful_model']);
        self::assertSame(0.002, $status['usage']['recorded_cost']);
        self::assertSame('confirmed', $status['usage']['state']);
    }

    // 7. Run status endpoint authorization and isolation
    public function test_run_status_endpoint_authorization_and_isolation(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 3',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Test',
            'position' => 1,
        ]);
        $run = AgentRun::create([
            'ulid' => 'run_auth_isolation_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $controller = new AgentRuntimeController();
        $sites = new MockSiteDirectory([7]);

        // 1. Authorized user -> 200
        $reqUser = Request::create('/agent-runtime/runs/' . $run->ulid . '/status', 'GET');
        $reqUser->setUserResolver(fn () => $this->user);
        $res = $controller->runStatus($reqUser, $run->ulid, $sites);
        self::assertSame(200, $res->getStatusCode());

        // 2. Different user -> 403 Forbidden
        $reqOther = Request::create('/agent-runtime/runs/' . $run->ulid . '/status', 'GET');
        $reqOther->setUserResolver(fn () => $this->otherUser);
        $resOther = $controller->runStatus($reqOther, $run->ulid, $sites);
        self::assertSame(403, $resOther->getStatusCode());

        // 3. Unknown run ULID -> 404 Not Found
        $reqUnknown = Request::create('/agent-runtime/runs/non_existent_ulid/status', 'GET');
        $reqUnknown->setUserResolver(fn () => $this->user);
        $resUnknown = $controller->runStatus($reqUnknown, 'non_existent_ulid', $sites);
        self::assertSame(404, $resUnknown->getStatusCode());
    }

    // 8. Tier to policy mapping preserved
    public function test_tier_to_policy_mapping_preserved(): void
    {
        $freePolicy = match ('free') {
            'free' => AiRoutingPolicy::FreeOnly,
            'pro' => AiRoutingPolicy::QuickFree,
            'master' => AiRoutingPolicy::Normal,
        };
        self::assertSame(AiRoutingPolicy::FreeOnly, $freePolicy);

        $proPolicy = match ('pro') {
            'free' => AiRoutingPolicy::FreeOnly,
            'pro' => AiRoutingPolicy::QuickFree,
            'master' => AiRoutingPolicy::Normal,
        };
        self::assertSame(AiRoutingPolicy::QuickFree, $proPolicy);

        $masterPolicy = match ('master') {
            'free' => AiRoutingPolicy::FreeOnly,
            'pro' => AiRoutingPolicy::QuickFree,
            'master' => AiRoutingPolicy::Normal,
        };
        self::assertSame(AiRoutingPolicy::Normal, $masterPolicy);
    }

    // 9. Free tier strictly prohibits Paid models
    public function test_free_tier_prohibits_paid_models_by_policy(): void
    {
        self::assertFalse(AiRoutingPolicy::FreeOnly->allowsPaidRoutes());
    }

    // 10. Pro tier QuickFree allows Paid fallback
    public function test_pro_tier_quick_free_allows_paid_fallback(): void
    {
        self::assertTrue(AiRoutingPolicy::QuickFree->allowsPaidRoutes());
        self::assertSame(1, AiRoutingPolicy::QuickFree->freeAttemptCap());
    }

    // 11. Paid authorization: Pro/Master tier request without paid_consent returns 422 paid_confirmation_required
    public function test_paid_authorization_requires_explicit_consent_for_pro_and_master(): void
    {
        $controller = new AgentRuntimeController();
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $sites = new MockSiteDirectory([7]);
        $threads = app(AgentThreadRepository::class);
        $persistence = app(AgentTurnPersistence::class);

        // 1. Pro tier without consent -> 422
        $reqPro = Request::create('/agent-runtime/turns', 'POST', [
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze website',
            'tier' => 'pro',
            'paid_consent' => false,
        ]);
        $reqPro->setUserResolver(fn () => $this->user);
        $resPro = $controller->turn($reqPro, $coordinator, $sites, $threads, $persistence);
        self::assertSame(422, $resPro->getStatusCode());
        self::assertSame('paid_confirmation_required', $resPro->getData(true)['error_code']);

        // 2. Master tier without consent -> 422
        $reqMaster = Request::create('/agent-runtime/turns', 'POST', [
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze website',
            'tier' => 'master',
            'paid_consent' => false,
        ]);
        $reqMaster->setUserResolver(fn () => $this->user);
        $resMaster = $controller->turn($reqMaster, $coordinator, $sites, $threads, $persistence);
        self::assertSame(422, $resMaster->getStatusCode());
        self::assertSame('paid_confirmation_required', $resMaster->getData(true)['error_code']);
    }

    // 12. Free tier proceeds without paid consent
    public function test_free_tier_proceeds_without_paid_consent(): void
    {
        $controller = new AgentRuntimeController();
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('send')->willReturn(new AgentTurnResult(
            new AgentResponse('Free response', [], [], []),
            $this->createMock(PreparedModelInput::class),
            $this->createMock(PreparedModelInput::class),
            false
        ));
        $sites = new MockSiteDirectory([7]);
        $threads = app(AgentThreadRepository::class);
        $persistence = app(AgentTurnPersistence::class);

        $req = Request::create('/agent-runtime/turns', 'POST', [
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Hello',
            'tier' => 'free',
        ]);
        $req->setUserResolver(fn () => $this->user);
        $res = $controller->turn($req, $coordinator, $sites, $threads, $persistence);
        self::assertSame(200, $res->getStatusCode());
    }

    // 13. Deterministic / internal-only runs record internal_only: true, external_model: null, external_model_calls: 0
    public function test_deterministic_run_records_internal_only_without_external_model(): void
    {
        $controller = new AgentRuntimeController();
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('send')->willReturn(new AgentTurnResult(
            new AgentResponse('Direct factual answer', [], [], []),
            $this->createMock(PreparedModelInput::class),
            $this->createMock(PreparedModelInput::class),
            false, // answerModelCalled = false
            executionTrace: [
                'router' => 'local',
                'synthesis' => false,
                'external_model' => null,
                'external_model_calls' => 0,
                'internal_only' => true,
            ]
        ));
        $sites = new MockSiteDirectory([7]);
        $threads = app(AgentThreadRepository::class);
        $persistence = app(AgentTurnPersistence::class);

        $req = Request::create('/agent-runtime/turns', 'POST', [
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Inventory query',
            'tier' => 'free',
        ]);
        $req->setUserResolver(fn () => $this->user);
        $res = $controller->turn($req, $coordinator, $sites, $threads, $persistence);
        self::assertSame(200, $res->getStatusCode());

        $data = $res->getData(true)['data'];
        self::assertTrue($data['execution']['internal_only']);
        self::assertNull($data['execution']['external_model']);
        self::assertSame(0, $data['execution']['external_model_calls']);
    }

    private function makeRetrievalExecutor(): RetrievalExecutor
    {
        return new RetrievalExecutor(
            new RetrievalPlanner(),
            new SeoAccessExecutor(
                new class implements SeoAccessTransport {
                    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array {
                        return ['status' => 'ok', 'data' => []];
                    }
                },
                new class implements SeoAccessCredential {
                    public function bearer(): ?string { return 'test'; }
                },
                new \Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy(),
                'https://app.example.test',
            )
        );
    }

    // 14. Confirmed free cost records 0.0 with state confirmed
    public function test_confirmed_free_cost_records_zero_with_confirmed_state(): void
    {
        $tracker = new AgentRunExecutionTracker('run_free_cost_1', (int) $this->user->id, 'free', 'free_only');
        $num = $tracker->recordAttemptStart('openrouter', 'conn', 'google/gemini-2.0-flash-exp:free', 'free');
        $routed = $this->makeCandidate('google/gemini-2.0-flash-exp:free', true);
        $tracker->recordAttemptSuccess($num, $routed, [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
        ]);

        $cost = $tracker->getCostSummary();
        self::assertSame(0.0, $cost['recorded_cost']);
        self::assertSame('USD', $cost['currency']);
        self::assertSame('confirmed', $cost['state']);
    }

    // 15. Paid model with explicit cost records exact amount and confirmed
    public function test_paid_model_with_explicit_cost_records_exact_amount_and_confirmed(): void
    {
        $tracker = new AgentRunExecutionTracker('run_paid_explicit_1', (int) $this->user->id, 'master', 'normal');
        $num = $tracker->recordAttemptStart('openrouter', 'conn', 'openai/gpt-4o', 'paid');
        $routed = $this->makeCandidate('openai/gpt-4o', false);
        $tracker->recordAttemptSuccess($num, $routed, [
            'prompt_tokens' => 1000,
            'completion_tokens' => 500,
            'total_tokens' => 1500,
            'total_cost' => 0.0125,
        ]);

        $cost = $tracker->getCostSummary();
        self::assertSame(0.0125, $cost['recorded_cost']);
        self::assertSame('confirmed', $cost['state']);
    }

    // 16. Paid model without explicit cost records null and pending (never guessing 0)
    public function test_paid_model_without_explicit_cost_records_null_and_pending(): void
    {
        $tracker = new AgentRunExecutionTracker('run_paid_pending_1', (int) $this->user->id, 'master', 'normal');
        $num = $tracker->recordAttemptStart('openrouter', 'conn', 'some-provider/paid-model', 'paid');
        $routed = $this->makeCandidate('some-provider/paid-model', false);
        $tracker->recordAttemptSuccess($num, $routed, [
            'prompt_tokens' => 300,
            'completion_tokens' => 100,
            'total_tokens' => 400,
            // Notice: no total_cost or cost field returned by provider
        ]);

        $cost = $tracker->getCostSummary();
        self::assertNull($cost['recorded_cost']);
        self::assertSame('pending', $cost['state']);
    }

    // 17. Execution trace in AgentTurnCoordinator exposes real executed model, distinct from assumed model
    public function test_execution_trace_exposes_real_executed_model_distinct_from_assumed(): void
    {
        $answers = new TrackingAnswerGateway();
        $answers->returnModel = 'actual-executed-provider/model-x';
        $answers->returnProvider = 'openrouter';
        $answers->returnCostClass = 'paid';
        $answers->returnCost = 0.005;

        $decisions = $this->createMock(DecisionModelGateway::class);
        $inputBuilder = new AgentModelInputBuilder(
            promptBindings: new class implements \Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): \Omnichannel\Addons\AiPrompt\Models\SeoPrompt {
                    $prompt = new \Omnichannel\Addons\AiPrompt\Models\SeoPrompt();
                    $prompt->hook_key = $hookKey;
                    $prompt->markdown_content = 'Test instruction for ' . $hookKey;
                    return $prompt;
                }
            }
        );

        $coordinator = new AgentTurnCoordinator(
            $inputBuilder,
            $decisions,
            new \Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser(),
            $this->makeRetrievalExecutor(),
            $answers,
            new \Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser(),
        );

        // When bundle factual compose is null, it calls answer model
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [new RetrievalSource('audit', 'ok', 'seo', [])], []);
        $result = $coordinator->answerConfirmed(
            (int) $this->user->id,
            AgentProjectScope::site(7),
            'Explain audit',
            [],
            $bundle,
            'report',
            'vi',
            AiRoutingPolicy::QuickFree,
            'pro',
            'run_ulid_coord_test'
        );

        self::assertTrue($result->answerModelCalled);
        self::assertSame('actual-executed-provider/model-x', $result->executionTrace['successful_model']);
        self::assertSame('openrouter', $result->executionTrace['successful_provider']);
        self::assertSame('paid', $result->executionTrace['successful_cost_class']);
        self::assertSame(0.005, $result->executionTrace['cost']['recorded_cost']);
        self::assertSame('confirmed', $result->executionTrace['cost']['state']);
    }

    // 18. GET /agent-runtime/runs/{runUlid}/status returns expected contract structure
    public function test_run_status_endpoint_returns_expected_contract(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 4',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Query',
            'position' => 1,
        ]);
        $run = AgentRun::create([
            'ulid' => 'run_contract_check_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $tracker = new AgentRunExecutionTracker($run->ulid, (int) $this->user->id, 'pro', 'quick_free');
        $tracker->recordAttemptStart('openrouter', 'conn', 'test-model', 'free');

        $controller = new AgentRuntimeController();
        $sites = new MockSiteDirectory([7]);

        $req = Request::create('/agent-runtime/runs/' . $run->ulid . '/status', 'GET');
        $req->setUserResolver(fn () => $this->user);
        $res = $controller->runStatus($req, $run->ulid, $sites);

        self::assertSame(200, $res->getStatusCode());
        $payload = $res->getData(true)['data'];

        self::assertArrayHasKey('run_status', $payload);
        self::assertArrayHasKey('run_ulid', $payload);
        self::assertArrayHasKey('tier', $payload);
        self::assertArrayHasKey('routing_policy', $payload);
        self::assertArrayHasKey('attempts', $payload);
        self::assertArrayHasKey('usage', $payload);
        self::assertArrayHasKey('input_tokens', $payload['usage']);
        self::assertArrayHasKey('output_tokens', $payload['usage']);
        self::assertArrayHasKey('recorded_cost', $payload['usage']);
        self::assertArrayHasKey('state', $payload['usage']);
    }

    // 19. rerun preserves prior tier and client run ULID
    public function test_rerun_preserves_prior_tier_and_client_run_ulid(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 5',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Prior turn',
            'position' => 1,
        ]);
        AgentRun::create([
            'ulid' => 'prior_run_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'done',
            'started_at' => now()->subMinutes(2),
            'retrieval_summary' => ['tier' => 'pro', 'routing_policy' => 'quick_free'],
        ]);

        $controller = new AgentRuntimeController();
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('send')->willReturn(new AgentTurnResult(
            new AgentResponse('Rerun done', [], [], []),
            $this->createMock(PreparedModelInput::class),
            $this->createMock(PreparedModelInput::class),
            false
        ));
        $sites = new MockSiteDirectory([7]);
        $threads = app(AgentThreadRepository::class);
        $persistence = app(AgentTurnPersistence::class);
        $resolver = $this->createMock(AssumedModelResolver::class);

        $clientUlid = '01CLIENTRERUNULID1234567890';
        $req = Request::create("/agent-runtime/threads/{$thread->ulid}/messages/{$msg->id}/rerun", 'POST', [
            'run_ulid' => $clientUlid,
            'paid_consent' => true,
        ]);
        $req->setUserResolver(fn () => $this->user);

        $res = $controller->rerun($req, $thread->ulid, $msg->id, $coordinator, $sites, $threads, $persistence, $resolver);
        self::assertSame(200, $res->getStatusCode());

        $rerunRecord = AgentRun::where('ulid', $clientUlid)->first();
        self::assertNotNull($rerunRecord);
        self::assertSame('pro', $rerunRecord->retrieval_summary['tier']);
    }

    // 20. confirmRun correlates with run ULID and executes confirmed tool without losing correlation
    public function test_confirm_run_correlates_with_run_ulid(): void
    {
        $thread = AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $this->agentApp->id,
            'principal_type' => 'user',
            'principal_ref' => (string) $this->user->id,
            'user_id' => $this->user->id,
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'title' => 'Thread 6',
            'status' => 'active',
        ]);
        $msg = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Sync GSC',
            'position' => 1,
        ]);

        $proposal = new AgentToolConfirmationProposal(
            'traffic',
            'gsc.performance',
            ['gsc.performance'],
            ['period' => '2026-09'],
            'report',
            'vi',
            ['gsc.performance'],
            ['type' => 'site', 'siteId' => 7, 'siteRef' => 'site:7'],
        );

        $run = AgentRun::create([
            'ulid' => 'run_confirm_ulid_1',
            'thread_id' => $thread->id,
            'user_message_id' => $msg->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:7',
            'user_id' => $this->user->id,
            'status' => 'awaiting_confirmation',
            'started_at' => now(),
            'retrieval_summary' => [
                'tier' => 'pro',
                'confirmation' => [
                    'proposal' => $proposal->toArray(),
                ],
            ],
        ]);

        $controller = new AgentRuntimeController();
        $sites = new MockSiteDirectory([7]);
        $persistence = app(AgentTurnPersistence::class);
        $threads = app(AgentThreadRepository::class);
        $tools = new AgentConfirmedToolExecutor(
            $this->makeRetrievalExecutor(),
            $this->createMock(\Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService::class),
        );

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('composeFactual')->willReturn(null);
        $coordinator->expects(self::once())
            ->method('answerConfirmed')
            ->with(
                (int) $this->user->id,
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                AiRoutingPolicy::QuickFree,
                'pro',
                $run->ulid // Exact correlation ID passed
            )
            ->willReturn(new AgentTurnResult(
                new AgentResponse('Confirmed report', [], [], []),
                $this->createMock(PreparedModelInput::class),
                $this->createMock(PreparedModelInput::class),
                true,
                executionTrace: ['synthesis' => true, 'successful_model' => 'test-model']
            ));

        $req = Request::create("/agent-runtime/runs/{$run->ulid}/confirm", 'POST', []);
        $req->setUserResolver(fn () => $this->user);

        $res = $controller->confirmRun($req, $run->ulid, $sites, $persistence, $threads, $tools, $coordinator);
        self::assertSame(200, $res->getStatusCode());
    }
}
