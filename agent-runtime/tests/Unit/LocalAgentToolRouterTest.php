<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Answer\AnswerModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\DecisionModelGateway;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecisionParser;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Model\AgentModelInputBuilder;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Routing\CatalogToolRouteAuthority;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticWeightedClient;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedRouteEvaluator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LocalAgentToolRouterTest extends TestCase
{
    #[Test]
    public function lexical_phrase_does_not_select_a_tool_without_semantic_confidence(): void
    {
        $router = $this->router([jev('none', null)]);
        $route = $router->route('tìm bài SEO kém');

        self::assertSame('none', $route->outcome);
        self::assertNull($route->capability);
        self::assertSame('weighted', $route->evidenceKind);
    }

    #[Test]
    public function keyword_inventory_is_not_forced_into_seo_audit(): void
    {
        $router = $this->router([jev('confident', 'keywords'), jev('confident', 'keywords.landscape')]);
        $route = $router->route('Website này đang theo dõi những từ khóa SEO nào?');

        self::assertSame('confident', $route->outcome);
        self::assertSame('keywords', $route->module);
        self::assertSame('READ', $route->intentFamily);
        self::assertSame('keywords.landscape', $route->capability);
        self::assertNotSame('seo_audit.worst_articles', $route->capability);
        self::assertFalse($route->answerModelRequired);
    }

    #[Test]
    public function ambiguous_modules_do_not_execute(): void
    {
        $router = $this->router([jev('ambiguous', null)]);
        $route = $router->route('traffic tháng này và draft hiện tại');

        self::assertSame('ambiguous', $route->outcome);
        self::assertNull($route->capability);
        self::assertFalse($route->catalogAuthorized);
    }

    #[Test]
    public function unmatched_message_stays_unresolved(): void
    {
        $router = $this->router([jev('none', null)]);
        $route = $router->route('thời tiết hôm nay thế nào');

        self::assertSame('none', $route->outcome);
        self::assertNull($route->capability);
    }

    #[Test]
    public function unconnected_capability_is_unsupported_even_with_a_high_score(): void
    {
        $authority = new CatalogToolRouteAuthority();
        self::assertFalse($authority->accepts('seo_audit.publish'));

        $router = $this->router([jev('confident', 'seo_audit'), jev('confident', 'seo_audit.publish')]);
        $route = $router->route('hãy làm điều không có ví dụ tường minh');

        self::assertSame('unsupported', $route->outcome);
        self::assertSame('seo_audit.publish', $route->capability);
        self::assertSame('seo_audit.publish', $route->diagnostics['operation'] ?? null);
        self::assertFalse($route->catalogAuthorized);
        self::assertFalse($route->executesTool());
    }

    #[Test]
    public function hidden_available_capability_stays_rejected(): void
    {
        \Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog::register('demo.hidden', [
            'label' => 'Hidden',
            'description' => 'Selectable policy denial.',
            'jev_selectable' => false,
            'execution_mode' => 'direct',
            'requires_confirmation' => false,
            'status' => 'available',
            'modules' => [],
        ]);
        try {
            $routing = new SemanticRoutingConfig([
                'revision' => 1,
                'global' => [[
                    'id' => 'hidden',
                    'name' => 'Hidden',
                    'examples' => ['hidden status'],
                    'targets' => [['ref' => 'demo', 'weight' => 10]],
                ]],
                'modules' => [
                    'demo' => [[
                        'id' => 'hidden_read',
                        'name' => 'Hidden',
                        'examples' => ['hidden status'],
                        'targets' => [['ref' => 'demo.hidden', 'weight' => 10]],
                    ]],
                ],
                'operations' => [
                    'demo.hidden' => ['family' => 'READ', 'capability' => 'demo.hidden', 'answer_model' => false, 'secondary' => []],
                ],
            ]);
            $route = (new LocalAgentToolRouter(
                new ScriptedWeightedEvaluator([jev('confident', 'demo'), jev('confident', 'demo.hidden')]),
                $routing,
            ))->route('hidden status');

            self::assertSame('rejected', $route->outcome);
            self::assertSame('demo.hidden', $route->capability);
            self::assertFalse($route->catalogAuthorized);
        } finally {
            \Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog::unregister('demo.hidden');
        }
    }

    #[Test]
    public function missing_focus_keyword_is_guidance_without_draft(): void
    {
        $router = $this->router([jev('confident', 'seo_audit'), jev('confident', 'seo_audit.focus_keyword_guidance')]);
        $route = $router->route('Bài nào chưa có Focus Keyword?');

        self::assertSame('confident', $route->outcome);
        self::assertNull($route->capability);
        self::assertStringContainsString('/seo/content-projects/seo-audit', (string) $route->guidance);
        self::assertFalse($route->answerModelRequired);
    }

    #[Test]
    public function settings_weights_change_the_module_when_relevance_is_equal(): void
    {
        $keywordsFirst = new SemanticRoutingConfig([
            'revision' => 1,
            'global' => [[
                'id' => 'inventory',
                'name' => 'Inventory',
                'examples' => ['Site hiện có những từ khóa nào trong hệ thống?'],
                'enabled' => true,
                'targets' => [
                    ['ref' => 'keywords', 'weight' => 10],
                    ['ref' => 'seo_audit', 'weight' => 3],
                ],
            ]],
            'modules' => [
                'keywords' => [[
                    'id' => 'keywords_inventory',
                    'name' => 'Keyword inventory',
                    'examples' => ['Site hiện có những từ khóa nào trong hệ thống?'],
                    'enabled' => true,
                    'targets' => [['ref' => 'keywords.landscape', 'weight' => 10]],
                ]],
            ],
        ]);
        $auditFirst = new SemanticRoutingConfig([
            'revision' => 2,
            'global' => [[
                'id' => 'inventory',
                'name' => 'Inventory',
                'examples' => ['Site hiện có những từ khóa nào trong hệ thống?'],
                'enabled' => true,
                'targets' => [
                    ['ref' => 'keywords', 'weight' => 3],
                    ['ref' => 'seo_audit', 'weight' => 10],
                ],
            ]],
            'modules' => [
                'seo_audit' => [[
                    'id' => 'audit_low_score',
                    'name' => 'Low SEO score list',
                    'examples' => ['Những bài nào có điểm SEO thấp?'],
                    'enabled' => true,
                    'targets' => [['ref' => 'seo_audit.worst_articles', 'weight' => 10]],
                ]],
            ],
        ]);
        $question = 'Site hiện có những từ khóa nào trong hệ thống?';

        $keywords = (new LocalAgentToolRouter(new EqualRelevanceEvaluator(), $keywordsFirst))->route($question);
        $audit = (new LocalAgentToolRouter(new EqualRelevanceEvaluator(), $auditFirst))->route($question);

        self::assertSame('keywords.landscape', $keywords->capability);
        self::assertSame('seo_audit.worst_articles', $audit->capability);
    }

    #[Test]
    public function enabled_local_router_does_not_call_the_decision_model(): void
    {
        config(['agent-runtime.local_tool_router.enabled' => true]);
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');

        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('listArticles')->willReturn(['items' => [], 'total' => 0, 'post_type' => null]);
        $retrieval = new RetrievalExecutor(
                new RetrievalPlanner(),
                new SeoAccessExecutor(
                    new class implements SeoAccessTransport {
                        public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
                        {
                            throw new \LogicException('SEO Access must not run for an unconfirmed tool route.');
                        }
                    },
                    new class implements SeoAccessCredential {
                        public function bearer(): ?string
                        {
                            return null;
                        }
                    },
                    new SeoAccessUrlPolicy(),
                    'https://app.example.test',
                ),
        );
        $coordinator = new AgentTurnCoordinator(
            new AgentModelInputBuilder(promptBindings: new class implements ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): SeoPrompt
                {
                    $prompt = new SeoPrompt();
                    $prompt->markdown_content = 'local router test';

                    return $prompt;
                }
            }),
            $decisions,
            new RetrievalDecisionParser(),
            $retrieval,
            $answers,
            new AgentResponseParser(),
            localToolRouter: $this->router([jev('confident', 'seo_audit'), jev('confident', 'seo_audit.worst_articles')]),
            confirmedTools: new AgentConfirmedToolExecutor($retrieval, $audit),
        );

        $result = $coordinator->send(1, AgentProjectScope::site(4), 'tìm bài SEO kém', []);

        self::assertNull($result->confirmationProposal);
        self::assertSame('seo_audit.worst_articles', $result->executionTrace['capabilities'][0] ?? null);
        self::assertFalse($result->answerModelCalled);
    }

    #[Test]
    public function paraphrased_question_uses_semantic_match_and_retrieves_without_jev(): void
    {
        $this->fakeSemantic([
            ['status' => 'confident', 'winner' => 'articles'],
            ['status' => 'confident', 'winner' => 'articles.inventory'],
        ]);
        $transport = new RecordingSeoTransport();
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->once())->method('complete')->willReturn('not-a-contract');

        $result = $this->coordinator($decisions, $answers, $transport, new SemanticWeightedClient())
            ->send(1, AgentProjectScope::site(4), 'Hiện trên site đang có những trang nội dung nào?', []);

        self::assertNull($result->confirmationProposal);
        self::assertTrue($result->answerModelCalled);
        self::assertNotEmpty($transport->methods);
        self::assertContains('POST', $transport->methods);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/tool-intents/hybrid-match'));
    }

    #[Test]
    public function ambiguous_semantic_match_does_not_execute(): void
    {
        $this->fakeSemantic([
            ['status' => 'ambiguous', 'winner' => null],
        ]);
        $transport = new RecordingSeoTransport();
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');
        $answers = $this->createMock(AnswerModelGateway::class);
        $answers->expects($this->never())->method('complete');

        $result = $this->coordinator($decisions, $answers, $transport, new SemanticWeightedClient())
            ->send(1, AgentProjectScope::site(4), 'Hiện trên site đang có những trang nội dung nào?', []);

        self::assertNull($result->confirmationProposal);
        self::assertSame('local_tool_router_ambiguous', $result->failureCode);
        self::assertSame([], $transport->methods);
    }

    #[Test]
    public function semantic_cannot_select_an_unavailable_tool(): void
    {
        $this->fakeSemantic([
            ['status' => 'confident', 'winner' => 'seo_audit'],
            ['status' => 'confident', 'winner' => 'seo_audit.publish'],
        ]);
        $transport = new RecordingSeoTransport();
        $decisions = $this->createMock(DecisionModelGateway::class);
        $decisions->expects($this->never())->method('decide');

        $result = $this->coordinator(
            $decisions,
            $this->createMock(AnswerModelGateway::class),
            $transport,
            new SemanticWeightedClient(),
        )->send(1, AgentProjectScope::site(4), 'Hiện trên site đang có những trang nội dung nào?', []);

        self::assertNull($result->confirmationProposal);
        self::assertSame('local_tool_router_unsupported', $result->failureCode);
        self::assertSame('Agent đã hiểu yêu cầu nhưng chức năng này hiện chưa được hỗ trợ.', $result->response?->message);
        self::assertSame([], $transport->methods);
    }

    /** @param list<array{status: string, winner: ?string}> $steps */
    private function fakeSemantic(array $steps): void
    {
        config([
            'agent-runtime.local_tool_router.enabled' => true,
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
        ]);
        $module = $steps[0];
        $operation = $steps[1] ?? null;
        $status = (string) $module['status'];
        $payload = [
            'status' => $status,
            'reason' => $status,
            'global_candidates' => [],
            'operation_candidates' => [],
        ];
        if ($status === 'confident' && is_array($operation) && ($operation['status'] ?? null) === 'confident') {
            $payload['module'] = $module['winner'];
            $payload['operation'] = $operation['winner'];
            $payload['operation_candidates'] = [[
                'module' => $module['winner'],
                'operation' => $operation['winner'],
                'internal_semantic_score' => 0.8,
                'group_id' => 'group',
                'example' => 'example',
            ]];
        }
        Http::fake([
            'http://semantic.test/v1/tool-intents/hybrid-match' => Http::response($payload),
        ]);
    }

    private function coordinator(
        DecisionModelGateway $decisions,
        AnswerModelGateway $answers,
        RecordingSeoTransport $transport,
        WeightedRouteEvaluator $evaluator,
    ): AgentTurnCoordinator {
        return new AgentTurnCoordinator(
            new AgentModelInputBuilder(promptBindings: new class implements ResolvesSettingsPromptHook {
                public function resolveSettingsHook(string $hookKey): SeoPrompt
                {
                    $prompt = new SeoPrompt();
                    $prompt->markdown_content = 'local router test';

                    return $prompt;
                }
            }),
            $decisions,
            new RetrievalDecisionParser(),
            new RetrievalExecutor(
                new RetrievalPlanner(),
                new SeoAccessExecutor(
                    $transport,
                    new class implements SeoAccessCredential {
                        public function bearer(): ?string
                        {
                            return 'svc_live_test';
                        }
                    },
                    new SeoAccessUrlPolicy(),
                    'https://app.example.test',
                ),
            ),
            $answers,
            new AgentResponseParser(),
            localToolRouter: $evaluator instanceof LocalAgentToolRouter ? $evaluator : new LocalAgentToolRouter($evaluator),
        );
    }

    /** @param list<WeightedEvaluation> $steps */
    private function router(array $steps): LocalAgentToolRouter
    {
        return new LocalAgentToolRouter(new ScriptedWeightedEvaluator($steps));
    }
}

function jev(string $status, ?string $winner): WeightedEvaluation
{
    return new WeightedEvaluation($status, $winner, $winner === null ? [] : [[
        'ref' => $winner,
        'semantic_relevance' => 0.8,
        'weight' => 10.0,
        'score' => 0.8,
        'group_id' => 'group',
        'example' => 'example',
    ]]);
}

final class ScriptedWeightedEvaluator implements WeightedRouteEvaluator
{
    private int $index = 0;

    /** @param list<WeightedEvaluation> $steps */
    public function __construct(private array $steps) {}

    public function evaluate(string $query, array $groups): WeightedEvaluation
    {
        $step = $this->steps[$this->index] ?? $this->steps[array_key_last($this->steps)];
        $this->index++;

        return $step;
    }
}

final class EqualRelevanceEvaluator implements WeightedRouteEvaluator
{
    public function evaluate(string $query, array $groups): WeightedEvaluation
    {
        $maxWeight = 1;
        foreach ($groups as $group) {
            foreach ((array) ($group['targets'] ?? []) as $target) {
                $maxWeight = max($maxWeight, (int) ($target['weight'] ?? 1));
            }
        }
        $best = [];
        foreach ($groups as $group) {
            if (($group['enabled'] ?? true) !== true) {
                continue;
            }
            foreach ((array) ($group['targets'] ?? []) as $target) {
                $ref = (string) ($target['ref'] ?? '');
                $weight = (int) ($target['weight'] ?? 0);
                $score = 0.9 * ($weight / $maxWeight);
                if (! isset($best[$ref]) || $score > $best[$ref]['score']) {
                    $best[$ref] = [
                        'ref' => $ref,
                        'semantic_relevance' => 0.9,
                        'weight' => (float) $weight,
                        'score' => $score,
                        'group_id' => (string) ($group['id'] ?? ''),
                        'example' => (string) (($group['examples'][0] ?? '')),
                    ];
                }
            }
        }
        $candidates = array_values($best);
        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        if ($candidates === []) {
            return new WeightedEvaluation('none', null, []);
        }
        $second = $candidates[1]['score'] ?? null;
        if ($second !== null && ($candidates[0]['score'] - $second) < 0.08) {
            return new WeightedEvaluation('ambiguous', null, $candidates);
        }

        return new WeightedEvaluation('confident', $candidates[0]['ref'], $candidates);
    }
}

final class RecordingSeoTransport implements SeoAccessTransport
{
    /** @var list<string> */
    public array $methods = [];

    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
    {
        $this->methods[] = $method;
        if ($method === 'POST') {
            return ['status' => 200, 'json' => ['data' => [
                'access_url' => 'https://app.example.test/api/v1/services/seo/access/tok',
                'expires_at' => '',
            ]]];
        }

        return ['status' => 200, 'json' => ['data' => ['available' => true]]];
    }
}
