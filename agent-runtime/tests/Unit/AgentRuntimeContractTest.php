<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use InvalidArgumentException;
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
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Domain\AgentHostContext;
use Omnichannel\Addons\AgentRuntime\Filament\Pages\AgentRuntimePage;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;
use Omnichannel\Addons\AgentRuntime\Projects\EloquentSiteDirectory;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

final class AgentRuntimeContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_apps')) {
            $this->artisan('migrate', ['--path' => 'D:\work\omnichannel-addons\agent-runtime\database\migrations', '--realpath' => true]);
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

    public function test_routing_decision_schema_rejects_out_of_range_scores(): void
    {
        $parser = new RetrievalDecisionParser();
        $decision = $parser->parse('{"intent":"gsc","needs":{"gsc":0.87,"site":0.1},"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false}');
        self::assertSame('2026-09', $decision->parameters['period']);
        self::assertSame(0.87, $decision->needs['gsc']);

        $this->expectException(InvalidArgumentException::class);
        $parser->parse('{"intent":"bad","needs":{"gsc":1.4},"parameters":{}}');
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
        $transport = new RecordingTransport();
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
        $planner = new RetrievalPlanner(0.5);
        $decision = (new RetrievalDecisionParser())->parse('{"intent":"traffic","needs":{"gsc":0.9,"site":0.2},"parameters":{"period":"2026-09","url":"https://evil.example/steal"},"requires_parameter_extraction":false,"requires_user_confirmation":false}');
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

    public function test_need_threshold_is_explicit(): void
    {
        $planner = new RetrievalPlanner(0.9);
        $decision = (new RetrievalDecisionParser())->parse('{"intent":"maybe","needs":{"site":0.8,"gsc":0.95},"parameters":{}}');
        $plan = $planner->plan($decision, AgentProjectScope::site(7));
        self::assertSame(['gsc'], array_map(static fn ($step) => $step->resource, $plan->steps));
        self::assertSame(0.5, RetrievalPlanner::DEFAULT_NEED_THRESHOLD);
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
        self::assertSame(['actions', 'blocks', 'message', 'sources', 'thread_ulid'], $keys);

        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('svc_live_secret_value', $raw);
        self::assertStringNotContainsString('Bearer ', $raw);
        self::assertArrayNotHasKey('copy', $data);
        self::assertArrayNotHasKey('answer_model_called', $data);
    }

    public function test_unavailable_gsc_metadata_is_preserved_in_controller_response(): void
    {
        $transport = new RecordingTransport();
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}'),
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
        self::assertNotEmpty($data['sources']);
        $gscSource = null;
        foreach ($data['sources'] as $src) {
            if (($src['name'] ?? null) === 'gsc') {
                $gscSource = $src;
                break;
            }
        }
        self::assertNotNull($gscSource);
        self::assertSame('unavailable', $gscSource['status']);
        self::assertSame('no_synced_data', $gscSource['reason']);
        self::assertSame(['period' => '2026-07'], $gscSource['data']['latest_available']);
    }

    public function test_model_input_copy_endpoint_shares_prepared_input_without_model_completion(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(
            new ScriptedDecisionGateway('{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}'),
            $answers,
        );
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Thang 9 traffic co van de gi?',
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

    public function test_model_debug_start_builds_exact_decision_input_with_zero_live_model_calls(): void
    {
        $answers = new RecordingAnswerGateway();
        $decisions = new RecordingDecisionGateway('{"intent":"traffic","needs":{"gsc":0.9}}');
        $coordinator = $this->coordinator($decisions, $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze traffic status',
        ], userId: 1);

        $response = $controller->modelDebugStart($request, $coordinator, $sites, $resolver);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $decisions->calls);
        self::assertSame(0, $answers->calls);

        $data = $response->getData(true)['data'];
        self::assertSame('decision', $data['stage']);
        self::assertNotEmpty($data['full_prompt']);
        self::assertSame(mb_strlen($data['full_prompt']), $data['prompt_size']);
        self::assertFalse($data['global_unsupported']);

        // Matches exact production routing prompt
        $expectedPrompt = (new AgentModelInputBuilder())->buildRoutingInput(
            AgentProjectScope::site(7),
            'Analyze traffic status',
            [],
        )->exportText();
        self::assertSame($expectedPrompt, $data['full_prompt']);
    }

    public function test_displayed_decision_assumed_model_comes_from_settings_not_hardcoded(): void
    {
        $resolver = new MockAssumedModelResolver(
            decisionMetadata: new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata(
                stage: 'decision',
                profile: 'decision.route',
                provider: 'custom-provider',
                model: 'custom-model-4.5',
                displayName: 'Custom Model 4.5',
                fallbacks: [
                    new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelCandidate('alt-prov', 'alt-model', 'Alt Model', [], 50),
                ],
                routingMode: 'priority_order',
                status: 'available',
            ),
        );
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Analyze traffic status',
        ], userId: 1);

        $response = $controller->modelDebugStart($request, $coordinator, $sites, $resolver);
        $data = $response->getData(true)['data'];

        self::assertSame('custom-provider', $data['assumed_model']['provider']);
        self::assertSame('custom-model-4.5', $data['assumed_model']['model']);
        self::assertSame('Custom Model 4.5', $data['assumed_model']['display_name']);
        self::assertCount(1, $data['assumed_model']['fallbacks']);
        self::assertSame('alt-model', $data['assumed_model']['fallbacks'][0]['model']);
    }

    public function test_manual_valid_decision_result_is_processed_by_same_decision_parser(): void
    {
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $validDecisionJson = '{"intent":"traffic","needs":{"gsc":0.85,"site":0.2},"parameters":{"period":"2026-09"},"requires_parameter_extraction":false,"requires_user_confirmation":false}';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => $validDecisionJson,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        self::assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'];
        self::assertTrue($data['parse_success']);
        self::assertNull($data['parser_error']);
        self::assertSame($validDecisionJson, $data['raw_result']);
        self::assertSame('traffic', $data['decision']['intent']);
        self::assertSame(0.85, $data['decision']['needs']['gsc']);
        self::assertSame('2026-09', $data['decision']['parameters']['period']);
        self::assertNotNull($data['next_stage']);
        self::assertSame('answer', $data['next_stage']['stage']);
    }

    public function test_manual_invalid_decision_returns_parser_error_and_does_not_continue_or_call_model(): void
    {
        $answers = new RecordingAnswerGateway();
        $decisions = new RecordingDecisionGateway('{}');
        $coordinator = $this->coordinator($decisions, $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $invalidDecision = 'This is plain text not JSON at all';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => $invalidDecision,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $decisions->calls);
        self::assertSame(0, $answers->calls);

        $data = $response->getData(true)['data'];
        self::assertFalse($data['parse_success']);
        self::assertNotNull($data['parser_error']);
        self::assertSame($invalidDecision, $data['raw_result']);
        self::assertNull($data['next_stage']);
    }

    public function test_valid_manual_decision_drives_same_retrieval_planner_and_executor(): void
    {
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway(), $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $decision = '{"intent":"traffic","needs":{"gsc":0.9,"site":0.1},"parameters":{"period":"2026-09"}}';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => $decision,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        $data = $response->getData(true)['data'];

        self::assertTrue($data['parse_success']);
        self::assertNotEmpty($data['retrieval_trace']);
        self::assertSame('gsc', $data['retrieval_trace'][0]['name']);
        self::assertSame('GET /gsc?period=2026-09', $data['retrieval_trace'][0]['request']);
    }

    public function test_retrieval_trace_preserves_unavailable_metadata_and_reason(): void
    {
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway(), $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $decision = '{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => $decision,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        $data = $response->getData(true)['data'];

        $trace = $data['retrieval_trace'][0];
        self::assertSame('unavailable', $trace['status']);
        self::assertSame('no_synced_data', $trace['reason']);
        self::assertSame(['period' => '2026-07'], $trace['data']['latest_available']);
    }

    public function test_answer_debug_input_matches_production_answer_input(): void
    {
        $decisionJson = '{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}';
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway($decisionJson), $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        // 1. Production copy
        $copyRequest = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
        ], userId: 1);
        $copyResponse = $controller->modelInput($copyRequest, $coordinator, $sites);
        $productionAnswerPrompt = $copyResponse->getData(true)['data']['copy']['answer'];

        // 2. Debug apply decision
        $debugRequest = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => $decisionJson,
        ], userId: 1);
        $debugResponse = $controller->modelDebugApply($debugRequest, $coordinator, $sites, $resolver);
        $debugAnswerPrompt = $debugResponse->getData(true)['data']['next_stage']['full_prompt'];

        self::assertSame($productionAnswerPrompt, $debugAnswerPrompt);
    }

    public function test_answer_assumed_model_is_derived_from_routing_config(): void
    {
        $resolver = new MockAssumedModelResolver(
            answerMetadata: new \Omnichannel\Addons\AgentRuntime\Model\AssumedModelMetadata(
                stage: 'answer',
                profile: 'text.reasoning',
                provider: 'custom-answer-provider',
                model: 'anthropic/claude-sonnet-4',
                displayName: 'Claude Sonnet 4',
                fallbacks: [],
                routingMode: 'text.reasoning',
                status: 'available',
            ),
        );
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => '{"intent":"traffic","needs":{"site":0.2}}',
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        $data = $response->getData(true)['data'];

        self::assertSame('custom-answer-provider', $data['next_stage']['assumed_model']['provider']);
        self::assertSame('anthropic/claude-sonnet-4', $data['next_stage']['assumed_model']['model']);
        self::assertSame('Claude Sonnet 4', $data['next_stage']['assumed_model']['display_name']);
        self::assertSame('text.reasoning', $data['next_stage']['assumed_model']['profile']);
    }

    public function test_manual_valid_answer_result_is_processed_by_same_production_parser(): void
    {
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway(), $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $decisionJson = '{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}';
        $validAnswerJson = '{"message":"GSC data is unavailable for September.","blocks":[{"type":"markdown","text":"Please check July data instead."}],"actions":[]}';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'answer',
            'raw_decision' => $decisionJson,
            'manual_result' => $validAnswerJson,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        self::assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'];
        self::assertTrue($data['parse_success']);
        self::assertNull($data['parser_error']);
        self::assertSame('GSC data is unavailable for September.', $data['response']['message']);
        self::assertCount(1, $data['response']['blocks']);
        self::assertSame('markdown', $data['response']['blocks'][0]['type']);
        self::assertSame('Please check July data instead.', $data['response']['blocks'][0]['text']);
        self::assertArrayHasKey('sources', $data['response']);
    }

    public function test_manual_invalid_answer_result_stays_raw_plus_error_and_does_not_call_model(): void
    {
        $answers = new RecordingAnswerGateway();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), $answers);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $decisionJson = '{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}';
        $invalidAnswer = 'Not json answer response';

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'answer',
            'raw_decision' => $decisionJson,
            'manual_result' => $invalidAnswer,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $answers->calls);

        $data = $response->getData(true)['data'];
        self::assertFalse($data['parse_success']);
        self::assertNotNull($data['parser_error']);
        self::assertSame($invalidAnswer, $data['raw_result']);
        self::assertNull($data['response']);
    }

    public function test_debug_path_creates_no_persistence_records(): void
    {
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $beforeThreads = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::count();
        $beforeMessages = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage::count();
        $beforeRuns = \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::count();

        // 1. Debug start
        $request1 = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
        ], userId: 1);
        $controller->modelDebugStart($request1, $coordinator, $sites, $resolver);

        // 2. Debug apply decision
        $request2 = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'decision',
            'manual_result' => '{"intent":"site","needs":{"site":0.1}}',
        ], userId: 1);
        $controller->modelDebugApply($request2, $coordinator, $sites, $resolver);

        // 3. Debug apply answer
        $request3 = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Check traffic',
            'stage' => 'answer',
            'raw_decision' => '{"intent":"site","needs":{"site":0.1}}',
            'manual_result' => '{"message":"ok","blocks":[],"actions":[]}',
        ], userId: 1);
        $controller->modelDebugApply($request3, $coordinator, $sites, $resolver);

        self::assertSame($beforeThreads, \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread::count());
        self::assertSame($beforeMessages, \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage::count());
        self::assertSame($beforeRuns, \Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun::count());
    }

    public function test_debug_path_executes_no_returned_actions(): void
    {
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway());
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $answerWithActions = json_encode([
            'message' => 'Draft prepared.',
            'blocks' => [],
            'actions' => [
                ['action' => 'content_project.draft.intake', 'label' => 'Create Draft'],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Create draft',
            'stage' => 'answer',
            'raw_decision' => '{"intent":"site","needs":{"site":0.1}}',
            'manual_result' => $answerWithActions,
        ], userId: 1);

        $response = $controller->modelDebugApply($request, $coordinator, $sites, $resolver);
        $data = $response->getData(true)['data'];

        self::assertTrue($data['parse_success']);
        self::assertCount(1, $data['response']['actions']);
        self::assertSame('not_connected', $data['response']['actions'][0]['status']);
    }

    public function test_no_secret_credentials_appear_in_debug_json_or_prompt(): void
    {
        $transport = new RecordingTransport();
        $coordinator = $this->coordinator(new ScriptedDecisionGateway('{}'), new RecordingAnswerGateway(), $transport);
        $sites = new InMemorySiteDirectory([['id' => 7, 'domain' => 'example.test', 'user_id' => 1]]);
        $resolver = new MockAssumedModelResolver();
        $controller = new AgentRuntimeController();

        $request = $this->createTurnRequest([
            'scope' => ['type' => 'site', 'siteId' => 7],
            'message' => 'Prompt containing secret svc_live_fake123 and sk-ant-api03-abcdefghijklmn and Authorization: Bearer secret',
        ], userId: 1);

        $response = $controller->modelDebugStart($request, $coordinator, $sites, $resolver);
        $raw = (string) $response->getContent();

        self::assertStringNotContainsString('svc_live_fake123', $raw);
        self::assertStringNotContainsString('sk-ant-api03-abcdefghijklmn', $raw);
        self::assertStringNotContainsString('svc_live_secret_value', $raw);
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

    public function test_global_scope_remains_unsupported_in_model_debug(): void
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
        $executor = new RetrievalExecutor(
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

        return new AgentTurnCoordinator(
            new AgentModelInputBuilder(),
            $decisions,
            new RetrievalDecisionParser(),
            $executor,
            $answers,
            new AgentResponseParser(),
        );
    }
}

final class RecordingTransport implements SeoAccessTransport
{
    /** @var list<array{method: string, url: string, bearer: ?string, body: ?array}> */
    public array $calls = [];

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
                'reason' => 'no_synced_data',
                'period' => '2026-09',
                'latest_available' => ['period' => '2026-07'],
            ]],
        ];
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

    public function complete(int $userId, PreparedModelInput $input): string
    {
        $this->calls++;
        $this->lastExport = $input->exportText();

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

