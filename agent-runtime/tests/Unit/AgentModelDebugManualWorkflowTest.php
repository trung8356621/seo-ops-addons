<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Model\SecretRedactor;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository;
use Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnProgress;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AgentRuntime\Runtime\InterceptedModelCall;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentModelDebugManualWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        }
    }

    #[Test]
    public function prompt_export_for_manual_chat_wraps_system_and_user_with_single_turn_enforcement(): void
    {
        $input = new PreparedModelInput('answer', [
            ['role' => 'system', 'content' => 'System instructions for SEO agent response.'],
            ['role' => 'user', 'content' => json_encode(['task' => 'summarize', 'retrieval_bundle' => []])],
        ]);

        $chatPrompt = $input->exportForManualChat();

        // 1. Must preserve system instructions
        self::assertStringContainsString('System instructions for SEO agent response.', $chatPrompt);
        // 2. Must preserve user content
        self::assertStringContainsString('"task":"summarize"', $chatPrompt);
        // 3. Must instruct single valid AgentResponse JSON object
        self::assertStringContainsString('Respond ONLY with a single valid AgentResponse JSON object', $chatPrompt);
        // 4. Must forbid markdown code fences and conversational filler
        self::assertStringContainsString('Do NOT output conversational greetings', $chatPrompt);
        self::assertStringContainsString('no ```json', $chatPrompt);
        // 5. Must end with high-priority output requirement
        self::assertStringContainsString('=== OUTPUT REQUIREMENT ===', $chatPrompt);
        // 6. Messages array remains unchanged
        self::assertCount(2, $input->messages);
        self::assertSame('system', $input->messages[0]['role']);
        self::assertSame('user', $input->messages[1]['role']);
    }

    #[Test]
    public function prompt_export_for_manual_chat_preserves_secret_redaction(): void
    {
        $redactor = new SecretRedactor();
        $input = PreparedModelInput::make('answer', [
            ['role' => 'system', 'content' => 'Bearer token: sk-abcdef1234567890abcdef'],
            ['role' => 'user', 'content' => 'Authorization: Bearer secret_access_token_12345'],
        ], $redactor);

        $chatPrompt = $input->exportForManualChat();
        self::assertStringNotContainsString('sk-abcdef1234567890abcdef', $chatPrompt);
        self::assertStringNotContainsString('secret_access_token_12345', $chatPrompt);
        self::assertStringContainsString('[redacted', $chatPrompt);
    }

    #[Test]
    public function invalid_manual_debug_result_returns_422_and_preserves_awaiting_model_state(): void
    {
        $user = new \App\Models\User();
        $user->id = 1;

        $app = AgentApp::findByKey('seo-ops');
        $thread = AgentThread::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'agent_app_id' => $app->id,
            'principal_type' => 'user',
            'principal_ref' => '1',
            'user_id' => 1,
            'owner_id' => 1,
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'title' => 'Test Manual Debug Thread',
        ]);

        $userMessage = AgentMessage::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
            'position' => 1,
        ]);

        $checkpointBundle = [
            'scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'],
            'sources' => [
                [
                    'name' => 'worst_articles',
                    'status' => 'ok',
                    'capability' => 'seo_audit.worst_articles',
                    'data' => [
                        'total' => 10,
                        'items' => [
                            ['article_ref' => 'article:1', 'title' => 'Bài A', 'seo_score' => 20],
                        ],
                    ],
                ],
            ],
            'warnings' => [],
        ];

        $runtimeState = [
            'turn' => [
                'scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'],
                'message' => 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
                'history' => [],
            ],
            'bundle' => $checkpointBundle,
            'selected_response_template' => 'report',
            'selected_response_language' => 'vi',
            'execution' => [
                'router' => 'local',
                'capabilities' => ['seo_audit.site_improve'],
                'tools' => ['worst_articles'],
            ],
        ];

        $run = AgentRun::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'user_message_id' => $userMessage->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'user_id' => 1,
            'status' => 'awaiting_model',
            'retrieval_summary' => [
                'model_call' => 'answer',
                'runtime_state' => $runtimeState,
            ],
            'started_at' => now(),
        ]);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('resumeIntercepted')
            ->willThrowException(new \Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected('Agent response is not JSON.'));

        $coordinator->method('buildAnswerInput')->willReturn(
            new PreparedModelInput('answer', [
                ['role' => 'system', 'content' => 'System instruction'],
                ['role' => 'user', 'content' => 'User payload'],
            ])
        );

        $modelResolver = $this->createMock(AssumedModelResolver::class);
        $modelResolver->method('resolveAnswerModel')->willReturn(
            new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata('answer', 'direct', 'gemini', 'gemini-1.5-flash', 'Gemini Flash', [], 'standard', 'available')
        );

        $persistence = app(AgentTurnPersistence::class);
        $threadRepo = app(AgentThreadRepository::class);
        $controller = new AgentRuntimeController();

        $request = Request::create('/agent-runtime/model-debug/apply', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'run_ulid' => $run->ulid,
            'manual_result' => 'Here is my conversational response without JSON: You should improve SEO by optimizing titles.',
        ], JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn () => $user);

        $response = $controller->modelDebugApply($request, $coordinator, $threadRepo, $persistence, $modelResolver);

        // 1. Must return HTTP 422
        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);

        // 2. Payload contains validation_error and paused status
        self::assertSame('paused', $data['data']['status']);
        self::assertStringContainsString('Agent response is not JSON.', $data['validation_error']);
        self::assertSame('answer', $data['data']['model_call']['key']);
        self::assertArrayHasKey('chat_prompt', $data['data']['model_call']);

        // 3. Run status MUST still be awaiting_model in database
        $freshRun = $run->fresh();
        self::assertSame('awaiting_model', $freshRun->status);
        self::assertNull($freshRun->assistant_message_id);
        self::assertNull($freshRun->finished_at);

        // 4. Checkpoint data remains completely intact
        self::assertSame('answer', $freshRun->retrieval_summary['model_call']);
        self::assertSame($runtimeState, $freshRun->retrieval_summary['runtime_state']);

        // 5. No assistant message created
        $assistantCount = AgentMessage::where('role', 'assistant')->where('thread_id', $thread->id)->count();
        self::assertSame(0, $assistantCount);
    }

    #[Test]
    public function valid_retry_after_failed_apply_completes_successfully(): void
    {
        $user = new \App\Models\User();
        $user->id = 1;

        $app = AgentApp::findByKey('seo-ops');
        $thread = AgentThread::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'agent_app_id' => $app->id,
            'principal_type' => 'user',
            'principal_ref' => '1',
            'user_id' => 1,
            'owner_id' => 1,
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'title' => 'Test Retry Thread',
        ]);

        $userMessage = AgentMessage::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
            'position' => 1,
        ]);

        $runtimeState = [
            'turn' => [
                'scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'],
                'message' => 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
                'history' => [],
            ],
            'bundle' => [
                'scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'],
                'sources' => [],
                'warnings' => [],
            ],
            'selected_response_template' => 'report',
            'selected_response_language' => 'vi',
        ];

        $run = AgentRun::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'user_message_id' => $userMessage->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'user_id' => 1,
            'status' => 'awaiting_model',
            'retrieval_summary' => [
                'model_call' => 'answer',
                'runtime_state' => $runtimeState,
            ],
            'started_at' => now(),
        ]);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('buildAnswerInput')->willReturn(
            new PreparedModelInput('answer', [
                ['role' => 'system', 'content' => 'System instruction'],
                ['role' => 'user', 'content' => 'User payload'],
            ])
        );

        $modelResolver = $this->createMock(AssumedModelResolver::class);
        $modelResolver->method('resolveAnswerModel')->willReturn(
            new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata('answer', 'direct', 'gemini', 'gemini-1.5-flash', 'Gemini Flash', [], 'standard', 'available')
        );

        $persistence = app(AgentTurnPersistence::class);
        $threadRepo = app(AgentThreadRepository::class);
        $controller = new AgentRuntimeController();

        // 1st attempt: fails with AgentResponseRejected
        $coordinator->expects(self::exactly(2))
            ->method('resumeIntercepted')
            ->willReturnCallback(function ($scope, $msg, $hist, $callKey, $manualResult) {
                if (!str_contains($manualResult, '"blocks"')) {
                    throw new \Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected('Agent response is not JSON.');
                }
                return AgentTurnProgress::completed(new AgentTurnResult(
                    new AgentResponse('Đã hoàn thành phân tích đề xuất SEO.', [['type' => 'markdown', 'text' => 'Chi tiết đề xuất']], [], []),
                    new PreparedModelInput('routing', []),
                    new PreparedModelInput('answer', []),
                    true,
                ));
            });

        $req1 = Request::create('/agent-runtime/model-debug/apply', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'run_ulid' => $run->ulid,
            'manual_result' => 'not json',
        ], JSON_THROW_ON_ERROR));
        $req1->setUserResolver(fn () => $user);

        $res1 = $controller->modelDebugApply($req1, $coordinator, $threadRepo, $persistence, $modelResolver);
        self::assertSame(422, $res1->getStatusCode());
        self::assertSame('awaiting_model', $run->fresh()->status);

        // 2nd attempt: valid JSON succeeds
        $validJson = json_encode([
            'message' => 'Đã hoàn thành phân tích đề xuất SEO.',
            'blocks' => [['type' => 'markdown', 'text' => 'Chi tiết đề xuất']],
            'actions' => [],
        ], JSON_THROW_ON_ERROR);

        $req2 = Request::create('/agent-runtime/model-debug/apply', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'run_ulid' => $run->ulid,
            'manual_result' => $validJson,
        ], JSON_THROW_ON_ERROR));
        $req2->setUserResolver(fn () => $user);

        $res2 = $controller->modelDebugApply($req2, $coordinator, $threadRepo, $persistence, $modelResolver);
        self::assertSame(200, $res2->getStatusCode());

        $freshRun = $run->fresh();
        self::assertSame('done', $freshRun->status);
        self::assertNotNull($freshRun->assistant_message_id);

        $assistantMessage = AgentMessage::find($freshRun->assistant_message_id);
        self::assertNotNull($assistantMessage);
        self::assertSame('Đã hoàn thành phân tích đề xuất SEO.', $assistantMessage->content);
    }

    #[Test]
    public function explicit_use_verified_and_recover_stranded_continue_to_work(): void
    {
        $user = new \App\Models\User();
        $user->id = 1;

        $app = AgentApp::findByKey('seo-ops');
        $thread = AgentThread::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'agent_app_id' => $app->id,
            'principal_type' => 'user',
            'principal_ref' => '1',
            'user_id' => 1,
            'owner_id' => 1,
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'title' => 'Test Verified Fallback Thread',
        ]);

        $userMessage = AgentMessage::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => 'Xem dữ liệu',
            'position' => 1,
        ]);

        $run = AgentRun::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'thread_id' => $thread->id,
            'user_message_id' => $userMessage->id,
            'app_key' => 'seo-ops',
            'scope_type' => 'site',
            'scope_ref' => 'site:4',
            'user_id' => 1,
            'status' => 'awaiting_model',
            'retrieval_summary' => [
                'model_call' => 'answer',
                'runtime_state' => [
                    'turn' => ['scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'], 'message' => 'Xem dữ liệu', 'history' => []],
                    'bundle' => ['scope' => ['type' => 'site', 'siteId' => 4, 'siteRef' => 'site:4'], 'sources' => [], 'warnings' => []],
                ],
            ],
            'started_at' => now(),
        ]);

        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->method('recoverRejectedAnswer')->willReturn(
            new AgentResponse('Fallback response', [['type' => 'markdown', 'text' => 'Fallback']], [], [])
        );

        $modelResolver = $this->createMock(AssumedModelResolver::class);
        $persistence = app(AgentTurnPersistence::class);
        $threadRepo = app(AgentThreadRepository::class);
        $controller = new AgentRuntimeController();

        $request = Request::create('/agent-runtime/model-debug/apply', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'run_ulid' => $run->ulid,
            'use_verified' => true,
        ], JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn () => $user);

        $response = $controller->modelDebugApply($request, $coordinator, $threadRepo, $persistence, $modelResolver);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', $run->fresh()->status);
    }
}
