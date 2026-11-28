<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsureCanonicalHttps;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AgentRuntime\AgentRuntimeServiceProvider;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnResult;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Tests\TestCase;

final class AgentQaApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $qaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(AgentRuntimeServiceProvider::class);

        $this->withoutMiddleware([
            EnsureApplicationInstalled::class,
            EnsureCanonicalHttps::class,
        ]);

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', [
                '--path' => base_path('addons/agent-runtime/database/migrations'),
                '--realpath' => true,
            ]);
        }

        $this->qaUser = User::query()->create([
            'name' => 'QA Tester',
            'email' => 'qa-tester@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
        ]);

        AgentApp::query()->firstOrCreate(
            ['app_key' => 'seo-ops'],
            ['name' => 'SEO Ops', 'status' => 'active'],
        );

        config()->set('agent-runtime.qa_api', [
            'enabled' => true,
            'key' => 'qa_test_secret_key_1234567890',
            'user_id' => $this->qaUser->id,
            'allowed_site_ids' => '10,20',
            'rate_limit_per_minute' => 60,
        ]);

        $this->bindFakeSites();
    }

    private function bindFakeSites(): void
    {
        $fakeSites = new class implements SiteDirectory
        {
            public function listActiveSites(?int $userId = null): array
            {
                return [
                    ['id' => 10, 'domain' => 'allowed-one.example.com'],
                    ['id' => 20, 'domain' => 'allowed-two.example.com'],
                    ['id' => 99, 'domain' => 'outside-qa-scope.example.com'],
                ];
            }

            public function isSiteVisible(int $siteId, ?int $userId = null): bool
            {
                return in_array($siteId, [10, 20, 99], true);
            }
        };

        $this->app->instance(SiteDirectory::class, $fakeSites);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $key = 'qa_test_secret_key_1234567890'): array
    {
        return [
            'Authorization' => 'Bearer '.$key,
            'Accept' => 'application/json',
        ];
    }

    public function test_returns_404_when_qa_api_is_disabled(): void
    {
        config()->set('agent-runtime.qa_api.enabled', false);

        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'qa_api_disabled');
    }

    public function test_rejects_missing_and_invalid_api_key(): void
    {
        $this->getJson('/api/agent-qa/v1/health')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'invalid_api_key');

        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders('wrong-key'))
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'invalid_api_key');
    }

    public function test_health_and_projects_filter_to_allowed_sites_without_session_or_csrf(): void
    {
        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.user_id', $this->qaUser->id)
            ->assertJsonPath('data.allowed_site_ids', [10, 20])
            ->assertJsonPath('data.default_tier', 'free')
            ->assertJsonPath('data.diagnostics_default', true);

        $response = $this->getJson('/api/agent-qa/v1/projects', $this->authHeaders())
            ->assertStatus(200);

        $projects = $response->json('data.projects');
        $this->assertCount(2, $projects);
        $this->assertSame([10, 20], array_column($projects, 'site_id'));
    }

    public function test_blocks_website_outside_allowed_qa_scope(): void
    {
        $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 99,
            'message' => 'Phân tích SEO site 99',
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'site_scope_forbidden');
    }

    public function test_single_question_uses_free_tier_and_diagnostics_by_default(): void
    {
        $captured = [];
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (
                int $userId,
                AgentProjectScope $scope,
                string $message,
                array $history,
                bool $diagnostics,
                ?AiRoutingPolicy $routingPolicy,
                ?string $tier,
                ?string $runUlid,
            ) use (&$captured): AgentTurnResult {
                $captured = compact('userId', 'scope', 'message', 'history', 'diagnostics', 'routingPolicy', 'tier', 'runUlid');

                return new AgentTurnResult(
                    response: new AgentResponse(
                        message: 'Site có 12 bài viết và 45 từ khóa.',
                        blocks: [['type' => 'markdown', 'text' => 'Site có 12 bài viết và 45 từ khóa.']],
                        actions: [],
                        sources: [
                            ['name' => 'articles', 'status' => 'ok', 'kind' => 'direct', 'data' => ['count' => 12]],
                        ],
                    ),
                    routingInput: new PreparedModelInput('decision', []),
                    answerInput: new PreparedModelInput('answer', []),
                    answerModelCalled: true,
                    executionTrace: [
                        'router' => 'local',
                        'outcome' => 'confident',
                        'module' => 'articles',
                        'operation' => 'articles.inventory',
                        'capabilities' => ['articles.inventory'],
                        'tools' => ['articles'],
                        'jev_scores' => ['global' => 0.91, 'internal' => 0.88],
                        'successful_model' => 'free/test-model',
                        'successful_cost_class' => 'free',
                        'tier' => 'free',
                        'routing_policy' => 'free_only',
                    ],
                );
            });
        $this->app->instance(AgentTurnCoordinator::class, $coordinator);

        $res = $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Tổng quan bài viết của site này?',
        ], $this->authHeaders())->assertStatus(200);

        $this->assertSame($this->qaUser->id, $captured['userId']);
        $this->assertSame(10, $captured['scope']->siteId);
        $this->assertTrue($captured['diagnostics']);
        $this->assertSame(AiRoutingPolicy::FreeOnly, $captured['routingPolicy']);
        $this->assertSame('free', $captured['tier']);

        $res->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.message', 'Site có 12 bài viết và 45 từ khóa.')
            ->assertJsonPath('data.diagnostics.operation', 'articles.inventory')
            ->assertJsonPath('data.diagnostics.capabilities', ['articles.inventory'])
            ->assertJsonPath('data.diagnostics.semantic_routing_scores.jev_scores.global', 0.91)
            ->assertJsonPath('data.diagnostics.answer_model_called', true)
            ->assertJsonPath('data.diagnostics.retrieval_summary.tools', ['articles']);

        $runUlid = $res->json('data.run_ulid');
        $threadUlid = $res->json('data.thread_ulid');
        $this->assertNotEmpty($runUlid);
        $this->assertNotEmpty($threadUlid);

        $this->getJson('/api/agent-qa/v1/runs/'.$runUlid.'/status', $this->authHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.run_ulid', $runUlid)
            ->assertJsonPath('data.run_status', 'done')
            ->assertJsonPath('data.tier', 'free');
    }

    public function test_multi_module_and_multi_turn_thread_history(): void
    {
        $calls = [];
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (
                int $userId,
                AgentProjectScope $scope,
                string $message,
                array $history,
            ) use (&$calls): AgentTurnResult {
                $calls[] = ['message' => $message, 'history' => $history];

                return new AgentTurnResult(
                    response: new AgentResponse(
                        message: 'Trả lời cho: '.$message,
                        blocks: [['type' => 'markdown', 'text' => 'Trả lời cho: '.$message]],
                        actions: [],
                        sources: [
                            ['name' => 'keywords', 'status' => 'ok', 'kind' => 'direct', 'data' => []],
                            ['name' => 'articles', 'status' => 'ok', 'kind' => 'direct', 'data' => []],
                            ['name' => 'gsc', 'status' => 'warning', 'kind' => 'tool', 'data' => []],
                        ],
                    ),
                    routingInput: new PreparedModelInput('decision', []),
                    answerInput: new PreparedModelInput('answer', []),
                    answerModelCalled: false,
                    executionTrace: [
                        'router' => 'local',
                        'outcome' => 'confident',
                        'operation' => 'seo.cross_module_overview',
                        'capabilities' => ['keywords.landscape', 'articles.inventory', 'gsc.performance'],
                        'tools' => ['keywords', 'articles', 'gsc'],
                        'jev_scores' => ['global' => 0.85, 'internal' => 0.82],
                    ],
                );
            });
        $this->app->instance(AgentTurnCoordinator::class, $coordinator);

        $turn1 = $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Tổng hợp từ khóa, bài viết và GSC của site?',
        ], $this->authHeaders())->assertStatus(200);

        $turn1->assertJsonPath('data.diagnostics.capabilities', ['keywords.landscape', 'articles.inventory', 'gsc.performance'])
            ->assertJsonPath('data.diagnostics.warnings.0.source', 'gsc');

        $threadUlid = $turn1->json('data.thread_ulid');

        $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'thread_ulid' => $threadUlid,
            'message' => 'Nhóm từ khóa nào yếu nhất?',
        ], $this->authHeaders())->assertStatus(200);

        $this->assertCount(0, $calls[0]['history']);
        $this->assertCount(2, $calls[1]['history']);
        $this->assertSame('user', $calls[1]['history'][0]['role']);
        $this->assertSame('assistant', $calls[1]['history'][1]['role']);

        $threadRes = $this->getJson('/api/agent-qa/v1/threads/'.$threadUlid, $this->authHeaders())
            ->assertStatus(200);
        $this->assertCount(4, $threadRes->json('data.messages'));
    }

    public function test_blocks_paid_execution_and_paid_consent(): void
    {
        $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Phân tích SEO',
            'tier' => 'pro',
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'paid_execution_blocked');

        $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Phân tích SEO',
            'tier' => 'master',
            'paid_consent' => true,
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'paid_execution_blocked');

        $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Phân tích SEO',
            'paid_consent' => true,
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'paid_execution_blocked');
    }

    public function test_blocks_mutation_and_never_auto_confirms_on_thread_read(): void
    {
        $coordinator = $this->createMock(AgentTurnCoordinator::class);
        $coordinator->expects($this->once())
            ->method('send')
            ->willReturn(new AgentTurnResult(
                response: null,
                routingInput: new PreparedModelInput('decision', []),
                answerInput: null,
                answerModelCalled: false,
                confirmationProposal: new AgentToolConfirmationProposal(
                    intent: 'publish_article',
                    primaryCapability: 'seo_audit.publish',
                    capabilities: ['seo_audit.publish'],
                    parameters: ['article_id' => 123],
                    responseTemplate: 'text',
                    responseLanguage: 'vi',
                    toolCapabilities: ['seo_audit.publish'],
                    scope: ['type' => 'site', 'site_id' => 10, 'site_ref' => 'site:10'],
                ),
                executionTrace: [
                    'router' => 'local',
                    'outcome' => 'confident',
                    'operation' => 'seo_audit.publish',
                    'capabilities' => ['seo_audit.publish'],
                ],
            ));
        $this->app->instance(AgentTurnCoordinator::class, $coordinator);

        $res = $this->postJson('/api/agent-qa/v1/turns', [
            'site_id' => 10,
            'message' => 'Đăng bài viết 123 lên WordPress ngay',
        ], $this->authHeaders())->assertStatus(200);

        $res->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.blocked_reason', 'mutation_blocked');

        $threadUlid = $res->json('data.thread_ulid');
        $runUlid = $res->json('data.run_ulid');

        // Reading thread must NOT auto-confirm the pending mutation run.
        $this->getJson('/api/agent-qa/v1/threads/'.$threadUlid, $this->authHeaders())
            ->assertStatus(200);

        $this->assertSame('awaiting_confirmation', AgentRun::where('ulid', $runUlid)->value('status'));
    }

    public function test_rate_limit_enforced(): void
    {
        config()->set('agent-runtime.qa_api.rate_limit_per_minute', 2);

        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders())->assertStatus(200);
        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders())->assertStatus(200);
        $this->getJson('/api/agent-qa/v1/health', $this->authHeaders())->assertStatus(429);
    }

    public function test_existing_web_routes_remain_protected_by_web_auth(): void
    {
        $this->getJson('/agent-runtime/projects', $this->authHeaders())
            ->assertStatus(401);
    }

    public function test_input_only_returns_stage_diagnostics_without_calling_models(): void
    {
        $response = $this->postJson('/api/agent-qa/v1/input-only', [
            'site_id' => 10,
            'message' => 'Internal link là gì?',
        ], $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('data.status', 'no_retrieval_required')
            ->assertJsonPath('data.query_understanding.intent', 'general_knowledge')
            ->assertJsonPath('data.model_input', null)
            ->assertJsonPath('data.model_execution.decision_model_called', false)
            ->assertJsonPath('data.model_execution.answer_model_called', false)
            ->assertJsonPath('data.runtime_metrics.php_peak_memory_bytes', fn ($value): bool => is_int($value) && $value > 0)
            ->assertJsonStructure(['data' => ['thread_ulid', 'run_ulid', 'stage_timing_ms']]);

        $runUlid = (string) $response->json('data.run_ulid');
        $run = AgentRun::where('ulid', $runUlid)->firstOrFail();
        self::assertSame('done', $run->status);
        self::assertSame('not_called_input_only', $run->answer_model);
    }

    public function test_input_only_large_dataset_returns_authenticated_jsonl_package(): void
    {
        $this->app->instance(SeoAccessTransport::class, new class implements SeoAccessTransport {
            public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
            {
                if ($method === 'POST') {
                    return ['status' => 200, 'json' => ['data' => ['access_url' => 'https://seo-ops.test/api/v1/access/test', 'expires_at' => now()->addHour()->toIso8601String()]]];
                }
                return ['status' => 200, 'json' => ['data' => [
                    'schema' => 'seo.access.articles.v2',
                    'site_ref' => 'site:10',
                    'articles' => [],
                    'pagination' => ['requested' => 250, 'returned' => 0, 'total' => 0, 'complete' => true],
                ]]];
            }
        });
        $this->app->instance(ResolvesSettingsPromptHook::class, new class implements ResolvesSettingsPromptHook {
            public function resolveSettingsHook(string $hookKey): SeoPrompt
            {
                $prompt = new SeoPrompt();
                $prompt->forceFill(['hook_key' => $hookKey, 'markdown_content' => 'Use only the supplied evidence.']);
                return $prompt;
            }
        });
        $seoSchema = Schema::connection('omi_seo_ai');
        if (! $seoSchema->hasTable('articles')) {
            $seoSchema->create('articles', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('title');
                $table->string('slug')->nullable();
                $table->string('status')->default('draft');
                $table->softDeletes();
                $table->timestamps();
            });
            $this->beforeApplicationDestroyed(static function (): void {
                Schema::connection('omi_seo_ai')->dropIfExists('articles');
            });
        }

        \Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('articles')->insert([
            ['site_id' => 10, 'title' => 'WP published', 'status' => 'publish'],
            ['site_id' => 10, 'title' => 'Editor published', 'status' => 'published'],
            ['site_id' => 10, 'title' => 'Draft', 'status' => 'draft'],
            ['site_id' => 10, 'title' => 'Trash', 'status' => 'trash'],
            ['site_id' => 20, 'title' => 'Other site', 'status' => 'publish'],
        ]);

        $response = $this->postJson('/api/agent-qa/v1/input-only', [
            'site_id' => 10,
            'message' => 'Xuất tối đa 250 bài đã xuất bản của website, mỗi bài chỉ cần tiêu đề và URL hoặc mã bài.',
        ], $this->authHeaders())->assertOk()
            ->assertJsonPath('data.query_understanding.intent', 'dataset')
            ->assertJsonPath('data.model_execution.answer_model_called', false)
            ->assertJsonPath('data.dataset_manifest.status', 'ready')
            ->assertJsonPath('data.dataset_manifest.format', 'jsonl')
            ->assertJsonPath('data.dataset_manifest.matched_total', 2)
            ->assertJsonPath('data.dataset_manifest.exported', 2)
            ->assertJsonPath('data.dataset_manifest.preview_count', 2)
            ->assertJsonPath('data.model_input', null)
            ->assertJsonPath('data.dataset_manifest.site_ref', 'site:10');

        $id = (string) $response->json('data.dataset_manifest.id');
        $lines = file(storage_path('app/private/agent-qa-datasets/'.$id.'.jsonl'), FILE_IGNORE_NEW_LINES);
        self::assertSame($response->json('data.dataset_manifest.preview'), array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        ));
        $this->get('/api/agent-qa/v1/datasets/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertHeader('content-type', 'application/x-ndjson; charset=UTF-8');

        @unlink(storage_path('app/private/agent-qa-datasets/'.$id.'.jsonl'));
        @unlink(storage_path('app/private/agent-qa-datasets/'.$id.'.manifest.json'));
    }
}
