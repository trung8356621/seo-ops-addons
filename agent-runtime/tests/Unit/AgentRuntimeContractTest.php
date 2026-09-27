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
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeContractTest extends TestCase
{
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
