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
        self::assertSame(['actions', 'assistant_message_id', 'blocks', 'message', 'run_ulid', 'sources', 'thread_ulid', 'user_message_id'], $keys);

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

    public function test_answer_prompt_requires_existing_and_clearly_labeled_new_opportunities_without_fabricated_metrics(): void
    {
        $prompt = \Omnichannel\Addons\AgentRuntime\Answer\AnswerRuntimeInstructions::system();
        $routing = \Omnichannel\Addons\AgentRuntime\Decision\RoutingRuntimeInstructions::system();
        self::assertStringContainsString('retrieved SEO/site data as evidence', $prompt);
        self::assertStringContainsString('existing data opportunities', $prompt);
        self::assertStringContainsString('new topic or content opportunities', $prompt);
        self::assertStringContainsString('not confirmed as an existing Topic or Keyword', $prompt);
        self::assertStringContainsString('never fabricate search volume', $prompt);
        self::assertStringContainsString('what the evidence says, why it matters, and what to do next', $prompt);
        self::assertStringContainsString('exactly one valid JSON object', $prompt);
        self::assertStringContainsString('Do not wrap it in a Markdown code fence', $prompt);
        self::assertStringContainsString('Put inferred new topics in markdown/list content', $prompt);
        self::assertStringNotContainsString('new topic or content opportunities', $routing);
        self::assertStringNotContainsString('inferred new topics', $routing);
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
            'manual_result' => '{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}',
        ]), $coordinator, $threads, $persistence, $resolver);
        $decisionData = $decision->getData(true)['data'];
        self::assertSame('paused', $decisionData['status']);
        self::assertSame($run->ulid, $decisionData['run_ulid']);
        self::assertSame('answer', $decisionData['model_call']['key']);
        self::assertNotEmpty($decisionData['model_call']['full_prompt']);
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
            new ScriptedDecisionGateway('{"intent":"traffic","needs":{"gsc":0.9},"parameters":{"period":"2026-09"}}'),
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

