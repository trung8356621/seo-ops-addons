<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionRequest;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionResult;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AgentRuntime\Model\PreparedModelInput;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessBusinessModulesComposer;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Domain\AgentHostContext;
use Omnichannel\Addons\AgentRuntime\Filament\Pages\AgentRuntimePage;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Projects\EloquentSiteDirectory;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

final class AgentRuntimeContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(
            \Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook::class,
            new class implements \Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): \Omnichannel\Addons\AiPrompt\Models\SeoPrompt
                {
                    $type = match ($hookKey) {
                        'agent.routing.decide' => 'routing',
                        'agent.response.compose' => 'response',
                        default => throw new \RuntimeException("Missing prompt binding: {$hookKey}"),
                    };
                    $prompt = new \Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
                    $prompt->forceFill([
                        'hook_key' => $hookKey,
                        'markdown_content' => \Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown($type),
                    ]);

                    return $prompt;
                }
            },
        );
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('articles')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('articles', function ($table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->nullable();
                $table->string('title')->nullable();
                $table->string('slug')->nullable();
                $table->string('status')->default('publish');
                $table->string('review_status')->nullable();
                $table->integer('document_version')->default(1);
                $table->softDeletes();
                $table->timestamps();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('seo_article_profiles', function ($table) {
                $table->id();
                $table->unsignedBigInteger('article_id');
                $table->string('focus_keyword')->nullable();
                $table->float('seo_score')->nullable();
                $table->integer('internal_link_count')->default(0);
                $table->integer('external_link_count')->default(0);
                $table->timestamps();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('seo_projects')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('seo_projects', function ($table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->nullable();
                $table->string('name')->nullable();
                $table->date('month')->nullable();
                $table->string('status')->default('draft');
                $table->timestamp('archived_at')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('seo_project_tasks', function ($table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('site_id')->nullable();
                $table->unsignedBigInteger('article_id')->nullable();
                $table->string('task_type')->nullable();
                $table->string('status')->default('pending');
                $table->string('lifecycle_state')->default('draft');
                $table->string('publish_queue_status')->nullable();
                $table->timestamp('scheduled_publish_at')->nullable();
                $table->timestamp('last_publish_attempt_at')->nullable();
                $table->timestamp('publish_published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('seo_project_runs')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('seo_project_runs', function ($table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('status')->default('completed');
                $table->timestamps();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->create('wordpress_article_links', function ($table) {
                $table->id();
                $table->unsignedBigInteger('article_id')->nullable();
                $table->unsignedBigInteger('wp_post_id')->nullable();
                $table->timestamps();
            });
        }

        \Illuminate\Support\Facades\Config::set('database.core_connection', 'sqlite');

        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            \Illuminate\Support\Facades\Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default('user');
                $table->string('status')->default('normal');
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('sites')) {
            \Illuminate\Support\Facades\Schema::create('sites', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('domain')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $owner = \App\Models\User::query()->firstOrCreate(
            ['email' => 'agent-runtime-test@test.test'],
            [
                'name' => 'Agent Runtime Test Owner',
                'password' => bcrypt('secret'),
                'role' => \App\Models\User::ROLE_OWNER,
                'status' => \App\Models\User::STATUS_NORMAL,
            ]
        );
        $this->actingAs($owner);

        if (!\App\Models\Site::query()->where('id', 7)->exists()) {
            \App\Models\Site::query()->forceCreate([
                'id' => 7,
                'user_id' => $owner->id,
                'domain' => 'site7.test',
                'status' => 'active',
            ]);
        }

        $realConn = \Illuminate\Support\Facades\DB::connection('omi_seo_ai');
        if ($realConn instanceof \Illuminate\Database\SQLiteConnection && ! property_exists($realConn, 'isCompatWrapped')) {
            $wrappedConn = new class($realConn->getPdo(), $realConn->getDatabaseName(), $realConn->getTablePrefix(), $realConn->getConfig()) extends \Illuminate\Database\SQLiteConnection {
                public bool $isCompatWrapped = true;

                public function selectOne($query, $bindings = [], $useReadPdo = true)
                {
                    if (str_contains($query, 'DATE_SUB')) {
                        $query = (string) preg_replace('/DATE_SUB\(NOW\(\),\s*INTERVAL\s+(\d+)\s+MINUTE\)/i', "datetime('now', '-$1 minutes')", $query);
                    }
                    return parent::selectOne($query, $bindings, $useReadPdo);
                }

                public function select($query, $bindings = [], $useReadPdo = true)
                {
                    if (str_contains($query, 'DATE_SUB')) {
                        $query = (string) preg_replace('/DATE_SUB\(NOW\(\),\s*INTERVAL\s+(\d+)\s+MINUTE\)/i', "datetime('now', '-$1 minutes')", $query);
                    }
                    return parent::select($query, $bindings, $useReadPdo);
                }
            };
            $db = app('db');
            $prop = new \ReflectionProperty($db, 'connections');
            $prop->setAccessible(true);
            $conns = $prop->getValue($db);
            $conns['omi_seo_ai'] = $wrappedConn;
            $prop->setValue($db, $conns);
        }
    }

    public function test_runtime_does_not_import_legacy_agent_workspace(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('Omnichannel\\Addons\\Agent\\', $source, $file->getPathname());
            self::assertStringNotContainsString('seo_agent_', $source, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\b(jev|laya)\b/i', $source, $file->getPathname());
        }
    }

    public function test_legacy_agent_addon_stays_reference_only(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3).'/agent/README.md');
        self::assertStringContainsString('LEGACY / REFERENCE-ONLY', $readme);
        $provider = (string) file_get_contents(dirname(__DIR__, 3).'/agent/src/AgentServiceProvider.php');
        self::assertStringContainsString('REFERENCE-ONLY', $provider);
        self::assertStringNotContainsString('loadRoutesFrom', $provider);
    }

    public function test_global_scope_is_not_a_fake_site_id(): void
    {
        $global = AgentProjectScope::global();
        self::assertSame(['type' => 'global'], $global->toArray());
        self::assertNull($global->siteId);
        self::assertTrue($global->isGlobal());

        $this->expectException(InvalidArgumentException::class);
        AgentProjectScope::site(0);
    }

    public function test_module_aware_routing_decision_schema_and_parameters(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"is_in_scope":true,"intent":"improve articles","primary_module":"articles","modules":["articles","topics","keywords","internal_links","gsc"],"parameters":{"period":"2026-09","task":"improve","limit_min":15,"limit_max":30},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"table"}');
        self::assertSame('2026-09', $decision->parameters['period']);
        self::assertSame('articles', $decision->primaryModule);
        self::assertSame(15, $decision->parameters['limit_min']);
        self::assertSame(30, $decision->parameters['limit_max']);
        self::assertContains('internal_links', $decision->modules);
        self::assertSame('table', $decision->responseTemplate);

        $this->expectException(InvalidArgumentException::class);
        $parser->parse('{"intent":"bad","primary_module":"unknown","modules":["unknown"],"parameters":{}}');
    }

    public function test_response_template_contract_fails_closed_for_missing_or_unknown_values(): void
    {
        $parser = new RetrievalDecisionParser();

        foreach ([
            '{"is_in_scope":true,"intent":"x","primary_module":"site","modules":["site"],"parameters":{}}',
            '{"is_in_scope":true,"intent":"x","primary_module":"site","modules":["site"],"parameters":{},"response_template":"dashboard"}',
        ] as $raw) {
            try {
                $parser->parse($raw);
                self::fail('Invalid response template was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('response_template', $e->getMessage());
            }
        }

        $legacy = $parser->parse('{"intent":"legacy","needs":{"site":1},"parameters":{}}');
        self::assertSame('text', $legacy->responseTemplate);
        self::assertTrue($legacy->isInScope);
    }

    public function test_scope_contract_accepts_no_retrieval_and_rejects_contradictions(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"is_in_scope":true,"intent":"summarize conversation","primary_module":null,"modules":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text"}');
        self::assertTrue($decision->isInScope);
        self::assertNull($decision->primaryModule);
        self::assertSame([], $decision->modules);

        foreach ([
            '{"intent":"missing scope","primary_module":"site","modules":["site"],"parameters":{},"response_template":"text"}',
            '{"is_in_scope":false,"intent":"weather","primary_module":"gsc","modules":["gsc"],"parameters":{},"response_template":"text"}',
            '{"is_in_scope":false,"intent":"weather","primary_module":"site","modules":[],"parameters":{},"response_template":"text"}',
            '{"is_in_scope":false,"intent":"weather","primary_module":null,"modules":[],"parameters":{},"response_template":"report"}',
        ] as $raw) {
            try {
                $parser->parse($raw);
                self::fail('Contradictory scope decision was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_in_scope_no_retrieval_skips_retrieval_and_runs_answer_stage(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"summarize conversation","primary_module":null,"modules":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text"}'),
            $answers,
            $transport,
        );

        $result = $coordinator->send(1, AgentProjectScope::site(7), 'Tóm tắt cuộc trao đổi này', []);

        self::assertSame([], $transport->calls);
        self::assertSame(1, $answers->calls);
        self::assertTrue($result->answerModelCalled);
        self::assertStringContainsString('"selected_response_template":"text"', $result->answerInput->exportText());
    }

    public function test_out_of_scope_stops_before_retrieval_and_answer_model(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":false,"intent":"weather request","primary_module":null,"modules":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text"}'),
            $answers,
            $transport,
        );

        $result = $coordinator->send(1, AgentProjectScope::site(7), 'Thời tiết hôm nay?', []);

        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
        self::assertFalse($result->answerModelCalled);
        self::assertSame([], $result->response->actions);
        self::assertSame([], $result->response->sources);
        self::assertStringContainsString('ngoài phạm vi Agent SEO nội bộ', $result->response->message);
    }

    public function test_plain_single_article_detail_is_out_of_scope_without_retrieval_or_answer_model(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":false,"intent":"single article detail lookup","primary_module":null,"modules":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text"}'),
            $answers,
            $transport,
        );

        $result = $coordinator->send(1, AgentProjectScope::site(7), 'Thông tin article:123', []);

        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
        self::assertFalse($result->answerModelCalled);
        self::assertSame([], $result->response->actions);
        self::assertSame([], $result->response->sources);
    }

    public function test_scope_contract_evaluation_matrix(): void
    {
        $cases = [
            ['Tình hình SEO site hiện tại?', true, 'site', ['site', 'gsc'], 'report'],
            ['Cho tôi 20 bài tệ nhất', true, 'articles', ['articles'], 'table'],
            ['Thông tin article:123', false, null, [], 'text'],
            ['Nên viết thêm gì?', true, 'topics', ['topics', 'keywords', 'site'], 'report'],
            ['Bạn có thể làm gì?', true, null, [], 'text'],
            ['Tóm tắt cuộc trao đổi này', true, null, [], 'text'],
            ['Thời tiết hôm nay?', false, null, [], 'text'],
            ['Viết game React', false, null, [], 'text'],
            ['Ai là tổng thống Mỹ?', false, null, [], 'text'],
            ['Kể chuyện cười', false, null, [], 'text'],
        ];
        $parser = new RetrievalDecisionParser();

        foreach ($cases as [$request, $isInScope, $primary, $modules, $template]) {
            $decision = $parser->parse(json_encode([
                'is_in_scope' => $isInScope,
                'intent' => $request,
                'primary_module' => $primary,
                'modules' => $modules,
                'parameters' => [],
                'requires_parameter_extraction' => false,
                'requires_user_confirmation' => false,
                'response_template' => $template,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            self::assertSame($isInScope, $decision->isInScope, $request);
            self::assertSame($modules !== [], $decision->modules !== [], $request);
            self::assertSame($template, $decision->responseTemplate, $request);
        }
    }

    public function test_routing_catalog_and_selected_template_reach_model_inputs(): void
    {
        $scope = AgentProjectScope::site(7);
        $builder = new AgentModelInputBuilder();
        $routing = $builder->buildRoutingInput($scope, 'Analyze the site', [])->exportText();
        $answer = $builder->buildAnswerInput($scope, 'Analyze the site', [], new RetrievalBundle($scope, []), 'report')->exportText();

        foreach (['text', 'table', 'schema', 'report'] as $template) {
            self::assertStringContainsString('"key":"'.$template.'"', $routing);
        }
        self::assertStringContainsString('"capability_catalog"', $routing);
        self::assertStringContainsString('"key":"seo_audit.worst_articles"', $routing);
        self::assertStringNotContainsString('"resource_catalog"', $routing);
        self::assertStringNotContainsString('"requires_confirmation"', $routing);
        self::assertStringNotContainsString('industry.core_small', $routing);
        self::assertStringContainsString('"selected_response_template":"report"', $answer);
    }

    public function test_agent_capability_catalog_visibility_and_metadata_invariants(): void
    {
        $visible = array_column(AgentCapabilityCatalog::modelVisible(), 'key');
        foreach ([
            'site.knowledge', 'keywords.landscape', 'keywords.relationship', 'articles.inventory',
            'links.internal', 'links.external',
            'gsc.performance', 'content_projects.read', 'seo_audit.worst_articles',
        ] as $key) {
            self::assertContains($key, $visible);
        }
        foreach ([
            'site.network', 'industry.core', 'seo_audit.improve', 'seo_audit.publish',
            'industry.core_small', 'industry.discovery', 'industry.breakout', 'seo.router',
            'industry.match', 'content_project.draft.intake', 'content_project.write', 'domain.audit',
        ] as $key) {
            self::assertNotContains($key, $visible);
        }

        foreach (AgentCapabilityCatalog::all() as $metadata) {
            if ($metadata['execution_mode'] === 'tool') {
                self::assertTrue($metadata['jev_selectable']);
                self::assertTrue($metadata['requires_confirmation']);
            }
            if ($metadata['execution_mode'] === 'direct') {
                self::assertTrue($metadata['jev_selectable']);
                self::assertFalse($metadata['requires_confirmation']);
            }
            if (in_array($metadata['execution_mode'], ['companion', 'internal'], true)) {
                self::assertFalse($metadata['jev_selectable']);
            }
        }
        foreach (['site.network', 'industry.core', 'seo_audit.improve', 'seo_audit.publish'] as $key) {
            self::assertSame('not_connected', AgentCapabilityCatalog::get($key)['status']);
            self::assertTrue(AgentCapabilityCatalog::isSelectable($key));
            self::assertFalse(AgentCapabilityCatalog::isAvailable($key));
        }
    }

    public function test_capability_routing_contract_maps_to_internal_modules_and_fails_closed(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"is_in_scope":true,"intent":"find poor articles","primary_capability":"seo_audit.worst_articles","capabilities":["seo_audit.worst_articles","keywords.landscape"],"parameters":{"limit_max":20},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"table","response_language":"en"}');
        self::assertSame('seo_audit.worst_articles', $decision->primaryCapability);
        self::assertSame(['seo_audit.worst_articles', 'keywords.landscape'], $decision->capabilities);
        self::assertSame(['articles', 'keywords'], $decision->modules);

        $noCapability = $parser->parse('{"is_in_scope":true,"intent":"explain capabilities","primary_capability":null,"capabilities":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        self::assertSame([], $noCapability->capabilities);
        self::assertSame([], $noCapability->modules);

        $outOfScope = $parser->parse('{"is_in_scope":false,"intent":"weather","primary_capability":null,"capabilities":[],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        self::assertFalse($outOfScope->isInScope);
        self::assertSame([], $outOfScope->capabilities);

        foreach ([
            '{"is_in_scope":true,"intent":"x","primary_capability":"unknown","capabilities":["unknown"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"industry.discovery","capabilities":["industry.discovery"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"industry.core_small","capabilities":["industry.core_small"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"site.network","capabilities":["site.network"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"industry.core","capabilities":["industry.core"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"seo_audit.improve","capabilities":["seo_audit.improve"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"seo_audit.publish","capabilities":["seo_audit.publish"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"site.knowledge","capabilities":["site.knowledge","site.knowledge"],"parameters":{},"response_template":"text","response_language":"en"}',
            '{"is_in_scope":true,"intent":"x","primary_capability":"site.knowledge","capabilities":["keywords.landscape"],"parameters":{},"response_template":"text","response_language":"en"}',
        ] as $raw) {
            try {
                $parser->parse($raw);
                self::fail('Invalid capability decision was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_tool_capabilities_pause_whole_decision_before_retrieval_or_answer(): void
    {
        foreach ([
            ['gsc.performance', ['gsc.performance'], ['period' => '2026-09']],
            ['seo_audit.worst_articles', ['seo_audit.worst_articles'], ['limit_max' => 20]],
            ['seo_audit.worst_articles', ['seo_audit.worst_articles', 'keywords.landscape'], ['limit_max' => 20]],
        ] as [$primary, $capabilities, $parameters]) {
            $answers = new RecordingAnswerGateway();
            $transport = new RecordingTransport();
            $raw = json_encode([
                'is_in_scope' => true,
                'intent' => 'tool request',
                'primary_capability' => $primary,
                'capabilities' => $capabilities,
                'parameters' => $parameters,
                'requires_parameter_extraction' => false,
                'requires_user_confirmation' => false,
                'response_template' => $primary === 'gsc.performance' ? 'report' : 'table',
            ], JSON_THROW_ON_ERROR);
            $result = $this->coordinator(new ScriptedDecisionGateway($raw), $answers, $transport)
                ->send(1, AgentProjectScope::site(7), 'Run tool', []);

            self::assertNotNull($result->confirmationProposal);
            self::assertSame([$primary], $result->confirmationProposal->toolCapabilities);
            self::assertSame($capabilities, $result->confirmationProposal->capabilities);
            self::assertSame(['type' => 'site', 'site_id' => 7, 'site_ref' => 'site:7'], $result->confirmationProposal->scope);
            self::assertSame([], $transport->calls);
            self::assertSame(0, $answers->calls);
            self::assertNull($result->answerInput);
            self::assertNull($result->response);
        }
    }

    public function test_registry_ignores_model_confirmation_flag_for_direct_capability(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $result = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"keywords","primary_capability":"keywords.landscape","capabilities":["keywords.landscape"],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":true,"response_template":"report","response_language":"en"}'),
            $answers,
            $transport,
        )->send(1, AgentProjectScope::site(7), 'Keywords?', []);

        self::assertNull($result->confirmationProposal);
        self::assertNotEmpty($transport->calls);
        self::assertSame(1, $answers->calls);
    }

    public function test_confirmation_run_is_persisted_and_blocks_thread_archive(): void
    {
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"analyze GSC","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report","response_language":"en"}'), new RecordingAnswerGateway(), new RecordingTransport());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();

        $response = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze GSC September',
        ]), $coordinator, $sites, $threads, $persistence);
        $data = $response->getData(true)['data'];
        self::assertSame('awaiting_confirmation', $data['status']);
        self::assertSame(['intent' => 'analyze GSC', 'tool_capabilities' => ['gsc.performance'], 'parameters' => ['period' => '2026-09']], $data['confirmation']);
        self::assertStringContainsString('GSC Performance', $data['message']);
        self::assertSame([
            ['type' => 'confirmation', 'action' => 'confirm', 'label' => 'Xác nhận', 'run_ulid' => $data['run_ulid']],
            ['type' => 'confirmation', 'action' => 'reject', 'label' => 'Từ chối', 'run_ulid' => $data['run_ulid']],
        ], $data['actions']);

        $run = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $data['run_ulid'])->firstOrFail();
        self::assertSame('awaiting_confirmation', $run->status);
        self::assertSame([
            'intent' => 'analyze GSC',
            'primary_capability' => 'gsc.performance',
            'capabilities' => ['gsc.performance'],
            'parameters' => ['period' => '2026-09'],
            'response_template' => 'report',
            'tool_capabilities' => ['gsc.performance'],
            'scope' => ['type' => 'site', 'site_id' => 7, 'site_ref' => 'site:7'],
        ], $run->retrieval_summary['confirmation']['proposal']);

        $archive = $controller->archiveThread($this->createTurnRequest([], userId: 1), $data['thread_ulid'], $threads);
        self::assertSame(422, $archive->getStatusCode());
    }

    public function test_intercepted_tool_decision_yields_confirmation_not_answer_pause(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new RecordingDecisionGateway('unused'), $answers, $transport);
        $scope = AgentProjectScope::site(7);
        $start = $coordinator->startIntercepted($scope, 'Analyze GSC', []);

        $progress = $coordinator->resumeIntercepted(
            $scope,
            'Analyze GSC',
            [],
            'decision',
            '{"is_in_scope":true,"intent":"analyze GSC","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report","response_language":"en"}',
            $start->modelCall->state,
        );

        self::assertNotNull($progress->confirmationProposal);
        self::assertNull($progress->modelCall);
        self::assertNull($progress->result);
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    public function test_confirmed_gsc_uses_frozen_proposal_without_second_decision_and_blocks_replay(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"analyze GSC","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $audit);

        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze GSC September',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];
        self::assertSame([], $transport->calls);

        $confirmed = $controller->confirmRun(
            $this->createTurnRequest([], userId: 1),
            $pending['run_ulid'],
            $sites,
            $persistence,
            $threads,
            $tools,
            $coordinator,
        );
        self::assertSame(200, $confirmed->getStatusCode());
        self::assertSame(1, $decisions->calls);
        self::assertSame(1, $answers->calls);
        self::assertStringContainsString('2026-09', $answers->lastExport);
        self::assertStringContainsString('report', $answers->lastExport);
        self::assertStringContainsString('/gsc?period=2026-09', $transport->calls[1]['url']);

        $replay = $controller->confirmRun(
            $this->createTurnRequest([], userId: 1),
            $pending['run_ulid'],
            $sites,
            $persistence,
            $threads,
            $tools,
            $coordinator,
        );
        self::assertSame(409, $replay->getStatusCode());
        self::assertSame(1, $answers->calls);
        $rejectReplay = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource('2026-09'),
        );
        self::assertSame(409, $rejectReplay->getStatusCode());
    }

    public function test_confirmed_content_projects_uses_collection_projection_only(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"projects","primary_capability":"content_projects.read","capabilities":["content_projects.read"],"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"table","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Projects this month',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $response = $controller->confirmRun($this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads, $tools, $coordinator);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/content-projects?period=2026-09', $transport->calls[1]['url']);
        self::assertStringNotContainsString('/content-projects/', $transport->calls[1]['url']);
    }

    public function test_confirmed_mixed_worst_articles_uses_low_score_adapter_and_keeps_zero_candidates(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"prioritize","primary_capability":"seo_audit.worst_articles","capabilities":["seo_audit.worst_articles","keywords.landscape"],"parameters":{"limit_max":20},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"table","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->expects(self::once())->method('listArticles')->with(
            self::callback(static fn ($context): bool => $context->resolvedSiteId === 7),
            ['low_score' => true, 'limit' => 20],
        )->willReturn(['items' => [], 'total' => 0, 'post_type' => null]);
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $audit);
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Worst articles and keywords',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];
        self::assertSame([], $transport->calls);

        $response = $controller->confirmRun($this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads, $tools, $coordinator);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $answers->calls);
        self::assertStringContainsString('SeoAuditAgentReadService::listArticles?low_score=true&limit=20', $answers->lastExport);
        self::assertStringContainsString('"total":0', $answers->lastExport);
        self::assertStringContainsString('/keywords', $transport->calls[1]['url']);
        self::assertStringNotContainsString('/articles', implode(' ', array_column($transport->calls, 'url')));
    }

    /** @dataProvider normalRejectProvider */
    public function test_normal_reject_finishes_without_tool_retrieval_or_answer(string $capability): void
    {
        $decisions = new RecordingDecisionGateway(json_encode([
            'is_in_scope' => true,
            'intent' => 'reject normally',
            'primary_capability' => $capability,
            'capabilities' => [$capability],
            'parameters' => [],
            'requires_parameter_extraction' => false,
            'requires_user_confirmation' => false,
            'response_template' => 'text',
        ], JSON_THROW_ON_ERROR));
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Projects',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $response = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource('2026-09'),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Đã từ chối yêu cầu.', $response->getData(true)['data']['message']);
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
        self::assertSame('done', \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $pending['run_ulid'])->value('status'));
        $confirmReplay = $controller->confirmRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads, $tools, $coordinator,
        );
        self::assertSame(409, $confirmReplay->getStatusCode());
    }

    public static function normalRejectProvider(): array
    {
        return [['content_projects.read'], ['seo_audit.worst_articles']];
    }

    public function test_gsc_reject_uses_latest_synced_period_and_answers_once(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{"period":"2026-06"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'GSC June',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $response = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource('2026-09'),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $decisions->calls);
        self::assertSame(1, $answers->calls);
        $urls = implode(' ', array_column($transport->calls, 'url'));
        self::assertStringContainsString('/gsc?period=2026-09', $urls);
        self::assertStringNotContainsString('/gsc?period=2026-06', $urls);
        self::assertStringContainsString('09/2026', $response->getData(true)['data']['message']);
    }

    public function test_gsc_reject_without_synced_data_is_deterministic(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{"period":"2026-06"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'GSC June',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $response = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource(null),
        );
        self::assertSame('Không có dữ liệu GSC đã đồng bộ để sử dụng thay thế.', $response->getData(true)['data']['message']);
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    public function test_gsc_reject_with_direct_capability_executes_both_frozen_reads(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc keywords","primary_capability":"gsc.performance","capabilities":["gsc.performance","keywords.landscape"],"parameters":{"period":"2026-06"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'GSC and keywords',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $response = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource('2026-09'),
        );
        self::assertSame(200, $response->getStatusCode());
        $urls = implode(' ', array_column($transport->calls, 'url'));
        self::assertStringContainsString('/gsc?period=2026-09', $urls);
        self::assertStringContainsString('/keywords', $urls);
        self::assertSame(1, $answers->calls);
    }

    public function test_gsc_reject_with_another_tool_rejects_everything(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc projects","primary_capability":"gsc.performance","capabilities":["gsc.performance","content_projects.read"],"parameters":{"period":"2026-06"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'GSC and projects',
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];

        $response = $controller->rejectRun(
            $this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads,
            $tools, $coordinator, $this->gscSource('2026-09'),
        );
        self::assertSame('Đã từ chối yêu cầu.', $response->getData(true)['data']['message']);
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    /** @dataProvider unsupportedConfirmedToolProvider */
    public function test_unsupported_confirmed_tools_fail_closed(string $capability): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => $capability,
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];
        $run = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $pending['run_ulid'])->firstOrFail();
        $summary = $run->retrieval_summary;
        $summary['confirmation']['proposal']['intent'] = $capability;
        $summary['confirmation']['proposal']['primary_capability'] = $capability;
        $summary['confirmation']['proposal']['capabilities'] = [$capability];
        $summary['confirmation']['proposal']['tool_capabilities'] = [$capability];
        $run->update(['retrieval_summary' => $summary]);

        $response = $controller->confirmRun($this->createTurnRequest([], userId: 1), $pending['run_ulid'], $sites, $persistence, $threads, $tools, $coordinator);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    public static function unsupportedConfirmedToolProvider(): array
    {
        return [['seo_audit.improve'], ['seo_audit.publish']];
    }

    public function test_confirm_revalidates_site_access(): void
    {
        $decisions = new RecordingDecisionGateway('{"is_in_scope":true,"intent":"gsc","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"text","response_language":"en"}');
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $visible = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();
        $pending = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'GSC',
        ]), $coordinator, $visible, $threads, $persistence)->getData(true)['data'];
        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));

        $response = $controller->confirmRun($this->createTurnRequest([], userId: 1), $pending['run_ulid'], new InMemorySiteDirectory(), $persistence, $threads, $tools, $coordinator);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    public function test_debug_apply_persists_tool_confirmation_instead_of_answer_pause(): void
    {
        $answers = new RecordingAnswerGateway();
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new RecordingDecisionGateway('unused'), $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $start = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Find worst articles',
            'debug_mode' => true,
        ]), $coordinator, $sites, $threads, $persistence, $resolver)->getData(true)['data'];
        self::assertSame('decision', $start['model_call']['key']);

        $applied = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $start['run_ulid'],
            'manual_result' => '{"is_in_scope":true,"intent":"find worst articles","primary_capability":"seo_audit.worst_articles","capabilities":["seo_audit.worst_articles","keywords.landscape"],"parameters":{"limit_max":20},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"table","response_language":"en"}',
        ]), $coordinator, $threads, $persistence, $resolver)->getData(true)['data'];

        self::assertSame('awaiting_confirmation', $applied['status']);
        self::assertArrayNotHasKey('model_call', $applied);
        self::assertSame(['seo_audit.worst_articles'], $applied['confirmation']['tool_capabilities']);
        $run = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $start['run_ulid'])->firstOrFail();
        self::assertSame('awaiting_confirmation', $run->status);
        self::assertArrayNotHasKey('model_call', $run->retrieval_summary);

        $tools = new AgentConfirmedToolExecutor($this->retrievalExecutor($transport), $this->createMock(SeoAuditAgentReadService::class));
        $confirmed = $controller->confirmRun(
            $this->createTurnRequest([], userId: 1),
            $start['run_ulid'],
            $sites,
            $persistence,
            $threads,
            $tools,
            $coordinator,
            $resolver,
        )->getData(true)['data'];
        self::assertSame('paused', $confirmed['status']);
        self::assertSame('answer', $confirmed['model_call']['key']);
        self::assertSame(0, $answers->calls);
        self::assertStringContainsString('low_score=true&limit=20', $confirmed['model_call']['full_prompt']);
    }

    public function test_unavailable_gsc_is_not_measured_zero(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('gsc', 'unavailable', 'GET /gsc?period=2026-09', [
                'available' => false,
                'reason' => 'no_synced_data',
                'latest_available' => ['period' => '2026-07'],
            ], 'no_synced_data'),
        ]);
        $encoded = json_encode($bundle->toArray());
        self::assertIsString($encoded);
        self::assertStringContainsString('no_synced_data', $encoded);
        self::assertStringNotContainsString('"clicks":0', $encoded);

        $parser = new AgentResponseParser();
        $this->expectException(AgentResponseRejected::class);
        $parser->parse(json_encode([
            'message' => 'Traffic was zero.',
            'blocks' => [[
                'type' => 'chart',
                'chart' => 'line',
                'title' => 'Clicks',
                'x_key' => 'month',
                'series' => [['key' => 'clicks', 'label' => 'Clicks']],
                'data' => [['month' => '2026-09', 'clicks' => 0]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
    }

    public function test_malformed_chart_is_rejected(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('gsc', 'ok', 'GET /gsc', ['clicks' => 12]),
        ]);
        $parser = new AgentResponseParser();
        $this->expectException(AgentResponseRejected::class);
        $parser->parse('{"message":"x","blocks":[{"type":"chart","chart":"pie"}]}', $bundle);
    }

    public function test_chart_number_must_come_from_ok_evidence(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('gsc', 'ok', 'GET /gsc', [
                'performance' => [['month' => '2026-07', 'clicks' => 15]],
            ]),
        ]);
        $parser = new AgentResponseParser();
        $ok = $parser->parse(json_encode([
            'message' => 'July clicks were 15.',
            'blocks' => [[
                'type' => 'chart',
                'chart' => 'line',
                'title' => 'Clicks',
                'x_key' => 'month',
                'series' => [['key' => 'clicks', 'label' => 'Clicks']],
                'data' => [['month' => '2026-07', 'clicks' => 15]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
        self::assertSame(15, $ok->blocks[0]['data'][0]['clicks']);

        $this->expectException(AgentResponseRejected::class);
        $parser->parse(json_encode([
            'message' => 'Invented.',
            'blocks' => [[
                'type' => 'table',
                'columns' => [['key' => 'clicks', 'label' => 'Clicks']],
                'rows' => [['clicks' => 1200]],
            ]],
        ], JSON_THROW_ON_ERROR), $bundle);
    }

    public function test_executor_refuses_external_urls_and_hides_permanent_credentials(): void
    {
        $transport = new RecordingTransport('no_gsc_property');
        $executor = new SeoAccessExecutor(
            $transport,
            new class implements SeoAccessCredential {
                public function bearer(): ?string
                {
                    return 'svc_live_secret_value';
                }
            },
            new SeoAccessUrlPolicy(),
            'https://app.example.test',
        );
        $planner = new RetrievalPlanner();
        $decision = (new RetrievalDecisionParser())->parse('{"is_in_scope":true,"intent":"traffic","primary_module":"gsc","modules":["gsc"],"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false,"response_template":"report"}');
        $bundle = $executor->execute($planner->plan($decision, AgentProjectScope::site(7)));

        self::assertSame(['https://app.example.test/api/v1/services/seo/access'], array_values(array_unique(array_map(
            static fn (array $call): string => strtok($call['url'], '?') ?: $call['url'],
            array_filter($transport->calls, static fn (array $call): bool => $call['method'] === 'POST'),
        ))));
        foreach ($transport->calls as $call) {
            if ($call['method'] === 'GET') {
                self::assertStringStartsWith('https://app.example.test/api/v1/access/access_tmp_test/', $call['url']);
                self::assertStringNotContainsString('evil.example', $call['url']);
                self::assertNull($call['bearer']);
            }
        }
        $export = (new AgentModelInputBuilder())->buildAnswerInput(
            AgentProjectScope::site(7),
            'use svc_live_secret_value and Authorization: Bearer svc_live_secret_value',
            [],
            $bundle,
            'report',
        )->exportText();
        self::assertStringNotContainsString('svc_live_secret_value', $export);
        self::assertStringContainsString('no_synced_data', $export);
    }

    public function test_copy_and_send_share_prepared_model_input(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":false}'), $answers);
        $copy = $coordinator->copy(1, AgentProjectScope::site(7), 'What is the site about?', []);
        self::assertFalse($copy->answerModelCalled);
        self::assertSame(0, $answers->calls);

        $send = $coordinator->send(1, AgentProjectScope::site(7), 'What is the site about?', []);
        self::assertTrue($send->answerModelCalled);
        self::assertSame(1, $answers->calls);
        self::assertSame($copy->answerInput->exportText(), $send->answerInput->exportText());
        self::assertSame($send->answerInput->exportText(), $answers->lastExport);
    }

    public function test_global_scope_does_not_call_seo_access(): void
    {
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{"intent":"all","needs":{"gsc":1},"parameters":{}}'), new RecordingAnswerGateway(), $transport);
        $result = $coordinator->send(1, AgentProjectScope::global(), 'Compare every site', []);
        self::assertSame([], $transport->calls);
        self::assertStringContainsString('global_access_unsupported', $result->response->blocks[1]['text'] ?? '');
        self::assertFalse($result->answerModelCalled);
    }

    public function test_legacy_scores_normalize_immediately_and_cannot_drop_primary_module(): void
    {
        $planner = new RetrievalPlanner(0.9);
        $decision = (new RetrievalDecisionParser())->parse('{"intent":"maybe","needs":{"site":0.49,"gsc":0.2},"parameters":{}}');
        $plan = $planner->plan($decision, AgentProjectScope::site(7));
        self::assertSame('site', $decision->primaryModule);
        self::assertSame(['site'], array_map(static fn ($step) => $step->resource, $plan->steps));
    }

    public function test_module_parser_rejects_duplicates_missing_primary_unknown_modules_and_bad_ranges(): void
    {
        $parser = new RetrievalDecisionParser();
        foreach ([
            '{"intent":"x","primary_module":"articles","modules":["topics"],"parameters":{}}',
            '{"intent":"x","primary_module":"articles","modules":["articles","articles"],"parameters":{}}',
            '{"intent":"x","primary_module":"articles","modules":["articles","unknown"],"parameters":{}}',
            '{"intent":"x","primary_module":"articles","modules":["articles"],"parameters":{"limit_min":30,"limit_max":15}}',
            '{"intent":"x","primary_module":"articles","modules":["articles"],"parameters":{"limit_min":"15"}}',
            '{"intent":"x","primary_module":"articles","modules":["articles"],"parameters":{"url":"https://evil.example"}}',
        ] as $raw) {
            try {
                $parser->parse($raw);
                self::fail('Invalid decision was accepted: '.$raw);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_routing_prompt_encodes_capability_selection_boundaries(): void
    {
        $prompt = \Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing');
        self::assertStringContainsString('primary_capability', $prompt);
        self::assertStringContainsString('Select only keys present in capability_catalog', $prompt);
        self::assertStringContainsString('articles.inventory is collection-level', $prompt);
        self::assertStringContainsString('backend capability metadata is authoritative', $prompt);
        self::assertStringNotContainsString('Allowed modules:', $prompt);
        self::assertStringNotContainsString('probabilities from 0 to 1', $prompt);
    }

    public function test_business_modules_map_to_distinct_read_resources(): void
    {
        $decision = (new RetrievalDecisionParser())->parse('{"is_in_scope":true,"intent":"links","primary_module":"internal_links","modules":["internal_links","external_links","topics","content_projects"],"parameters":{"period":"2026-09"},"response_template":"table"}');
        $transport = new RecordingTransport();
        (new SeoAccessExecutor($transport, new class implements SeoAccessCredential { public function bearer(): ?string { return 'svc_live_x'; } }, new SeoAccessUrlPolicy(), 'https://app.example.test'))
            ->execute((new RetrievalPlanner())->plan($decision, AgentProjectScope::site(7)));
        $urls = array_column(array_filter($transport->calls, static fn (array $call): bool => $call['method'] === 'GET'), 'url');
        self::assertTrue((bool) array_filter($urls, static fn (string $url): bool => str_contains($url, '/internal-links')));
        self::assertTrue((bool) array_filter($urls, static fn (string $url): bool => str_contains($url, '/external-links')));
        self::assertTrue((bool) array_filter($urls, static fn (string $url): bool => str_contains($url, '/keywords')));
        self::assertTrue((bool) array_filter($urls, static fn (string $url): bool => str_contains($url, '/content-projects?period=2026-09')));
    }

    public function test_article_question_delivers_concrete_article_evidence_to_answer_input(): void
    {
        $processed = $this->coordinator(
            new ScriptedDecisionGateway('unused'),
            new RecordingAnswerGateway(),
            new ArticleEvidenceTransport(),
        )->processDecisionAndRetrieve(
            AgentProjectScope::site(7),
            'Tháng 9 này cần sửa những bài nào? gợi ý 15-30 bài',
            [],
            '{"is_in_scope":true,"intent":"review article inventory","primary_capability":"articles.inventory","capabilities":["articles.inventory","keywords.landscape"],"parameters":{"limit_min":15,"limit_max":30},"response_template":"table","response_language":"en"}',
        );
        self::assertSame('articles', $processed['decision']->primaryModule);
        self::assertStringContainsString('article:41', $processed['answerInput']->exportText());
        self::assertStringContainsString('Existing article title', $processed['answerInput']->exportText());
    }

    public function test_metadata_and_external_hosts_are_rejected(): void
    {
        $policy = new SeoAccessUrlPolicy();
        $access = 'https://app.example.test/api/v1/access/access_tmp_test';
        try {
            $policy->assertResourceUrl($access, 'https://evil.example/api/v1/access/access_tmp_test/gsc');
            self::fail('External host was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $policy->assertResourceUrl($access, 'http://169.254.169.254/latest/meta-data');
            self::fail('Metadata host was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $policy->mintUrl('http://169.254.169.254');
    }

    public function test_authenticated_site_turn_success(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([
            ['id' => 7, 'domain' => 'example.test', 'user_id' => 1],
        ]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'What is the traffic situation?',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $answers->calls);

        $data = $response->getData(true)['data'];
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('blocks', $data);
        self::assertArrayHasKey('actions', $data);
        self::assertArrayHasKey('sources', $data);
        self::assertNotEmpty($data['blocks']);
    }

    public function test_invalid_or_inaccessible_site_rejected(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([
            ['id' => 7, 'domain' => 'example.test', 'user_id' => 2],
        ]);
        $controller = new AgentRuntimeController();

        // User 1 trying to access site 7 belonging to user 2
        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'What is the traffic situation?',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $answers->calls);

        // Nonexistent site 999
        $request2 = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 999],
            'message' => 'What is the traffic situation?',
        ], userId: 1);

        $response2 = $controller->turn($request2, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(403, $response2->getStatusCode());
        self::assertSame(0, $answers->calls);
    }

    public function test_unauthenticated_turn_rejected(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'What is the traffic situation?',
        ], userId: null);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $answers->calls);
    }

    public function test_global_scope_remains_unsupported_in_controller(): void
    {
        $transport = new RecordingTransport();
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"all","needs":{"gsc":1},"parameters":{}}'),
            $answers,
            $transport,
        );
        $sites = new InMemorySiteDirectory();
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'global'],
            'message' => 'Compare all sites',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $answers->calls);
        self::assertSame([], $transport->calls);

        $data = $response->getData(true)['data'];
        self::assertStringContainsString('All Sites is selected', $data['message']);
        self::assertSame('warning', $data['blocks'][1]['type']);
        self::assertSame('global_access_unsupported', $data['blocks'][1]['text']);
    }

    public function test_turn_response_serializes_canonical_agent_response_only(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Summarize status',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        $data = $response->getData(true)['data'];

        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['actions', 'assistant_message_id', 'blocks', 'message', 'run_ulid', 'sources', 'thread_ulid', 'user_message_id'], $keys);

        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('svc_live_secret_value', $raw);
        self::assertStringNotContainsString('Bearer ', $raw);
        self::assertArrayNotHasKey('copy', $data);
        self::assertArrayNotHasKey('answer_model_called', $data);
    }

    public function test_gsc_controller_turn_requires_confirmation_before_access(): void
    {
        $transport = new RecordingTransport();
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"traffic","primary_capability":"gsc.performance","capabilities":["gsc.performance"],"parameters":{"period":"2026-09"},"requires_user_confirmation":false,"response_template":"report","response_language":"en"}'),
            $answers,
            $transport,
        );
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Thang 9 traffic co van de gi?',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'];
        self::assertSame('awaiting_confirmation', $data['status']);
        self::assertSame(['gsc.performance'], $data['confirmation']['tool_capabilities']);
        self::assertSame([], $transport->calls);
        self::assertSame(0, $answers->calls);
    }

    public function test_model_input_copy_endpoint_shares_prepared_input_without_model_completion(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"keyword landscape","primary_capability":"keywords.landscape","capabilities":["keywords.landscape"],"parameters":{},"requires_parameter_extraction":false,"requires_user_confirmation":true,"response_template":"report","response_language":"en"}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Keyword landscape thế nào?',
        ], userId: 1);

        $copyResponse = $controller->modelInput($request, $coordinator, $sites);
        self::assertSame(200, $copyResponse->getStatusCode());
        self::assertSame(0, $answers->calls);

        $copyData = $copyResponse->getData(true)['data'];
        self::assertArrayHasKey('copy', $copyData);
        self::assertArrayHasKey('answer', $copyData['copy']);
        self::assertNotEmpty($copyData['copy']['answer']);

        $turnResponse = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $turnResponse->getStatusCode());
        self::assertSame(1, $answers->calls);

        self::assertSame($copyData['copy']['answer'], $answers->lastExport);
    }

    public function test_eloquent_site_directory_handles_empty_or_missing_table_gracefully(): void
    {
        $directory = new EloquentSiteDirectory();
        self::assertIsArray($directory->listActiveSites());
        self::assertIsArray($directory->listActiveSites(1));
        self::assertFalse($directory->isSiteVisible(0));
        self::assertFalse($directory->isSiteVisible(-1, 1));
    }

    public function test_eloquent_site_directory_is_fail_closed_on_invalid_user_and_site(): void
    {
        $directory = new EloquentSiteDirectory();
        self::assertFalse($directory->isSiteVisible(0, 1));
        self::assertFalse($directory->isSiteVisible(-5, 1));
        self::assertFalse($directory->isSiteVisible(1, 0));
        self::assertFalse($directory->isSiteVisible(1, -1));
        self::assertSame([], $directory->listActiveSites(0));
        self::assertSame([], $directory->listActiveSites(-1));
    }

    public function test_agent_host_context_normalizes_and_roundtrips(): void
    {
        $ctx = AgentHostContext::fromArray([
            'appKey' => 'seo-ops',
            'scope' => ['type' => 'site', 'ref' => 'site:3'],
            'capabilities' => ['turn', 'model-input'],
        ]);

        self::assertSame('seo-ops', $ctx->appKey);
        self::assertTrue($ctx->scope->isSite());
        self::assertSame(3, $ctx->scope->siteId);
        self::assertSame('site:3', $ctx->scope->siteRef);
        self::assertSame(['turn', 'model-input'], $ctx->capabilities);

        $array = $ctx->toArray();
        self::assertSame('seo-ops', $array['appKey']);
        self::assertSame('site', $array['scope']['type']);
        self::assertSame('site:3', $array['scope']['ref']);
    }

    public function test_agent_host_context_defaults_to_standalone(): void
    {
        $ctx = AgentHostContext::fromArray([]);
        self::assertSame('standalone', $ctx->appKey);
        self::assertTrue($ctx->scope->isGlobal());
        self::assertSame(['turn', 'model-input'], $ctx->capabilities);
    }

    public function test_agent_project_scope_parses_generic_ref_when_site_id_omitted(): void
    {
        $scope = AgentProjectScope::fromArray(['type' => 'site', 'ref' => 'site:42']);
        self::assertTrue($scope->isSite());
        self::assertSame(42, $scope->siteId);
        self::assertSame('site:42', $scope->siteRef);

        $serialized = $scope->toArray();
        self::assertSame('site', $serialized['type']);
        self::assertSame('site:42', $serialized['site_ref']);
        self::assertSame(42, $serialized['site_id']);
    }

    public function test_controller_accepts_host_context_payload(): void
    {
        $controller = new AgentRuntimeController();
        $decisions = new ScriptedDecisionGateway('{"intent":"traffic","needs":{"site":0.9}}');
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator($decisions, $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);

        $request = $this->createTurnRequest([
            'hostContext' => [
                'appKey' => 'seo-ops',
                'scope' => ['type' => 'site', 'ref' => 'site:7'],
            ],
            'message' => 'Test message',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_agent_runtime_page_should_register_navigation_respects_config(): void
    {
        $container = new \Illuminate\Container\Container();
        $config = new \Illuminate\Config\Repository([
            'agent-runtime' => ['standalone_harness_navigation' => false],
        ]);
        $container->instance('config', $config);
        \Illuminate\Container\Container::setInstance($container);

        // Default: false (not registered in sidebar navigation)
        self::assertFalse(AgentRuntimePage::shouldRegisterNavigation());

        $config->set('agent-runtime.standalone_harness_navigation', true);
        self::assertTrue(AgentRuntimePage::shouldRegisterNavigation());
    }

    public function test_standalone_harness_can_access_blocks_normal_users_unless_permitted(): void
    {
        $container = new \Illuminate\Container\Container();
        $config = new \Illuminate\Config\Repository([
            'agent-runtime' => ['standalone_harness_enabled' => false],
        ]);
        $container->instance('config', $config);

        $staffUser = new class extends \App\Models\User {
            public function __construct() { $this->role = 'staff'; }
            public function canAccessSeoPanel(): bool { return true; }
            public function hasRole($r, $guard = null): bool { return $r === 'staff'; }
        };
        $adminUser = new class extends \App\Models\User {
            public function __construct() { $this->role = 'admin'; }
            public function canAccessSeoPanel(): bool { return true; }
            public function hasRole($r, $guard = null): bool { return $r === 'admin'; }
        };
        $ownerUser = new class extends \App\Models\User {
            public function __construct() { $this->role = 'owner'; }
            public function canAccessSeoPanel(): bool { return true; }
            public function hasRole($r, $guard = null): bool { return $r === 'owner'; }
        };

        $guard = new class($staffUser) implements \Illuminate\Contracts\Auth\Guard {
            public function __construct(public $user) {}
            public function check(): bool { return true; }
            public function guest(): bool { return false; }
            public function user() { return $this->user; }
            public function id(): int { return 1; }
            public function validate(array $credentials = []): bool { return true; }
            public function hasUser(): bool { return true; }
            public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user): void { $this->user = $user; }
        };
        $authMock = new class($guard) implements \Illuminate\Contracts\Auth\Factory {
            public function __construct(public $guard) {}
            public function guard($name = null) { return $this->guard; }
            public function shouldUse($name): void {}
            public function user() { return $this->guard->user(); }
            public function check(): bool { return true; }
        };
        $container->instance('auth', $authMock);
        $container->instance(\Illuminate\Contracts\Auth\Factory::class, $authMock);
        \Illuminate\Container\Container::setInstance($container);

        // 1. Staff user when harness disabled -> blocked
        self::assertFalse(AgentRuntimePage::canAccess());

        // 2. Staff user when harness explicitly enabled -> permitted
        $config->set('agent-runtime.standalone_harness_enabled', true);
        self::assertTrue(AgentRuntimePage::canAccess());

        // 3. Admin user when harness disabled -> permitted
        $config->set('agent-runtime.standalone_harness_enabled', false);
        $guard->user = $adminUser;
        self::assertTrue(AgentRuntimePage::canAccess());

        // 4. Owner user when harness disabled -> permitted
        $guard->user = $ownerUser;
        self::assertTrue(AgentRuntimePage::canAccess());
    }

    public function test_service_provider_registers_global_header_and_drawer_hooks(): void
    {
        $spSource = file_get_contents(dirname(__DIR__, 2).'/src/AgentRuntimeServiceProvider.php');
        self::assertStringContainsString('PanelsRenderHook::USER_MENU_BEFORE', $spSource);
        self::assertStringContainsString('PanelsRenderHook::BODY_END', $spSource);
        self::assertStringContainsString('agent-launcher', $spSource);
        self::assertStringContainsString('agent-drawer', $spSource);
    }

    public function test_answer_prompt_requires_existing_and_clearly_labeled_new_opportunities_without_fabricated_metrics(): void
    {
        $prompt = \Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('response');
        $routing = \Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultAgentRuntimePromptInstaller::canonicalDefaultMarkdown('routing');
        self::assertStringContainsString('retrieved SEO/site data as evidence', $prompt);
        self::assertStringContainsString('existing data opportunities', $prompt);
        self::assertStringContainsString('new topic or content opportunities', $prompt);
        self::assertStringContainsString('not confirmed as an existing Topic or Keyword', $prompt);
        self::assertStringContainsString('never fabricate search volume', $prompt);
        self::assertStringContainsString('what the evidence says, why it matters, and what to do next', $prompt);
        self::assertStringContainsString('exactly one valid JSON object', $prompt);
        self::assertStringContainsString('Do not wrap it in a Markdown code fence', $prompt);
        self::assertStringContainsString('Markdown is the safe default block type', $prompt);
        self::assertStringContainsString('inferred new topics in markdown list/text content', $prompt);
        self::assertStringContainsString('NEW SUGGESTED IDEAS', $prompt);
        self::assertStringContainsString('Tables and charts are evidence presentation tools, not reasoning or ideation tools', $prompt);
        self::assertStringContainsString('every numeric value is copied directly', $prompt);
        self::assertStringContainsString('If evidence support is uncertain, use markdown instead', $prompt);
        self::assertStringContainsString('priority scores, confidence percentages, estimated demand', $prompt);
        self::assertStringContainsString('never fabricate search volume, Topic IDs, article or DNA counts, keyword counts, scores', $prompt);
        self::assertStringContainsString('blocks and actions must be JSON arrays', $prompt);
        self::assertStringContainsString('String values must follow JSON escaping rules', $prompt);
        self::assertStringContainsString('Do not backslash-escape Markdown punctuation', $prompt);
        self::assertStringContainsString('*(note)*, not \*(note)\*', $prompt);
        self::assertStringNotContainsString('new topic or content opportunities', $routing);
        self::assertStringNotContainsString('inferred new topics', $routing);
        self::assertStringNotContainsString('Markdown is the safe default block type', $routing);
    }

    public function test_answer_rejection_diagnostics_are_opt_in_redacted_and_preserve_parser_reason(): void
    {
        $raw = '{"blocks":[{"type":"table","columns":[{"key":"count","label":"Count"}],"rows":[{"count":999999}]}],"echo":"svc_live_fake123"}';
        $answers = new RecordingAnswerGateway($raw);
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'),
            $answers,
        );

        $without = $coordinator->send(1, AgentProjectScope::site(7), 'Plan content', [], false);
        self::assertStringContainsString('could not be verified', $without->response->message);
        self::assertNull($without->answerDiagnostics);

        $with = $coordinator->send(1, AgentProjectScope::site(7), 'Plan content', [], true);
        self::assertStringContainsString('could not be verified', $with->response->message);
        self::assertSame('rejected', $with->answerDiagnostics['status']);
        self::assertSame('Agent response is missing message.', $with->answerDiagnostics['parser_error']);
        self::assertStringContainsString('[redacted-service-credential]', $with->answerDiagnostics['raw_completion']);
        self::assertStringNotContainsString('svc_live_fake123', $with->answerDiagnostics['raw_completion']);
    }

    public function test_out_of_evidence_table_value_is_reported_without_weakening_parser(): void
    {
        $raw = '{"message":"Plan","blocks":[{"type":"table","columns":[{"key":"count","label":"Count"}],"rows":[{"count":999999}]}],"actions":[]}';
        $result = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'),
            new RecordingAnswerGateway($raw),
        )->send(1, AgentProjectScope::site(7), 'Plan content', [], true);

        self::assertSame('Table value is not present in retrieval evidence.', $result->answerDiagnostics['parser_error']);
        self::assertSame($raw, $result->answerDiagnostics['raw_completion']);
    }

    public function test_controller_persists_and_returns_rejected_answer_diagnostics_only_when_enabled(): void
    {
        $raw = 'not valid AgentResponse JSON';
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'),
            new RecordingAnswerGateway($raw),
        );
        $controller = new AgentRuntimeController();
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);

        $enabled = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Plan content',
            'diagnostics' => true,
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];
        self::assertSame('Agent response is not JSON.', $enabled['answer_diagnostics']['parser_error']);
        $enabledRun = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $enabled['run_ulid'])->firstOrFail();
        self::assertSame($raw, $enabledRun->retrieval_summary['answer_diagnostics']['raw_completion']);

        $disabled = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Plan content again',
            'diagnostics' => false,
        ]), $coordinator, $sites, $threads, $persistence)->getData(true)['data'];
        self::assertArrayNotHasKey('answer_diagnostics', $disabled);
        $disabledRun = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $disabled['run_ulid'])->firstOrFail();
        self::assertArrayNotHasKey('answer_diagnostics', (array) $disabledRun->retrieval_summary);
    }

    public function test_debug_mode_pauses_and_resumes_the_same_production_run_without_live_model_calls_or_retrieval_replay(): void
    {
        $answers = new RecordingAnswerGateway();
        $decisions = new RecordingDecisionGateway('{"intent":"unused","needs":{}}');
        $transport = new RecordingTransport('no_gsc_property');
        $coordinator = $this->coordinator($decisions, $answers, $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $this->app->instance(\Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver::class, $resolver);
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $controller = new AgentRuntimeController();

        $start = $controller->turn($this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'debug_mode' => true,
        ]), $coordinator, $sites, $threads, $persistence);
        $startData = $start->getData(true)['data'];
        self::assertSame('paused', $startData['status']);
        self::assertSame('decision', $startData['model_call']['key']);
        self::assertSame(0, $decisions->calls);
        self::assertSame(0, $answers->calls);

        $run = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $startData['run_ulid'])->firstOrFail();
        self::assertSame('awaiting_model', $run->status);
        self::assertSame(1, $run->thread->messages()->where('role', 'user')->count());

        $decision = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $run->ulid,
            'manual_result' => '{"is_in_scope":true,"intent":"keyword landscape","primary_capability":"keywords.landscape","capabilities":["keywords.landscape"],"parameters":{},"response_template":"report","response_language":"en"}',
        ]), $coordinator, $threads, $persistence, $resolver);
        $decisionData = $decision->getData(true)['data'];
        self::assertSame('paused', $decisionData['status']);
        self::assertSame($run->ulid, $decisionData['run_ulid']);
        self::assertSame('answer', $decisionData['model_call']['key']);
        self::assertNotEmpty($decisionData['model_call']['full_prompt']);
        self::assertStringContainsString('"selected_response_template":"report"', $decisionData['model_call']['full_prompt']);
        $retrievalCalls = count($transport->calls);
        self::assertGreaterThan(0, $retrievalCalls);

        $longWarning = trim(str_repeat('Google Search Console is unavailable for this property. ', 8));
        $answer = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $run->ulid,
            'manual_result' => json_encode([
                'message' => $longWarning,
                'blocks' => [['type' => 'warning', 'text' => $longWarning]],
                'actions' => [],
            ], JSON_THROW_ON_ERROR),
        ]), $coordinator, $threads, $persistence, $resolver);
        self::assertSame($longWarning, $answer->getData(true)['data']['message']);
        self::assertSame($longWarning, $answer->getData(true)['data']['blocks'][0]['text']);
        self::assertSame($retrievalCalls, count($transport->calls));
        self::assertSame(0, $decisions->calls);
        self::assertSame(0, $answers->calls);

        $run->refresh();
        self::assertSame('done', $run->status);
        self::assertSame('no_gsc_property', $run->failure_code);
        self::assertNotSame($longWarning, $run->failure_code);
        self::assertNotNull($run->assistant_message_id);
        self::assertSame(1, $run->thread->messages()->where('role', 'assistant')->count());
        self::assertSame($longWarning, $run->assistantMessage->response_payload['blocks'][0]['text']);
    }

    public function test_normal_send_persists_canonical_retrieval_reason_instead_of_long_warning_text(): void
    {
        $longWarning = trim(str_repeat('Google Search Console is unavailable for this property. ', 8));
        $answers = new RecordingAnswerGateway(json_encode([
            'message' => $longWarning,
            'blocks' => [['type' => 'warning', 'text' => $longWarning]],
            'actions' => [],
        ], JSON_THROW_ON_ERROR));
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"is_in_scope":true,"intent":"keywords","primary_capability":"keywords.landscape","capabilities":["keywords.landscape"],"parameters":{},"response_template":"report","response_language":"en"}'),
            $answers,
            new RecordingTransport('no_gsc_property'),
        );
        $controller = new AgentRuntimeController();
        $controller->turn(
            $this->createTurnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Check traffic']),
            $coordinator,
            new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]),
            app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class),
            app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class),
        );

        $run = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::latest('id')->firstOrFail();
        self::assertSame('done', $run->status);
        self::assertSame('no_gsc_property', $run->failure_code);
        self::assertSame($longWarning, $run->assistantMessage->response_payload['blocks'][0]['text']);
    }

    public function test_rerun_reuses_user_message_and_preserves_versioned_assistant_results(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();
        $firstAnswers = new RecordingAnswerGateway('{"message":"Version one","blocks":[{"type":"markdown","text":"Version one"}],"actions":[]}');
        $first = $controller->turn(
            $this->createTurnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'What should I write?']),
            $this->coordinator(new RecordingDecisionGateway('{"intent":"content","needs":{"site":0.2}}'), $firstAnswers),
            $sites,
            $threads,
            $persistence,
        )->getData(true)['data'];

        $thread = $threads->findForPrincipal($first['thread_ulid'], 'user', '1');
        $userMessage = $thread->messages()->where('role', 'user')->firstOrFail();
        $firstAssistant = $thread->messages()->where('role', 'assistant')->firstOrFail();
        $rerunDecisions = new RecordingDecisionGateway('{"intent":"content","needs":{"site":0.2}}');
        $rerunAnswers = new RecordingAnswerGateway('{"message":"Version two","blocks":[{"type":"markdown","text":"Version two"}],"actions":[]}');

        $rerun = $controller->rerun(
            $this->createTurnRequest(['debug_mode' => false]),
            $thread->ulid,
            $userMessage->id,
            $this->coordinator($rerunDecisions, $rerunAnswers),
            $sites,
            $threads,
            $persistence,
            new MockAssumedModelResolver(),
        )->getData(true)['data'];

        self::assertSame('Version two', $rerun['message']);
        self::assertSame(1, $thread->messages()->where('role', 'user')->count());
        self::assertSame(2, $thread->runs()->where('user_message_id', $userMessage->id)->count());
        self::assertSame(2, $thread->messages()->where('role', 'assistant')->count());
        self::assertSame('Version one', $firstAssistant->fresh()->content);
        self::assertSame(1, $rerunDecisions->calls);
        self::assertSame(1, $rerunAnswers->calls);
        self::assertStringNotContainsString('Version one', $rerunAnswers->lastExport);

        $forbidden = $controller->rerun(
            $this->createTurnRequest([], userId: 2),
            $thread->ulid,
            $userMessage->id,
            $this->coordinator(new RecordingDecisionGateway(), new RecordingAnswerGateway()),
            $sites,
            $threads,
            $persistence,
            new MockAssumedModelResolver(),
        );
        self::assertSame(404, $forbidden->getStatusCode());

        $reload = $controller->showThread($this->createTurnRequest([]), $thread->ulid, $threads)->getData(true)['data'];
        $versions = array_values(array_filter($reload['messages'], static fn (array $message): bool => $message['role'] === 'assistant'));
        self::assertCount(2, $versions);
        self::assertSame($userMessage->id, $versions[0]['run']['user_message_id']);
        self::assertSame($userMessage->id, $versions[1]['run']['user_message_id']);
    }

    public function test_debug_rerun_pauses_and_resumes_same_new_run_without_live_model_calls(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();
        $initial = $controller->turn(
            $this->createTurnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Rerun me']),
            $this->coordinator(new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'), new RecordingAnswerGateway()),
            $sites,
            $threads,
            $persistence,
        )->getData(true)['data'];
        $thread = $threads->findForPrincipal($initial['thread_ulid'], 'user', '1');
        $userMessage = $thread->messages()->where('role', 'user')->firstOrFail();
        $decisions = new RecordingDecisionGateway();
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator($decisions, $answers);
        $resolver = new MockAssumedModelResolver();

        $paused = $controller->rerun(
            $this->createTurnRequest(['debug_mode' => true]),
            $thread->ulid,
            $userMessage->id,
            $coordinator,
            $sites,
            $threads,
            $persistence,
            $resolver,
        )->getData(true)['data'];
        self::assertSame('decision', $paused['model_call']['key']);
        $runUlid = $paused['run_ulid'];

        $answerPause = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $runUlid,
            'manual_result' => '{"intent":"site","needs":{"site":0.2}}',
        ]), $coordinator, $threads, $persistence, $resolver)->getData(true)['data'];
        self::assertSame('answer', $answerPause['model_call']['key']);

        $completed = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $runUlid,
            'manual_result' => '{"message":"Debug version","blocks":[],"actions":[]}',
        ]), $coordinator, $threads, $persistence, $resolver)->getData(true)['data'];
        self::assertSame('Debug version', $completed['message']);
        self::assertSame(0, $decisions->calls);
        self::assertSame(0, $answers->calls);
        self::assertSame(1, $thread->messages()->where('role', 'user')->count());
        self::assertSame(2, $thread->messages()->where('role', 'assistant')->count());
        self::assertSame('done', \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $runUlid)->value('status'));
    }

    public function test_existing_normal_send_flow_remains_unchanged(): void
    {
        $answers = new RecordingAnswerGateway();
        $decisions = new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}');
        $coordinator = $this->coordinator($decisions, $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Normal turn send',
        ], userId: 1);

        $response = $controller->turn($request, $coordinator, $sites, $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class), $this->createMock(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $answers->calls);
        $data = $response->getData(true)['data'];
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('blocks', $data);
    }

    public function test_existing_model_input_endpoint_remains_compatible(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2},"parameters":{}}'), $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Copy test',
        ], userId: 1);

        $response = $controller->modelInput($request, $coordinator, $sites);
        self::assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        self::assertArrayHasKey('copy', $data);
        self::assertArrayHasKey('answer', $data['copy']);
        self::assertArrayHasKey('routing', $data['copy']);
        self::assertSame(0, $answers->calls);
    }

    public function test_article_ref_reaches_article_retrieval(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"is_in_scope":true,"intent":"inspect article","primary_module":"articles","modules":["articles"],"parameters":{"article_ref":"article:41"},"response_template":"schema"}');
        $planner = new RetrievalPlanner();
        $plan = $planner->plan($decision, AgentProjectScope::site(7));

        self::assertCount(1, $plan->steps);
        self::assertSame('article:41', $plan->steps[0]->articleRef);

        $transport = new RecordingTransport();
        $executor = new SeoAccessExecutor(
            $transport,
            new class implements SeoAccessCredential { public function bearer(): ?string { return 'svc_live_x'; } },
            new SeoAccessUrlPolicy(),
            'https://app.example.test',
        );
        $executor->execute($plan);

        $urls = array_column(array_filter($transport->calls, static fn (array $c): bool => $c['method'] === 'GET'), 'url');
        self::assertNotEmpty($urls);
        self::assertStringContainsString('/articles/article:41', $urls[0]);
    }

    public function test_article_ref_cannot_read_article_from_another_site(): void
    {
        $composer = app(SeoAccessBusinessModulesComposer::class);
        $otherSiteArticle = \Omnichannel\Addons\Content\Models\SeoArticle::query()->create([
            'site_id' => 999,
            'title' => 'Other site article',
            'slug' => 'other-site-article-'.uniqid(),
            'status' => 'publish',
        ]);

        $detail = $composer->articleDetail(7, 'article:'.$otherSiteArticle->id);
        self::assertNull($detail);

        $sameSiteDetail = $composer->articleDetail(999, 'article:'.$otherSiteArticle->id);
        self::assertNotNull($sameSiteDetail);
        self::assertSame('article:'.$otherSiteArticle->id, $sameSiteDetail['article']['article_ref']);
    }

    public function test_task_improve_affects_article_read_projection(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"is_in_scope":true,"intent":"improve articles","primary_module":"articles","modules":["articles"],"parameters":{"task":"improve","limit_max":20},"response_template":"table"}');
        $planner = new RetrievalPlanner();
        $plan = $planner->plan($decision, AgentProjectScope::site(7));

        self::assertSame('improve', $plan->steps[0]->query['task'] ?? null);
        self::assertSame('20', $plan->steps[0]->query['limit'] ?? null);

        $transport = new RecordingTransport();
        $executor = new SeoAccessExecutor(
            $transport,
            new class implements SeoAccessCredential { public function bearer(): ?string { return 'svc_live_x'; } },
            new SeoAccessUrlPolicy(),
            'https://app.example.test',
        );
        $executor->execute($plan);

        $urls = array_column(array_filter($transport->calls, static fn (array $c): bool => $c['method'] === 'GET'), 'url');
        self::assertStringContainsString('/articles?task=improve&limit=20', $urls[0]);
    }

    public function test_improve_candidate_retrieval_reuses_canonical_seo_audit_logic(): void
    {
        $mockAudit = $this->createMock(\Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService::class);
        $mockAudit->expects(self::once())
            ->method('listArticles')
            ->willReturn([
                'items' => [
                    [
                        'article_ref' => 'article:55',
                        'title' => 'Weak Article 55',
                        'slug' => 'weak-article-55',
                        'status' => 'publish',
                        'score' => 45.0,
                        'seo_score' => 45.0,
                        'focus_keyword' => 'may dong phuc',
                        'reason_labels' => ['Thiếu meta description', 'Điểm SEO thấp'],
                        'has_keyword_flags' => true,
                        'updated_at' => '2026-09-01T00:00:00+00:00',
                    ],
                ],
                'total' => 1,
                'post_type' => null,
            ]);

        $composer = new SeoAccessBusinessModulesComposer(
            app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class),
            $mockAudit,
        );

        $result = $composer->articles(siteId: 7, limit: 15, task: 'improve');
        self::assertSame('seo.access.articles.v1', $result['schema']);
        self::assertSame('improve', $result['task']);
        self::assertCount(1, $result['articles']);
        self::assertSame('article:55', $result['articles'][0]['article_ref']);
        self::assertSame(['Thiếu meta description', 'Điểm SEO thấp'], $result['articles'][0]['reason_labels']);
    }

    public function test_explicit_output_limit_does_not_blindly_truncate_candidate_discovery(): void
    {
        $mockAudit = $this->createMock(\Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService::class);
        $mockAudit->expects(self::atLeastOnce())
            ->method('listArticles')
            ->with(
                self::anything(),
                self::callback(function (array $input): bool {
                    return isset($input['limit']) && $input['limit'] >= 50;
                }),
            )
            ->willReturn(['items' => [
                ['article_ref' => 'article:1', 'title' => 'T1', 'slug' => 's1', 'status' => 'publish'],
            ], 'total' => 1, 'post_type' => null]);

        $composer = new SeoAccessBusinessModulesComposer(
            app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class),
            $mockAudit,
        );

        $composer->articles(siteId: 7, limit: 15, task: 'improve');
    }

    public function test_answer_input_contains_concrete_candidate_article_refs_titles_and_issues(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('articles', 'ok', 'GET /articles?task=improve&limit=30', [
                'schema' => 'seo.access.articles.v1',
                'task' => 'improve',
                'articles' => [
                    [
                        'article_ref' => 'article:101',
                        'title' => 'Hướng dẫn đặt may áo thun',
                        'status' => 'publish',
                        'focus_keyword' => 'may ao thun',
                        'seo_score' => 42.0,
                        'reason_labels' => ['Thiếu thẻ meta description', 'Mật độ từ khóa thấp'],
                    ],
                ],
            ]),
        ]);

        $input = (new AgentModelInputBuilder())->buildAnswerInput(
            AgentProjectScope::site(7),
            'Gợi ý các bài cần sửa',
            [],
            $bundle,
            'table',
        );
        $export = $input->exportText();
        self::assertStringContainsString('article:101', $export);
        self::assertStringContainsString('Hướng dẫn đặt may áo thun', $export);
        self::assertStringContainsString('Thiếu thẻ meta description', $export);
    }

    public function test_generic_article_inventory_questions_still_work(): void
    {
        $composer = app(SeoAccessBusinessModulesComposer::class);
        $result = $composer->articles(siteId: 7, limit: 10, task: null);
        self::assertSame('seo.access.articles.v1', $result['schema']);
        self::assertSame('site:7', $result['site_ref']);
        self::assertArrayNotHasKey('task', $result);
    }

    public function test_domain_neutral_content_project_with_item_on_current_site_is_included(): void
    {
        $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
            'site_id' => null,
            'name' => 'Domain Neutral Project with Site 7 Task',
            'month' => '2026-09-01',
            'status' => 'draft',
        ]);
        \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => 7,
            'task_type' => 'new_content',
            'status' => 'pending',
        ]);

        $readService = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class);
        $context = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext(
            actorRef: 'seo-access', actorType: 'agent', tenantRef: 'seo-access',
            siteRef: 'site:7', requestRef: 'test-req-included', resolvedSiteId: 7,
            scopes: ['content-projects:read'],
        );

        $projects = $readService->listProjects($context)['projects'];
        $projectIds = array_column($projects, 'project_id');
        self::assertContains($project->id, $projectIds);
    }

    public function test_domain_neutral_content_project_containing_only_another_site_items_is_excluded(): void
    {
        $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
            'site_id' => null,
            'name' => 'Domain Neutral Project for Site 999 Only',
            'month' => '2026-09-01',
            'status' => 'draft',
        ]);
        \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => 999,
            'task_type' => 'new_content',
            'status' => 'pending',
        ]);

        $readService = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class);
        $context = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext(
            actorRef: 'seo-access', actorType: 'agent', tenantRef: 'seo-access',
            siteRef: 'site:7', requestRef: 'test-req-excluded', resolvedSiteId: 7,
            scopes: ['content-projects:read'],
        );

        $projects = $readService->listProjects($context)['projects'];
        $projectIds = array_column($projects, 'project_id');
        self::assertNotContains($project->id, $projectIds);

        $this->expectException(\RuntimeException::class);
        $readService->getProject($context, ['project_ref' => \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::project((int) $project->id)]);
    }

    public function test_non_agent_shared_content_project_semantics_remain_broad(): void
    {
        $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
            'site_id' => null,
            'name' => 'Domain Neutral Project Shared Multi-site',
            'month' => '2026-09-01',
            'status' => 'draft',
        ]);
        $taskSite7 = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => 7,
            'task_type' => 'new_content',
            'status' => 'pending',
        ]);
        $taskSite999 = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => 999,
            'task_type' => 'new_content',
            'status' => 'pending',
        ]);

        $actorContext = \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext::user(
            userId: 1,
            siteId: 7,
        );

        // General application read model returns all items without Agent site narrowing
        $readModel = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectReadModelService::class);
        $allItems = $readModel->items($project, $actorContext);
        $allItemRefs = array_map(static fn ($dto) => (string) $dto->itemRef, $allItems);
        $expectedSite7Ref = \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::item((int) $taskSite7->id);
        $expectedSite999Ref = \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::item((int) $taskSite999->id);
        self::assertContains($expectedSite7Ref, $allItemRefs);
        self::assertContains($expectedSite999Ref, $allItemRefs);

        // In contrast, Agent read service strictly filters domain-neutral project items to the working site
        $agentReadService = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class);
        $agentContext = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext(
            actorRef: 'seo-access', actorType: 'agent', tenantRef: 'seo-access',
            siteRef: 'site:7', requestRef: 'test-req-narrow', resolvedSiteId: 7,
            scopes: ['content-projects:read'],
        );
        $agentItems = $agentReadService->listItems($agentContext, ['project_ref' => \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::project((int) $project->id)])['items'];
        $agentItemRefs = array_column($agentItems, 'item_ref');
        self::assertContains($expectedSite7Ref, $agentItemRefs);
        self::assertNotContains($expectedSite999Ref, $agentItemRefs);
    }

    public function test_site_bound_content_project_behavior_remains_unchanged(): void
    {
        $projectSite7 = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
            'site_id' => 7,
            'name' => 'Site 7 Dedicated Project',
            'month' => '2026-09-01',
            'status' => 'draft',
        ]);
        $projectSite8 = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->create([
            'site_id' => 8,
            'name' => 'Site 8 Dedicated Project',
            'month' => '2026-09-01',
            'status' => 'draft',
        ]);

        $readService = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class);
        $context = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext(
            actorRef: 'seo-access', actorType: 'agent', tenantRef: 'seo-access',
            siteRef: 'site:7', requestRef: 'test-req-site-bound', resolvedSiteId: 7,
            scopes: ['content-projects:read'],
        );

        $res = $readService->listProjects($context);
        $projects = $res['projects'];
        $projectIds = array_column($projects, 'project_id');
        self::assertContains($projectSite7->id, $projectIds);
        self::assertNotContains($projectSite8->id, $projectIds);
    }

    public function test_gsc_unavailable_still_does_not_block_other_modules(): void
    {
        $transport = new class implements SeoAccessTransport {
            public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
            {
                if (strtoupper($method) === 'POST') {
                    return ['status' => 200, 'json' => ['data' => [
                        'access_url' => 'https://app.example.test/api/v1/access/access_tmp_test',
                        'expires_at' => '2026-09-27T00:00:00+00:00',
                        'site_ref' => 'site:7',
                    ]]];
                }
                if (str_contains($url, '/gsc')) {
                    return ['status' => 200, 'json' => ['data' => ['available' => false, 'reason' => 'no_gsc_property']]];
                }

                return ['status' => 200, 'json' => ['data' => ['status' => 'ok']]];
            }
        };

        $decision = (new RetrievalDecisionParser())->parse('{"is_in_scope":true,"intent":"check status","primary_module":"articles","modules":["articles","gsc"],"parameters":{},"response_template":"report"}');
        $plan = (new RetrievalPlanner())->plan($decision, AgentProjectScope::site(7));
        $bundle = (new SeoAccessExecutor(
            $transport,
            new class implements SeoAccessCredential { public function bearer(): ?string { return 'svc_live_x'; } },
            new SeoAccessUrlPolicy(),
            'https://app.example.test',
        ))->execute($plan);

        self::assertCount(2, $bundle->sources);
        self::assertSame('ok', $bundle->sources[0]->status);
        self::assertSame('unavailable', $bundle->sources[1]->status);
        self::assertSame('no_gsc_property', $bundle->sources[1]->reason);
    }

    public function test_no_global_agent_behavior_is_enabled(): void
    {
        $global = AgentProjectScope::global();
        $decision = (new RetrievalDecisionParser())->parse('{"is_in_scope":true,"intent":"test","primary_module":"site","modules":["site"],"parameters":{},"response_template":"text"}');
        $plan = (new RetrievalPlanner())->plan($decision, $global);

        self::assertTrue($plan->globalUnsupported);

        $bundle = (new SeoAccessExecutor(
            new RecordingTransport(),
            new class implements SeoAccessCredential { public function bearer(): ?string { return 'svc_live_x'; } },
            new SeoAccessUrlPolicy(),
            'https://app.example.test',
        ))->execute($plan);

        self::assertTrue($bundle->scope->isGlobal());
        self::assertSame(['global_access_unsupported'], $bundle->warnings);
    }

    public function test_debug_manual_answer_parser_rejection_keeps_run_retryable(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $coordinator = $this->coordinator(new RecordingDecisionGateway(), new RecordingAnswerGateway());
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $initial = $controller->turn(
            $this->createTurnRequest([
                'scope' => ['type' => 'site', 'siteId' => 7],
                'message' => 'Debug retry test',
                'debug_mode' => true,
            ]),
            $coordinator,
            $sites,
            $threads,
            $persistence,
            $resolver,
        )->getData(true)['data'];

        self::assertSame('paused', $initial['status']);
        self::assertSame('decision', $initial['model_call']['key']);
        $runUlid = $initial['run_ulid'];

        $decisionApply = $controller->modelDebugApply(
            $this->createTurnRequest([
                'run_ulid' => $runUlid,
                'manual_result' => '{"intent":"site","needs":{"site":0.2}}',
            ]),
            $coordinator,
            $threads,
            $persistence,
            $resolver,
        )->getData(true)['data'];

        self::assertSame('paused', $decisionApply['status']);
        self::assertSame('answer', $decisionApply['model_call']['key']);

        // First apply invalid answer -> 422 rejected, run preserved awaiting_model
        $rejectedRes = $controller->modelDebugApply(
            $this->createTurnRequest([
                'run_ulid' => $runUlid,
                'manual_result' => '{"not_valid_answer":true}',
            ]),
            $coordinator,
            $threads,
            $persistence,
            $resolver,
        );

        self::assertSame(422, $rejectedRes->getStatusCode());
        $rejectedData = $rejectedRes->getData(true);
        self::assertSame('paused', $rejectedData['data']['status']);
        self::assertSame('answer', $rejectedData['data']['model_call']['key']);
        self::assertSame($runUlid, $rejectedData['data']['run_ulid']);
        self::assertNotEmpty($rejectedData['validation_error']);

        $runModel = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $runUlid)->firstOrFail();
        self::assertSame('awaiting_model', $runModel->status);
        self::assertSame('answer', $runModel->retrieval_summary['model_call']);

        // Second apply with valid answer succeeds on the same run
        $validRes = $controller->modelDebugApply(
            $this->createTurnRequest([
                'run_ulid' => $runUlid,
                'manual_result' => '{"message":"Corrected answer","blocks":[],"actions":[]}',
            ]),
            $coordinator,
            $threads,
            $persistence,
            $resolver,
        );

        self::assertSame(200, $validRes->getStatusCode());
        $validData = $validRes->getData(true)['data'];
        self::assertSame('Corrected answer', $validData['message']);
        self::assertSame('done', $runModel->fresh()->status);
    }

    public function test_debug_rerun_manual_answer_parser_rejection_has_identical_retry_semantics(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();
        $initial = $controller->turn(
            $this->createTurnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Initial message']),
            $this->coordinator(new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'), new RecordingAnswerGateway()),
            $sites,
            $threads,
            $persistence,
        )->getData(true)['data'];
        $thread = $threads->findForPrincipal($initial['thread_ulid'], 'user', '1');
        $userMessage = $thread->messages()->where('role', 'user')->firstOrFail();
        $coordinator = $this->coordinator(new RecordingDecisionGateway(), new RecordingAnswerGateway());
        $resolver = new MockAssumedModelResolver();

        $rerunPaused = $controller->rerun(
            $this->createTurnRequest(['debug_mode' => true]),
            $thread->ulid,
            $userMessage->id,
            $coordinator,
            $sites,
            $threads,
            $persistence,
            $resolver,
        )->getData(true)['data'];
        $runUlid = $rerunPaused['run_ulid'];

        // Step 1: Decision
        $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $runUlid,
            'manual_result' => '{"intent":"site","needs":{"site":0.2}}',
        ]), $coordinator, $threads, $persistence, $resolver);

        // Step 2: Invalid Answer -> 422
        $rejectedRes = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $runUlid,
            'manual_result' => '{"invalid":1}',
        ]), $coordinator, $threads, $persistence, $resolver);
        self::assertSame(422, $rejectedRes->getStatusCode());
        self::assertSame('awaiting_model', \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $runUlid)->value('status'));

        // Step 3: Valid Answer -> 200
        $completedRes = $controller->modelDebugApply($this->createTurnRequest([
            'run_ulid' => $runUlid,
            'manual_result' => '{"message":"Rerun corrected","blocks":[],"actions":[]}',
        ]), $coordinator, $threads, $persistence, $resolver);
        self::assertSame(200, $completedRes->getStatusCode());
        self::assertSame('Rerun corrected', $completedRes->getData(true)['data']['message']);
        self::assertSame('done', \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $runUlid)->value('status'));
    }

    public function test_diag_captures_redacted_decision_diagnostics(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();
        $resolver = new MockAssumedModelResolver();

        $brokenDecision = new ScriptedDecisionGateway('api_key: sensitive_api_key_xyz123 invalid json decision');
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator($brokenDecision, $answers);

        $res = $controller->turn(
            $this->createTurnRequest([
                'scope' => ['type' => 'site', 'siteId' => 7],
                'message' => 'Diag test with broken decision',
                'diagnostics' => true,
            ]),
            $coordinator,
            $sites,
            $threads,
            $persistence,
            $resolver,
        );

        self::assertSame(200, $res->getStatusCode());
        $data = $res->getData(true)['data'];
        self::assertArrayHasKey('model_diagnostics', $data);
        $diag = $data['model_diagnostics'];
        self::assertNotNull($diag['decision'] ?? null);
        self::assertSame('rejected', $diag['decision']['status']);
        self::assertNotEmpty($diag['decision']['parser_error']);
        self::assertStringNotContainsString('sensitive_api_key_xyz123', $diag['decision']['raw_completion']);
        self::assertStringContainsString('api_key: [redacted]', $diag['decision']['raw_completion']);
    }

    public function test_diag_captures_redacted_answer_diagnostics(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();
        $resolver = new MockAssumedModelResolver();

        $decisions = new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}');
        $brokenAnswer = new RecordingAnswerGateway('bearer: secret_pass_456 invalid answer payload');
        $coordinator = $this->coordinator($decisions, $brokenAnswer);

        $res = $controller->turn(
            $this->createTurnRequest([
                'scope' => ['type' => 'site', 'siteId' => 7],
                'message' => 'Diag test with broken answer',
                'diagnostics' => true,
            ]),
            $coordinator,
            $sites,
            $threads,
            $persistence,
            $resolver,
        );

        self::assertSame(200, $res->getStatusCode());
        $data = $res->getData(true)['data'];
        self::assertArrayHasKey('model_diagnostics', $data);
        $diag = $data['model_diagnostics'];
        self::assertNotNull($diag['answer'] ?? null);
        self::assertSame('rejected', $diag['answer']['status']);
        self::assertNotEmpty($diag['answer']['parser_error']);
        self::assertStringNotContainsString('secret_pass_456', $diag['answer']['raw_completion']);
        self::assertStringContainsString('bearer: [redacted]', $diag['answer']['raw_completion']);
    }

    public function test_model_diagnostics_merge_without_destroying_debug_checkpoint_fields(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);
        $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', 'Test');
        $userMsg = $persistence->persistUserMessage($thread, 'Test message');
        $run = $persistence->startRun($thread, $userMsg, 'seo-ops', 'site', 'site:7', 1);

        $persistence->pauseRun($run, 'decision', [
            'scope' => ['type' => 'site', 'site_id' => 7],
            'prompt' => 'Checkpoint prompt',
        ]);

        $runModel = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $run->ulid)->firstOrFail();
        self::assertSame('decision', $runModel->retrieval_summary['model_call']);
        self::assertSame('Checkpoint prompt', $runModel->retrieval_summary['runtime_state']['prompt']);

        $persistence->storeModelDiagnostics($run, [
            'decision' => ['status' => 'rejected', 'parser_error' => 'Syntax error'],
        ]);

        $reloaded = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::where('ulid', $run->ulid)->firstOrFail();
        self::assertSame('decision', $reloaded->retrieval_summary['model_call']);
        self::assertSame('Checkpoint prompt', $reloaded->retrieval_summary['runtime_state']['prompt']);
        self::assertSame(['type' => 'site', 'site_id' => 7], $reloaded->retrieval_summary['runtime_state']['scope']);
        self::assertSame('rejected', $reloaded->retrieval_summary['model_diagnostics']['decision']['status']);
    }

    public function test_user_message_persistence_trims_only_outer_whitespace(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);
        $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', 'Trim');

        $trimmed = $persistence->persistUserMessage($thread, "\n\n  Bạn có thể làm gì?  \r\n");
        $multiline = $persistence->persistUserMessage($thread, "Dòng 1\n\nDòng 2");

        self::assertSame('Bạn có thể làm gì?', $trimmed->content);
        self::assertSame("Dòng 1\n\nDòng 2", $multiline->content);
    }

    public function test_user_message_persistence_rejects_whitespace_only_content(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);
        $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', 'Empty');
        $before = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage::query()
            ->where('thread_id', $thread->id)->count();

        try {
            $persistence->persistUserMessage($thread, " \n\r\t ");
            self::fail('Expected whitespace-only user message to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('User message content must not be empty.', $exception->getMessage());
        }

        self::assertSame($before, \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage::query()
            ->where('thread_id', $thread->id)->count());
    }

    public function test_thread_archive_and_delete_lifecycle_and_principal_isolation(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{"intent":"site","needs":{"site":0.2}}'), new RecordingAnswerGateway());
        $controller = new AgentRuntimeController();

        $turn = $controller->turn(
            $this->createTurnRequest(['scope' => ['type' => 'site', 'siteId' => 7], 'message' => 'Hello for archive']),
            $coordinator,
            $sites,
            $threads,
            $persistence,
        )->getData(true)['data'];

        $threadUlid = $turn['thread_ulid'];
        $thread = $threads->findForPrincipal($threadUlid, 'user', '1');
        self::assertNotNull($thread);
        self::assertSame('active', $thread->status);

        // User 2 cannot archive User 1's thread
        $unauthArchive = $controller->archiveThread($this->createTurnRequest([], userId: 2), $threadUlid, $threads);
        self::assertSame(404, $unauthArchive->getStatusCode());

        // User 1 archives thread
        $archiveRes = $controller->archiveThread($this->createTurnRequest([], userId: 1), $threadUlid, $threads);
        self::assertSame(200, $archiveRes->getStatusCode());
        self::assertSame('archived', $thread->fresh()->status);
        self::assertGreaterThanOrEqual(1, $thread->messages()->count());
        self::assertGreaterThanOrEqual(1, $thread->runs()->count());

        // Archived listing returns this thread
        $listReq = Request::create('/agent-runtime/threads?status=archived&scope_ref=site:7', 'GET');
        $user1 = new class(1) { public function __construct(public int $id) {} };
        $listReq->setUserResolver(static fn () => $user1);
        $archivedList = $controller->listThreads($listReq, $threads)->getData(true)['data'];
        $ulids = array_column($archivedList, 'ulid');
        self::assertContains($threadUlid, $ulids);

        // Active listing does NOT return this thread
        $activeListReq = Request::create('/agent-runtime/threads?status=active&scope_ref=site:7', 'GET');
        $activeListReq->setUserResolver(static fn () => $user1);
        $activeList = $controller->listThreads($activeListReq, $threads)->getData(true)['data'];
        $activeUlids = array_column($activeList, 'ulid');
        self::assertNotContains($threadUlid, $activeUlids);

        // Write turn to archived thread returns 422
        $writeTurnReq = $this->createTurnRequest(['message' => 'Should fail on archived', 'scope' => ['type' => 'site', 'siteId' => 7]]);
        $writeRes = $controller->threadTurn($writeTurnReq, $threadUlid, $coordinator, $sites, $threads, $persistence);
        self::assertSame(422, $writeRes->getStatusCode());

        // User 2 cannot delete User 1's thread
        $unauthDelete = $controller->deleteThread($this->createTurnRequest([], userId: 2), $threadUlid, $threads);
        self::assertSame(404, $unauthDelete->getStatusCode());

        // User 1 deletes thread and database cascades remove its workflow.
        $deleteRes = $controller->deleteThread($this->createTurnRequest([], userId: 1), $threadUlid, $threads);
        self::assertSame(200, $deleteRes->getStatusCode());
        self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::find($thread->id));
        self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::withTrashed()->find($thread->id));
    }

    public function test_completed_active_thread_can_be_deleted_without_archive(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);
        $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', 'Direct delete');
        $message = $persistence->persistUserMessage($thread, 'Completed request');
        $run = $persistence->startRun($thread, $message, 'seo-ops', 'site', 'site:7', 1);
        $run->update(['status' => 'done', 'finished_at' => now()]);

        $response = (new AgentRuntimeController())->deleteThread(
            $this->createTurnRequest([], userId: 1),
            $thread->ulid,
            $threads,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('active', $thread->status);
        self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::find($thread->id));
        self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::withTrashed()->find($thread->id));
    }

    public function test_direct_delete_rejects_running_run_and_preserves_thread(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);

        $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', 'running');
        $message = $persistence->persistUserMessage($thread, 'Running request');
        $run = $persistence->startRun($thread, $message, 'seo-ops', 'site', 'site:7', 1);

        $response = (new AgentRuntimeController())->deleteThread(
            $this->createTurnRequest([], userId: 1),
            $thread->ulid,
            $threads,
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Cannot delete thread with an active run.', $response->getData(true)['message']);
        self::assertNotNull($thread->fresh());
        self::assertNotNull($run->fresh());
    }

    public function test_direct_delete_discards_paused_runs_by_thread_cascade(): void
    {
        $threads = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentThreadRepository::class);
        $persistence = app(\Omnichannel\Addons\AgentRuntime\Persistence\AgentTurnPersistence::class);
        $app = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::findByKey('seo-ops')
            ?? \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp::create(['app_key' => 'seo-ops', 'name' => 'SEO Ops', 'default_scope_type' => 'site']);

        foreach (['awaiting_model', 'awaiting_confirmation'] as $status) {
            $thread = $threads->createThread($app, 'user', '1', 1, 1, 'site', 'site:7', $status);
            $message = $persistence->persistUserMessage($thread, 'Pending request');
            $run = $persistence->startRun($thread, $message, 'seo-ops', 'site', 'site:7', 1);
            $run->update(['status' => $status]);

            $response = (new AgentRuntimeController())->deleteThread(
                $this->createTurnRequest([], userId: 1),
                $thread->ulid,
                $threads,
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::withTrashed()->find($thread->id));
            self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::find($run->id));
            self::assertNull(\Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage::find($message->id));
        }
    }

    public function test_history_ui_offers_archive_and_delete_for_active_threads(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/widget/AgentWidget.jsx');
        $activeStart = strpos($source, '{activeThreads.map((t) => (');
        $archivedStart = strpos($source, '{archivedThreads.map((t) => (');
        self::assertNotFalse($activeStart);
        self::assertNotFalse($archivedStart);
        $activeMarkup = substr($source, $activeStart, $archivedStart - $activeStart);
        $archivedMarkup = substr($source, $archivedStart);

        self::assertStringContainsString('onArchiveThread(t.ulid)', $activeMarkup);
        self::assertStringContainsString('onDeleteThread(t.ulid)', $activeMarkup);
        self::assertStringContainsString('<Archive size={13} />', $activeMarkup);
        self::assertStringContainsString('<Trash2 size={13} />', $activeMarkup);
        self::assertStringContainsString('onDeleteThread(t.ulid)', $archivedMarkup);
        self::assertStringContainsString("deleteConfirm: 'Xóa hội thoại này?'", $source);
        self::assertStringContainsString("deleteConfirm: 'Delete this conversation?'", $source);
        self::assertStringNotContainsString('Delete this archived conversation?', $source);
    }

    private function retired_global_scope_remains_unsupported_in_model_debug(): void
    {
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory();
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $startReq = $this->createTurnRequest([
            'scope' => ['type' => 'global'],
            'message' => 'Check all sites',
        ], userId: 1);

        $startRes = $controller->modelDebugStart($startReq, $coordinator, $sites, $resolver);
        self::assertSame(200, $startRes->getStatusCode());
        self::assertTrue($startRes->getData(true)['data']['global_unsupported']);

        $applyReq = $this->createTurnRequest([
            'scope' => ['type' => 'global'],
            'message' => 'Check all sites',
            'stage' => 'decision',
            'manual_result' => '{"intent":"all","needs":{"site":0.5}}',
        ], userId: 1);

        $applyRes = $controller->modelDebugApply($applyReq, $coordinator, $sites, $resolver);
        self::assertSame(200, $applyRes->getStatusCode());
        $applyData = $applyRes->getData(true)['data'];
        self::assertFalse($applyData['parse_success']);
        self::assertStringContainsString('All Sites is selected', $applyData['parser_error']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createTurnRequest(array $payload, ?int $userId = 1): Request
    {
        $request = Request::create('/agent-runtime/turns', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        if ($userId !== null) {
            $user = new class($userId) {
                public function __construct(public int $id) {}
            };
            $request->setUserResolver(static fn () => $user);
        } else {
            $request->setUserResolver(static fn () => null);
        }

        return $request;
    }

    /**
     * @param  SeoAccessTransport|null  $transport
     */
    private function coordinator(DecisionModelGateway $decisions, AnswerModelGateway $answers, ?SeoAccessTransport $transport = null): AgentTurnCoordinator
    {
        $transport ??= new RecordingTransport();
        $executor = $this->retrievalExecutor($transport);

        return new AgentTurnCoordinator(
            new AgentModelInputBuilder(),
            $decisions,
            new RetrievalDecisionParser(),
            $executor,
            $answers,
            new AgentResponseParser(),
        );
    }

    private function retrievalExecutor(SeoAccessTransport $transport): RetrievalExecutor
    {
        return new RetrievalExecutor(
            new RetrievalPlanner(),
            new SeoAccessExecutor(
                $transport,
                new class implements SeoAccessCredential {
                    public function bearer(): ?string
                    {
                        return 'svc_live_secret_value';
                    }
                },
                new SeoAccessUrlPolicy(),
                'https://app.example.test',
            ),
        );
    }

    private function gscSource(?string $latestPeriod): GscContextSource
    {
        return new GscContextSource(new class($latestPeriod) implements GscContextLoader {
            public function __construct(private readonly ?string $latestPeriod) {}

            public function forSite(int $siteId, string $periodKey): GscContext
            {
                throw new \LogicException('GSC context load is not expected in this test.');
            }

            public function sourceUpdatedAt(int $siteId): ?string
            {
                return null;
            }

            public function latestSyncedPeriodOnOrBefore(int $siteId, string $onOrBeforePeriod): ?string
            {
                return $this->latestPeriod;
            }

            public function latestSyncedPeriod(int $siteId): ?string
            {
                return $this->latestPeriod;
            }
        });
    }

    public function test_parser_leaves_normal_valid_json_unchanged(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), []);
        $raw = json_encode([
            'message' => 'Normal valid response',
            'blocks' => [
                ['type' => 'markdown', 'text' => "Paragraph 1\n\nParagraph 2 with *emphasis* and `code`"],
            ],
            'actions' => [],
        ], JSON_THROW_ON_ERROR);

        $response = $parser->parse($raw, $bundle);
        self::assertSame('Normal valid response', $response->message);
        self::assertSame("Paragraph 1\n\nParagraph 2 with *emphasis* and `code`", $response->blocks[0]['text']);
    }

    public function test_answer_model_cannot_create_confirmation_actions(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), []);
        $response = (new AgentResponseParser())->parse(json_encode([
            'message' => 'Attempted confirmation',
            'blocks' => [],
            'actions' => [
                ['type' => 'confirmation', 'action' => 'confirm', 'label' => 'Xác nhận', 'run_ulid' => 'forged'],
                ['type' => 'confirmation', 'action' => 'reject', 'label' => 'Từ chối', 'run_ulid' => 'forged'],
            ],
        ], JSON_THROW_ON_ERROR), $bundle);

        self::assertSame([], $response->actions);
    }

    public function test_parser_recovers_gemini_markdown_punctuation_escapes(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), []);

        // Raw Gemini response with invalid backslash escapes before markdown punctuation
        $raw = '{"message":"Recovery successful","blocks":[{"type":"markdown","text":"Summary:\n\n\*(Lưu ý...)\*\n\n\_italic\_ and \#heading and \[link text\] and \(parens\) and \~strikethrough\~ and \`inline_code\`."}],"actions":[]}';

        $response = $parser->parse($raw, $bundle);
        self::assertSame('Recovery successful', $response->message);
        self::assertSame("Summary:\n\n*(Lưu ý...)*\n\n_italic_ and #heading and [link text] and (parens) and ~strikethrough~ and `inline_code`.", $response->blocks[0]['text']);
    }

    public function test_parser_preserves_valid_json_escapes_including_newlines_quotes_and_backslashes(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), []);

        // Valid JSON with standard escapes (\n, \t, \", \\) plus an escaped backslash followed by a star (\\*)
        // And mixed with a Gemini-style escaped star (\*) to ensure repair distinguishes \\* from \*
        $raw = '{"message":"Escapes test","blocks":[{"type":"markdown","text":"Line 1\nLine 2\t\"quoted\"\nLiteral backslash and star: \\\\*\nInvalid markdown escape repaired: \*(note)\*"}],"actions":[]}';

        $response = $parser->parse($raw, $bundle);
        $text = $response->blocks[0]['text'];
        self::assertStringContainsString("Line 1\nLine 2\t\"quoted\"", $text);
        self::assertStringContainsString('Literal backslash and star: \\*', $text);
        self::assertStringContainsString('Invalid markdown escape repaired: *(note)*', $text);
    }

    public function test_parser_still_rejects_genuinely_malformed_json_and_non_markdown_escapes(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), []);

        // Case 1: Truncated JSON / missing closing brace
        try {
            $parser->parse('{"message":"truncated"', $bundle);
            self::fail('Truncated JSON should be rejected.');
        } catch (AgentResponseRejected $e) {
            self::assertStringContainsString('Agent response is not JSON', $e->getMessage());
        }

        // Case 2: Unquoted keys / invalid JSON syntax
        try {
            $parser->parse('{message: "unquoted"}', $bundle);
            self::fail('Unquoted key JSON should be rejected.');
        } catch (AgentResponseRejected $e) {
            self::assertStringContainsString('Agent response JSON is invalid', $e->getMessage());
        }

        // Case 3: Invalid escape that is NOT safe markdown punctuation (e.g. \q)
        try {
            $parser->parse('{"message":"bad escape \q here","blocks":[]}', $bundle);
            self::fail('Non-markdown invalid escape should be rejected.');
        } catch (AgentResponseRejected $e) {
            self::assertStringContainsString('Agent response JSON is invalid', $e->getMessage());
        }
    }

    public function test_schema_and_evidence_validation_still_run_after_markdown_escape_repair(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(7), [
            new RetrievalSource('gsc', 'ok', 'GET /gsc', ['clicks' => 10]),
        ]);

        // Even though Gemini markdown escapes are repaired, schema rules (missing message, fabricated numbers) must strictly apply
        $missingMessage = '{"blocks":[{"type":"markdown","text":"\*(note)\*"}],"actions":[]}';
        try {
            $parser->parse($missingMessage, $bundle);
            self::fail('Missing message should be rejected.');
        } catch (AgentResponseRejected $e) {
            self::assertStringContainsString('Agent response is missing message', $e->getMessage());
        }

        $inventedChartNumber = '{"message":"Repaired \*(note)\*","blocks":[{"type":"chart","chart":"line","title":"Clicks","x_key":"month","series":[{"key":"clicks","label":"Clicks"}],"data":[{"month":"2026-09","clicks":99999}]}],"actions":[]}';
        try {
            $parser->parse($inventedChartNumber, $bundle);
            self::fail('Fabricated number in chart should be rejected.');
        } catch (AgentResponseRejected $e) {
            self::assertStringContainsString('Chart value is not present in retrieval evidence', $e->getMessage());
        }
    }
}

final class RecordingTransport implements SeoAccessTransport
{
    /** @var list<array{method: string, url: string, bearer: ?string, body: ?array}> */
    public array $calls = [];

    public function __construct(private readonly string $unavailableReason = 'no_synced_data') {}

    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        $this->calls[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'bearer' => $bearer,
            'body' => $jsonBody,
        ];
        if (strtoupper($method) === 'POST') {
            return [
                'status' => 200,
                'json' => ['data' => [
                    'access_url' => 'https://app.example.test/api/v1/access/access_tmp_test',
                    'expires_at' => '2026-09-27T00:00:00+00:00',
                    'site_ref' => 'site:7',
                ]],
            ];
        }

        return [
            'status' => 200,
            'json' => ['data' => [
                'available' => false,
                'reason' => $this->unavailableReason,
                'period' => '2026-09',
                'latest_available' => ['period' => '2026-07'],
            ]],
        ];
    }
}

final class ArticleEvidenceTransport implements SeoAccessTransport
{
    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        if (strtoupper($method) === 'POST') {
            return ['status' => 200, 'json' => ['data' => [
                'access_url' => 'https://app.example.test/api/v1/access/access_tmp_test',
                'expires_at' => '2026-09-30T00:00:00+00:00',
            ]]];
        }
        if (str_contains($url, '/articles')) {
            return ['status' => 200, 'json' => ['data' => [
                'schema' => 'seo.access.articles.v1',
                'articles' => [['article_ref' => 'article:41', 'title' => 'Existing article title', 'status' => 'published']],
            ]]];
        }

        return ['status' => 200, 'json' => ['data' => ['available' => false, 'reason' => 'no_synced_data']]];
    }
}

final class ScriptedDecisionGateway implements DecisionModelGateway
{
    public function __construct(private readonly string $json) {}

    public function decide(DecisionRequest $request): DecisionResult
    {
        return new DecisionResult(true, $this->json);
    }
}

final class RecordingAnswerGateway implements AnswerModelGateway
{
    public int $calls = 0;

    public string $lastExport = '';

    public function __construct(private readonly ?string $rawResponse = null) {}

    public function complete(int $userId, PreparedModelInput $input): string
    {
        $this->calls++;
        $this->lastExport = $input->exportText();

        if ($this->rawResponse !== null) {
            return $this->rawResponse;
        }

        return json_encode([
            'message' => 'No synchronized GSC data for that period.',
            'blocks' => [[
                'type' => 'markdown',
                'text' => 'September has no synced GSC data. The latest available period is 2026-07.',
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}

final class InMemorySiteDirectory implements SiteDirectory
{
    /**
     * @param  list<array{id: int, domain: string, user_id?: int}>  $sites
     */
    public function __construct(public array $sites = []) {}

    public function listActiveSites(?int $userId = null): array
    {
        $out = [];
        foreach ($this->sites as $site) {
            if ($userId !== null && isset($site['user_id']) && $site['user_id'] !== $userId) {
                continue;
            }
            $out[] = ['id' => $site['id'], 'domain' => $site['domain']];
        }

        return $out;
    }

    public function isSiteVisible(int $siteId, ?int $userId = null): bool
    {
        foreach ($this->sites as $site) {
            if ($site['id'] === $siteId) {
                if ($userId !== null && isset($site['user_id']) && $site['user_id'] !== $userId) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }
}

final class RecordingDecisionGateway implements DecisionModelGateway
{
    public int $calls = 0;

    public function __construct(private readonly string $json = '{}') {}

    public function decide(DecisionRequest $request): DecisionResult
    {
        $this->calls++;

        return new DecisionResult(true, $this->json);
    }
}

final class MockAssumedModelResolver implements \Omnichannel\Addons\AgentRuntime\Model\AssumedModelResolver
{
    public function __construct(
        public ?\Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata $decisionMetadata = null,
        public ?\Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata $answerMetadata = null,
    ) {
        $this->decisionMetadata ??= new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata(
            stage: 'decision',
            profile: 'decision.route',
            provider: 'test-provider',
            model: 'test-decision-model',
            displayName: 'Test Decision Model',
            fallbacks: [],
            routingMode: 'priority_order',
            status: 'available',
        );

        $this->answerMetadata ??= new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata(
            stage: 'answer',
            profile: 'text.reasoning',
            provider: 'test-provider',
            model: 'test-answer-model',
            displayName: 'Test Answer Model',
            fallbacks: [],
            routingMode: 'text.reasoning',
            status: 'available',
        );
    }

    public function resolveDecisionModel(int $userId): \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata
    {
        return $this->decisionMetadata;
    }

    public function resolveAnswerModel(int $userId): \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata
    {
        return $this->answerMetadata;
    }
}

