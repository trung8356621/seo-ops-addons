<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseParser;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseRejected;
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
    // ==========================================
    // POSITIVE TESTS (PART D: 1, 2, 3)
    // ==========================================

    #[Test]
    public function positive_1_topic_listing_resolves_to_keywords_landscape_via_entity_disambiguation(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.inventory',
                            'internal_semantic_score' => 0.863,
                            'final_score' => 0.873,
                            'group_id' => 'keywords_inventory',
                            'example' => 'Website đang theo dõi những từ khóa SEO nào?',
                        ],
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.802,
                            'final_score' => 0.834,
                            'group_id' => 'keywords_landscape',
                            'example' => 'Cho tôi xem những chủ đề có độ bao phủ SEO thấp.',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Cho tôi xem danh sách các Topic SEO của website.');

        self::assertSame('confident', $route->outcome);
        self::assertSame('keywords.landscape', $route->capability);
        self::assertSame('READ', $route->intentFamily);
        self::assertArrayHasKey('entity_disambiguation', $route->diagnostics);
        self::assertSame('topic', $route->diagnostics['entity_disambiguation']['target_entity']);
    }

    #[Test]
    public function positive_2_keyword_listing_resolves_to_keywords_inventory(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.inventory',
                            'internal_semantic_score' => 0.896,
                            'final_score' => 0.896,
                            'group_id' => 'keywords_inventory',
                            'example' => 'Website đang theo dõi những từ khóa SEO nào?',
                        ],
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.834,
                            'final_score' => 0.834,
                            'group_id' => 'keywords_landscape',
                            'example' => 'Cho tôi xem những chủ đề có độ bao phủ SEO thấp.',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Cho tôi xem danh sách từ khóa SEO của website.');

        self::assertSame('confident', $route->outcome);
        self::assertSame('keywords.inventory', $route->capability);
        self::assertSame('READ', $route->intentFamily);
        self::assertArrayHasKey('entity_disambiguation', $route->diagnostics);
        self::assertSame('keyword', $route->diagnostics['entity_disambiguation']['target_entity']);
    }

    #[Test]
    public function positive_3_topic_strength_statistics_preserves_confident_keywords_landscape(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('unavailable', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return [
                    'status' => 'confident',
                    'module' => 'keywords',
                    'operation' => 'keywords.landscape',
                    'reason' => 'confident_internal',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 1.0,
                            'group_id' => 'keywords_landscape',
                            'example' => 'Thống kê số chủ đề Strong, Medium và Weak của website.',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Thống kê số chủ đề Strong, Medium và Weak của website.');

        self::assertSame('confident', $route->outcome);
        self::assertSame('keywords.landscape', $route->capability);
        self::assertSame('READ', $route->intentFamily);
    }

    // ==========================================
    // NEGATIVE TESTS (PART D: 4, 5, 6, 7, 8, 9)
    // ==========================================

    #[Test]
    public function negative_4_topic_suggestions_preserves_unsupported_improve(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('unavailable', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return [
                    'status' => 'confident',
                    'module' => 'keywords',
                    'operation' => 'keywords.topic_suggestions',
                    'reason' => 'confident_internal',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.topic_suggestions',
                            'internal_semantic_score' => 0.758,
                            'group_id' => 'keywords_topic_suggestions',
                            'example' => 'Dựa trên Topic bao phủ yếu, hãy đề xuất bài viết mới.',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Dựa trên Topic yếu, hãy đề xuất bài viết mới.');

        // keywords.topic_suggestions maps to content.topic_suggestions (unsupported)
        // Must stay unsupported IMPROVE, not converted to READ.
        self::assertSame('unsupported', $route->outcome);
        self::assertSame('IMPROVE', $route->intentFamily);
    }

    #[Test]
    public function negative_5_same_family_ambiguity_without_distinguishing_entity_remains_ambiguous(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.inventory',
                            'internal_semantic_score' => 0.85,
                            'final_score' => 0.85,
                            'group_id' => 'keywords_inventory',
                        ],
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.82,
                            'final_score' => 0.82,
                            'group_id' => 'keywords_landscape',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        // Generic phrasing without mentioning topic or keyword
        $route = $router->route('Cho tôi xem danh sách dữ liệu hiện có của website.');

        self::assertSame('ambiguous', $route->outcome);
        self::assertNull($route->capability);
        self::assertArrayHasKey('clarification', $route->diagnostics);
        self::assertSame('Bạn muốn xem danh sách Topic (chủ đề) hay danh sách từ khóa?', $route->diagnostics['clarification']['vi']);
    }

    #[Test]
    public function negative_6_conflicting_entity_terms_preserves_ambiguity(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'keywords',
                    'operation_candidates' => [
                        [
                            'operation' => 'keywords.inventory',
                            'internal_semantic_score' => 0.85,
                            'final_score' => 0.85,
                            'group_id' => 'keywords_inventory',
                        ],
                        [
                            'operation' => 'keywords.landscape',
                            'internal_semantic_score' => 0.83,
                            'final_score' => 0.83,
                            'group_id' => 'keywords_landscape',
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        // Query mentions both Topic AND từ khóa
        $route = $router->route('Cho tôi xem cả topic và từ khóa của website.');

        self::assertSame('ambiguous', $route->outcome);
        self::assertNull($route->capability);
    }

    #[Test]
    public function negative_7_single_article_improvement_preserves_ambiguity(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'articles',
                    'operation_candidates' => [
                        [
                            'operation' => 'articles.improve',
                            'internal_semantic_score' => 0.72,
                            'final_score' => 0.72,
                        ],
                        [
                            'operation' => 'seo_audit.site_improve',
                            'internal_semantic_score' => 0.799,
                            'final_score' => 0.799,
                        ],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Bài viết balo học sinh cần cải thiện SEO thế nào?');

        self::assertSame('ambiguous', $route->outcome);
    }

    #[Test]
    public function negative_8_gsc_high_impression_low_seo_preserves_ambiguity(): void
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
                    'reason' => 'final_operation_margin',
                    'module' => 'gsc',
                    'operation_candidates' => [
                        ['operation' => 'gsc.cross_read', 'internal_semantic_score' => 0.74, 'final_score' => 0.74],
                        ['operation' => 'seo_audit.worst_articles', 'internal_semantic_score' => 0.71, 'final_score' => 0.71],
                    ],
                ];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Những bài có impression cao nhưng điểm SEO thấp?');

        self::assertSame('ambiguous', $route->outcome);
    }

    #[Test]
    public function negative_9_weather_query_remains_out_of_scope(): void
    {
        $evaluator = new class implements WeightedRouteEvaluator, HybridRouteEvaluator {
            public function evaluate(string $query, array $groups): \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation
            {
                return new \Omnichannel\Addons\AgentRuntime\Routing\WeightedEvaluation('none', null, []);
            }

            public function evaluateHybrid(string $query, array $document): array
            {
                return ['status' => 'none', 'reason' => 'below_threshold', 'operation_candidates' => []];
            }
        };

        $router = new LocalAgentToolRouter($evaluator, new SemanticRoutingConfig(), new CatalogToolRouteAuthority());
        $route = $router->route('Ngày mai thời tiết thế nào?');

        self::assertSame('none', $route->outcome);
        self::assertNull($route->capability);
    }

    // ==========================================
    // ANSWER RESPONSE TESTS (PART D: 10, 11, 12, 13, 14)
    // ==========================================

    #[Test]
    public function answer_10_valid_report_response_is_accepted(): void
    {
        $parser = new AgentResponseParser();
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('audit', 'ok', 'seo_audit.worst_articles', [
                'total' => 20,
                'items' => [['title' => 'Bài A', 'seo_score' => 25]],
            ]),
        ]);

        $reportJson = json_encode([
            'message' => 'Đề xuất cải thiện SEO tổng thể',
            'blocks' => [
                [
                    'type' => 'markdown',
                    'text' => "### Đề xuất ưu tiên\n1. Tối ưu tiêu đề và thẻ meta cho bài viết chất lượng thấp.\n2. Bổ sung nội dung cho các bài viết có điểm SEO thấp.\n3. Cải thiện cấu trúc liên kết nội bộ.",
                ],
            ],
            'actions' => [],
        ], JSON_THROW_ON_ERROR);

        $parsed = $parser->parse($reportJson, $bundle);

        self::assertSame([], $parser->lastRejections());
        self::assertSame('Đề xuất cải thiện SEO tổng thể', $parsed->message);
        self::assertCount(1, $parsed->blocks);
        self::assertSame('markdown', $parsed->blocks[0]['type']);
    }

    #[Test]
    public function answer_11_invalid_report_triggers_safe_fallback(): void
    {
        $coordinator = (new \ReflectionClass(AgentTurnCoordinator::class))->newInstanceWithoutConstructor();
        $propertyFactual = new \ReflectionProperty($coordinator, 'factual');
        $propertyFactual->setValue($coordinator, new FactualAgentResponseComposer());
        $propertyResponses = new \ReflectionProperty($coordinator, 'responses');
        $propertyResponses->setValue($coordinator, new AgentResponseParser());

        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'seo_audit.worst_articles', [
                'total' => 25,
                'items' => [
                    ['title' => 'Bài 1', 'focus_keyword' => 'k1', 'seo_score' => 20, 'reason_labels' => ['Lỗi']],
                ],
            ]),
        ]);

        $method = new ReflectionMethod($coordinator, 'recoverParsedAnswer');
        $result = $method->invoke($coordinator, 'malformed json string', $bundle, 'Hãy đề xuất cách cải thiện SEO tổng thể', 'vi', true);

        self::assertNotNull($result['response']);
        self::assertSame('rejected', $result['diagnostics']['status'] ?? null);
        self::assertNotEmpty($result['response']->blocks);
        self::assertSame('warning', $result['response']->blocks[0]['type']);
    }

    #[Test]
    public function answer_12_seo_improvement_fallback_has_no_draft_intake_controls(): void
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

        $tableBlock = null;
        foreach ($fallback->blocks as $block) {
            if ($block['type'] === 'table') {
                $tableBlock = $block;
            }
        }

        self::assertNotNull($tableBlock);
        // Selection controls must NOT appear in improvement fallback
        self::assertArrayNotHasKey('actionable', $tableBlock);
        // At most 8 sample rows are shown, not all 50
        self::assertLessThanOrEqual(8, count($tableBlock['rows']));
        self::assertCount(8, $tableBlock['rows']);
        // Rows must not have draft intake item
        self::assertArrayNotHasKey('item', $tableBlock['rows'][0]);
    }

    #[Test]
    public function answer_13_explicit_draft_request_preserves_actionable_controls(): void
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

    #[Test]
    public function answer_14_public_response_contains_no_access_tmp(): void
    {
        $composer = new FactualAgentResponseComposer();
        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('topics', 'ok', 'keywords.landscape', [
                'topics' => [
                    [
                        'name' => 'Balo học sinh',
                        'coverage' => 'Weak',
                        'mcp_percent' => 30,
                        'article_count' => 5,
                        'coverage_href' => 'https://app.example.test/topics/1?access_tmp=SECRET_TOKEN',
                        'ui_href' => 'https://app.example.test/api/v1/access/temporary_key',
                    ],
                ],
            ]),
        ]);

        $response = $composer->compose($bundle, 'Thống kê số chủ đề Strong, Medium và Weak của website.', 'vi');
        self::assertNotNull($response);

        $json = json_encode($response->toArray());
        self::assertStringNotContainsString('access_tmp', $json);
        self::assertStringNotContainsString('/api/v1/access/', $json);
        self::assertStringNotContainsString('SECRET_TOKEN', $json);
    }

    #[Test]
    public function answer_15_composite_response_combines_section_a_and_section_b(): void
    {
        $coordinator = (new \ReflectionClass(AgentTurnCoordinator::class))->newInstanceWithoutConstructor();
        $propertyFactual = new \ReflectionProperty($coordinator, 'factual');
        $propertyFactual->setValue($coordinator, new FactualAgentResponseComposer());
        $propertyResponses = new \ReflectionProperty($coordinator, 'responses');
        $propertyResponses->setValue($coordinator, new AgentResponseParser());

        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'seo_audit.worst_articles', [
                'total' => 20,
                'items' => [
                    ['title' => 'Bài viết A', 'focus_keyword' => 'balo laptop', 'seo_score' => 25, 'reason_labels' => ['Thiếu meta']],
                ],
            ]),
            new RetrievalSource('topics', 'ok', 'keywords.landscape', [
                'topics' => [
                    ['name' => 'Balo du lịch', 'coverage' => 'Weak', 'mcp_percent' => 20],
                ],
            ]),
        ]);

        $modelAnswerJson = json_encode([
            'message' => 'Đề xuất cải thiện SEO tổng thể',
            'blocks' => [
                [
                    'type' => 'markdown',
                    'text' => "1. **Bài viết mới: Cách chọn balo du lịch siêu nhẹ** (Topic: Balo du lịch, Search intent: Informational)\n2. **Bài viết mới: Top 5 balo chống thấm nước tốt nhất** (Topic: Balo du lịch)",
                ],
            ],
            'actions' => [],
        ], JSON_THROW_ON_ERROR);

        $method = new ReflectionMethod($coordinator, 'recoverParsedAnswer');
        $result = $method->invoke($coordinator, $modelAnswerJson, $bundle, 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.', 'vi', false);

        $response = $result['response'];
        self::assertNotNull($response);
        // Must contain both Section A and Section B
        $combinedText = '';
        $hasTable = false;
        foreach ($response->blocks as $block) {
            if ($block['type'] === 'markdown') {
                $combinedText .= $block['text'] . "\n";
            } elseif ($block['type'] === 'table') {
                $hasTable = true;
            }
        }

        self::assertTrue($hasTable, 'Response must include Section A table from audit');
        self::assertStringContainsString('### A. Bài viết hiện có cần cải thiện', $combinedText);
        self::assertStringContainsString('### B. Bài viết mới nên bổ sung', $combinedText);
        self::assertStringContainsString('Cách chọn balo du lịch', $combinedText);
    }

    #[Test]
    public function answer_16_projector_limits_articles_and_includes_topic_coverage(): void
    {
        $projector = new \Omnichannel\Addons\AgentRuntime\Model\AnswerEvidenceProjector();
        $articles = [];
        for ($i = 1; $i <= 50; $i++) {
            $articles[] = [
                'title' => "Bài {$i}",
                'seo_score' => 10 + $i,
                'focus_keyword' => "keyword {$i}",
                'reason_labels' => ['Thiếu H2'],
            ];
        }

        $bundle = new RetrievalBundle(AgentProjectScope::site(4), [
            new RetrievalSource('articles', 'ok', 'seo_audit.worst_articles', [
                'total' => 50,
                'items' => $articles,
            ]),
            new RetrievalSource('topics', 'ok', 'keywords.landscape', [
                'summary' => ['topic_count' => 30],
                'topics' => [
                    ['name' => 'Topic 1', 'coverage' => 'Weak', 'mcp_percent' => 15, 'article_count' => 2],
                ],
            ]),
        ]);

        $projected = $projector->project($bundle, 'Hãy đề xuất cách cải thiện SEO tổng thể cho site này.');
        $sources = $projected['bundle']['sources'];

        // Compact audit check: sample size must be 8, not 50
        $auditData = $sources[0]['data'];
        self::assertSame('retrieved_article_sample', $auditData['dataset_scope']);
        self::assertSame(50, $auditData['total']);
        self::assertCount(8, $auditData['examples']);

        // Compact topic check
        $topicData = $sources[1]['data'];
        self::assertSame('topic_coverage_sample', $topicData['dataset_scope']);
        self::assertCount(1, $topicData['topics']);

        // Analysis tasks
        self::assertNotEmpty($projected['analysis_task']);
        $tasksText = implode(' ', $projected['analysis_task']);
        self::assertStringContainsString('weak or undercovered Topics', $tasksText);
    }
}
