<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Response\FactualAgentResponseComposer;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\AgentRuntime\Routing\CatalogToolRouteAuthority;
use Omnichannel\Addons\AgentRuntime\Routing\HybridRouteEvaluator;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;
use Omnichannel\Addons\AgentRuntime\Routing\LocalToolRoute;
use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use Omnichannel\Addons\AgentRuntime\Routing\WeightedRouteEvaluator;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentTurnCoordinator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

final class AgentTopicAndImprovementFixTest extends TestCase
{
    #[Test]
    public function cross_family_ambiguity_resolved_when_user_explicitly_requests_read(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('unavailable', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return [
                    'status' => 'ambiguous',
                    'reason' => 'margin_too_narrow',
                    'module' => 'seo_audit',
                    'operation_candidates' => [
                        [
                            'operation' => 'seo_audit.worst_articles',
                            'internal_semantic_score' => 0.82,
                            'group_id' => 'bulk_seo_evaluation',
                            'example' => 'Những bài nào có điểm SEO thấp?',
                        ],
                        [
                            'operation' => 'seo_audit.site_improve',
                            'internal_semantic_score' => 0.80,
                            'group_id' => 'site_wide_improvement',
                            'example' => 'Nên cải thiện SEO của cả website theo hướng nào?',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Cho tôi xem danh sách các bài viết có điểm SEO thấp.');

        self::assertSame('confident', $route->outcome);
        self::assertSame('seo_audit.worst_articles', $route->capability);
        self::assertSame('READ', $route->intentFamily);
        self::assertArrayHasKey('cross_family_disambiguation', $route->diagnostics);
        self::assertSame('READ', $route->diagnostics['cross_family_disambiguation']['target_family']);
    }

    #[Test]
    public function cross_family_ambiguity_resolved_when_user_explicitly_requests_improve(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('unavailable', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return [
                    'status' => 'ambiguous',
                    'reason' => 'margin_too_narrow',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.82,
                            'group_id' => 'keywords_landscape',
                            'example' => 'Những chủ đề nào có mức độ bao phủ SEO thấp?',
                        ],
                        [
                            'operation' => 'keywords.topic_suggestions',
                            'internal_semantic_score' => 0.80,
                            'group_id' => 'keywords_topic_suggestions',
                            'example' => 'Lập đề xuất bài mới từ các nhóm chủ đề còn thiếu độ phủ.',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Gợi ý cải thiện các topic còn yếu cho website.');

        // keywords.topic_suggestions is an unsupported IMPROVE capability in catalog,
        // and must stay unsupported rather than being converted into an unrelated READ.
        self::assertSame('unsupported', $route->outcome);
        self::assertSame('IMPROVE', $route->intentFamily);
        self::assertArrayHasKey('cross_family_disambiguation', $route->diagnostics);
        self::assertSame('IMPROVE', $route->diagnostics['cross_family_disambiguation']['target_family']);
    }

    #[Test]
    public function same_family_ambiguity_is_strictly_preserved(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('unavailable', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return [
                    'status' => 'ambiguous',
                    'reason' => 'margin_too_narrow',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.inventory',
                            'internal_semantic_score' => 0.87352,
                            'group_id' => 'keywords_inventory',
                            'example' => 'Website đang theo dõi những từ khóa SEO nào?',
                        ],
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.83400,
                            'group_id' => 'keywords_landscape',
                            'example' => 'Những chủ đề nào có mức độ bao phủ SEO thấp?',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Cho tôi xem danh sách các Topic SEO của website.');

        // Genuine same-family ambiguity between keywords.inventory (READ) and keywords.landscape (READ)
        // must remain ambiguous without brittle overrides.
        self::assertSame('ambiguous', $route->outcome);
        self::assertNull($route->capability);
        self::assertArrayNotHasKey('cross_family_disambiguation', $route->diagnostics);
    }

    #[Test]
    public function local_decision_json_sets_report_template_for_improve_intent(): void
    {
        $coordinator = (new \ReflectionClass(AgentTurnCoordinator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($coordinator, 'localDecisionJson');

        $improveRoute = new LocalToolRoute(
            outcome: 'confident',
            capability: 'seo_audit.worst_articles',
            score: 0.85,
            catalogAuthorized: true,
            matches: [],
            evidenceKind: 'hybrid_weighted',
            intentFamily: 'IMPROVE',
            module: 'seo_audit',
            guidance: null,
            answerModelRequired: true,
        );

        $extracted = [
            'parameters' => [],
            'clarification' => null,
            'language' => 'vi',
        ];

        $json = $method->invoke($coordinator, $improveRoute, 'Hãy đề xuất cách cải thiện SEO tổng thể', $extracted);
        $decision = json_decode((string) $json, true);

        self::assertSame('report', $decision['response_template']);
        self::assertSame('seo_audit.worst_articles', $decision['primary_capability']);

        $readRoute = new LocalToolRoute(
            outcome: 'confident',
            capability: 'keywords.landscape',
            score: 0.85,
            catalogAuthorized: true,
            matches: [],
            evidenceKind: 'hybrid_weighted',
            intentFamily: 'READ',
            module: 'keywords',
            guidance: null,
            answerModelRequired: false,
        );

        $jsonRead = $method->invoke($coordinator, $readRoute, 'Danh sách topic', $extracted);
        $decisionRead = json_decode((string) $jsonRead, true);

        self::assertSame('table', $decisionRead['response_template']);
    }

    #[Test]
    public function verified_fallback_omits_draft_intake_controls_for_improvement_analysis(): void
    {
        $items = [];
        for ($i = 1; $i <= 50; $i++) {
            $items[] = [
                'article_ref' => "article:{$i}",
                'title' => "Bài viết {$i}",
                'focus_keyword' => "tu khoa {$i}",
                'seo_score' => 20 + $i,
                'reason_labels' => ['Thiếu thẻ meta', 'Nội dung ngắn'],
            ];
        }

        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'seo_audit.worst_articles', [
                'draft_action' => 'content_project.draft.intake',
                'draft_item_type' => 'improve',
                'draft_source' => 'seo_audit',
                'total' => 50,
                'items' => $items,
            ]),
        ]);

        $coordinator = (new \ReflectionClass(AgentTurnCoordinator::class))->newInstanceWithoutConstructor();
        $propertyFactual = new \ReflectionProperty($coordinator, 'factual');
        $propertyFactual->setValue($coordinator, new FactualAgentResponseComposer());

        $fallback = $coordinator->verifiedFallback(
            $bundle,
            'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.',
            'vi',
        );

        self::assertNotEmpty($fallback->blocks);
        self::assertSame([], $fallback->actions);

        $hasWarning = false;
        $tableBlock = null;
        foreach ($fallback->blocks as $block) {
            if ($block['type'] === 'warning') {
                $hasWarning = true;
                self::assertStringContainsString('Đề xuất cải thiện', $block['text']);
            }
            if ($block['type'] === 'table') {
                $tableBlock = $block;
            }
        }

        self::assertTrue($hasWarning);
        self::assertNotNull($tableBlock);

        // Selection controls must NOT appear in improvement fallback
        self::assertArrayNotHasKey('actionable', $tableBlock);

        // At most 8 sample rows are shown, not all 50
        self::assertLessThanOrEqual(8, count($tableBlock['rows']));
        self::assertCount(8, $tableBlock['rows']);

        // Individual rows must NOT contain draft intake items
        self::assertArrayNotHasKey('item', $tableBlock['rows'][0]);
        self::assertSame('Bài viết 1', $tableBlock['rows'][0]['title']);
        self::assertSame(21, $tableBlock['rows'][0]['seo_score']);
    }

    #[Test]
    public function explicit_draft_request_preserves_draft_intake_table(): void
    {
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'seo_audit.worst_articles', [
                'draft_action' => 'content_project.draft.intake',
                'draft_item_type' => 'improve',
                'draft_source' => 'seo_audit',
                'total' => 2,
                'items' => [
                    [
                        'article_ref' => 'article:1',
                        'title' => 'Bài A',
                        'focus_keyword' => 'tu khoa a',
                        'seo_score' => 25,
                        'reason_labels' => ['Thiếu heading'],
                    ],
                ],
            ]),
        ]);

        $composer = new FactualAgentResponseComposer();
        $response = $composer->verifiedFacts($bundle, 'vi', 'Đưa các bài vừa chọn vào Draft cải thiện.');

        self::assertNotNull($response);
        $block = $response->blocks[0];
        self::assertSame('table', $block['type']);
        self::assertArrayHasKey('actionable', $block);
        self::assertSame('content_project.draft.intake', $block['actionable']['action']);
        self::assertSame('improve', $block['rows'][0]['item']['type']);
        self::assertSame('article:1', $block['rows'][0]['item']['article_ref']);
    }
}
