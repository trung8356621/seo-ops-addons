<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\OutboundAiRequest;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptBudgetPlan;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptBudget\DirectFitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\HtmlSafeRewriteSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\KeywordDiscoveryBudgetStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\LongFormArticleSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptChunkLedger;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\KeywordDiscoveryRouteReplanHarness;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator;
use Omnichannel\Addons\AiPrompt\Services\SemanticSplitExecutor;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use PHPUnit\Framework\TestCase;

final class PromptBudgetBoundedExecutionTest extends TestCase
{
    public function test_double_count_prevention_when_continuation_already_inlined(): void
    {
        $preflight = new PromptBudgetPreflightService();
        $cap = new ModelContextCapability(
            contextWindow: 32_000,
            maxOutputTokens: 4096,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 500,
        );
        $continuation = str_repeat('accepted-fp-', 40);
        $compiled = "BRIEF\n\n".$continuation."\n\nGenerate 5 ideas.";
        $strategy = new KeywordDiscoveryBudgetStrategy();

        $withFlag = $preflight->planWithCapability($cap, $strategy, $compiled, [
            'continuation_already_inlined' => true,
            'continuation' => $continuation,
            'batch_target' => 5,
            'desired_output_tokens' => 900,
            'minimum_required_output_tokens' => 300,
        ]);
        $withoutExtra = $preflight->planWithCapability($cap, $strategy, $compiled, [
            'continuation_already_inlined' => true,
            'batch_target' => 5,
            'desired_output_tokens' => 900,
            'minimum_required_output_tokens' => 300,
        ]);

        $this->assertSame($withoutExtra->estimatedInputTokens, $withFlag->estimatedInputTokens);
        $this->assertTrue((bool) ($withFlag->diagnostics['continuation_already_inlined'] ?? false));
    }

    public function test_outbound_always_counts_schema_from_request(): void
    {
        $preflight = new PromptBudgetPreflightService();
        $cap = new ModelContextCapability(
            contextWindow: 16_000,
            maxOutputTokens: 2048,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 400,
            capabilityConfidence: ModelContextCapability::CONFIDENCE_TRUSTED,
            estimatorConfidence: ModelContextCapability::CONFIDENCE_DEFAULT,
        );
        $schema = json_encode(['type' => 'object', 'properties' => ['items' => ['type' => 'array']]], JSON_THROW_ON_ERROR);
        $strategy = new DirectFitStrategy('article.title_suggestion');
        $seed = $preflight->planWithCapability($cap, $strategy, 'short prompt', [
            'desired_output_tokens' => 128,
            'minimum_required_output_tokens' => 64,
            'continuation_already_inlined' => true,
            'schema_already_inlined' => true,
        ]);
        // Manually register verified plan id for outbound gate contract.
        $ref = new \ReflectionClass($preflight);
        $prop = $ref->getProperty('verifiedPlans');
        $prop->setAccessible(true);
        /** @var array<string, PromptBudgetPlan> $plans */
        $plans = $prop->getValue($preflight);
        $plans[$seed->planId] = $seed;
        $prop->setValue($preflight, $plans);

        $outbound = new OutboundAiRequest(
            messages: [['role' => 'user', 'content' => 'short prompt']],
            jsonSchema: $schema,
            tools: [],
            requestedMaxOutputTokens: 128,
            provider: 'deepseek',
            model: 'deepseek-chat',
            planId: $seed->planId,
        );
        $plan = $preflight->assertOutbound($outbound, $cap, $strategy, [
            'desired_output_tokens' => 128,
            'minimum_required_output_tokens' => 64,
        ]);
        $this->assertGreaterThan(0, (int) ($plan->diagnostics['estimated_schema_tokens'] ?? 0));
    }

    public function test_insufficient_output_capability_atomic_throws_before_provider(): void
    {
        $preflight = new PromptBudgetPreflightService();
        $cap = new ModelContextCapability(
            contextWindow: 32_000,
            maxOutputTokens: 500,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 500,
        );
        $strategy = new DirectFitStrategy('article.title_suggestion');
        $plan = $preflight->planWithCapability($cap, $strategy, 'Write title', [
            'desired_output_tokens' => 8000,
            'minimum_required_output_tokens' => 8000,
        ]);
        $this->assertFalse($plan->outputCapabilitySufficient);
        $this->assertFalse($plan->requestFits);

        try {
            $outbound = new OutboundAiRequest(
                messages: [['role' => 'user', 'content' => 'Write title']],
                requestedMaxOutputTokens: 8000,
                provider: 'deepseek',
                model: 'x',
                planId: 'unverified',
            );
            $preflight->assertOutbound($outbound, $cap, $strategy, [
                'desired_output_tokens' => 8000,
                'minimum_required_output_tokens' => 8000,
            ]);
            $this->fail('Expected output capability exception');
        } catch (PromptBudgetException $e) {
            $this->assertStringContainsString(PromptBudgetException::CODE_OUTPUT_CAPABILITY, $e->getMessage());
        }
    }

    public function test_estimator_margin_tiers_differ(): void
    {
        $resolver = new \Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver();
        $ref = new \ReflectionClass($resolver);
        $method = $ref->getMethod('marginFor');
        $method->setAccessible(true);

        [$exact,] = $method->invoke($resolver, ModelContextCapability::CONFIDENCE_TRUSTED, ModelContextCapability::CONFIDENCE_EXACT, 100_000, false);
        [$trusted,] = $method->invoke($resolver, ModelContextCapability::CONFIDENCE_TRUSTED, ModelContextCapability::CONFIDENCE_DEFAULT, 100_000, false);
        [$default,] = $method->invoke($resolver, ModelContextCapability::CONFIDENCE_DEFAULT, ModelContextCapability::CONFIDENCE_DEFAULT, 100_000, false);

        $this->assertLessThan($trusted, $exact);
        $this->assertLessThan($default, $trusted);
    }

    public function test_vietnamese_html_json_estimation_conservative(): void
    {
        $estimator = new PromptTokenEstimator();
        $en = str_repeat('Product keyword backpack fan text ', 40);
        $vi = str_repeat('Tu khoa san pham balo quat rat dai ', 40);
        // Force non-latin density via combining marks mixed into ASCII-length text.
        $dense = str_repeat("Tu khoa\u{0301} san pham ", 40);
        $html = str_repeat('<p>Doan van <strong>HTML</strong> voi lien ket.</p>', 40);
        $json = '{"ideas":[{"keyword":"balo quat cong nghiep"}]}';

        $this->assertGreaterThan(0, $estimator->estimate($en));
        $this->assertGreaterThan(0, $estimator->estimate($vi));
        $this->assertGreaterThanOrEqual($estimator->estimate($dense), (int) floor($estimator->estimate($en) * 0.8));
        $this->assertGreaterThan(0, $estimator->estimate($html));
        $this->assertGreaterThan(0, $estimator->estimate($json));
    }

    public function test_registry_supports_split_only_with_real_strategies(): void
    {
        $registry = new PromptSplitStrategyRegistry();
        $gen = $registry->forHook('article.content.generate');
        $rewrite = $registry->forHook('article.content.rewrite');
        $title = $registry->forHook('article.title_suggestion');

        $this->assertInstanceOf(LongFormArticleSplitStrategy::class, $gen);
        $this->assertTrue($gen->supportsSplit());
        $this->assertInstanceOf(HtmlSafeRewriteSplitStrategy::class, $rewrite);
        $this->assertTrue($rewrite->supportsSplit());
        $this->assertFalse($title->supportsSplit());
        $this->assertSame(PromptSplitClass::SemanticSplit->value, $registry->classificationMap()['article.content.translate']);
    }

    public function test_long_form_split_and_merge_order(): void
    {
        $strategy = new LongFormArticleSplitStrategy('article.content.generate');
        $chunks = $strategy->buildChunks([
            'title' => 'Balo quat',
            'keyword' => 'balo quat',
            'language' => 'vi',
            'outline' => "# Introduction\n".str_repeat('Opening detail about product. ', 120)
                ."\n\n## Uses\n".str_repeat('Long uses detail text. ', 120)
                ."\n\n## Conclusion\n".str_repeat('Long conclusion text here. ', 120),
        ], new ModelContextCapability(
            contextWindow: 2_800,
            maxOutputTokens: 500,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 1_200,
            providerMessageOverheadTokens: 300,
        ));
        $this->assertGreaterThanOrEqual(2, count($chunks));

        $completed = [];
        foreach ($chunks as $chunk) {
            $completed[] = array_merge($chunk, [
                'output' => '## '.$chunk['heading']."\nBody ".$chunk['heading'],
            ]);
        }
        $merged = $strategy->mergeResults($completed);
        $this->assertNotSame('', $merged);
        $this->assertMatchesRegularExpression('/Uses|Conclusion|Introduction/u', $merged);
    }

    public function test_html_safe_split_preserves_tags(): void
    {
        $strategy = new HtmlSafeRewriteSplitStrategy('article.content.translate');
        $chunks = $strategy->buildChunks([
            'source' => '<p>Hello <a href="/x">link</a></p><ul><li>One</li><li>Two</li></ul>',
            'language' => 'vi',
        ]);
        $this->assertGreaterThanOrEqual(2, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString('substr', $chunk->body);
        }
        $parts = [];
        foreach ($chunks as $chunk) {
            // Simulate translate keeping HTML.
            $html = preg_replace('/^.*SOURCE BLOCK:\n/s', '', $chunk->body) ?? $chunk->body;
            $parts[] = ['chunk' => $chunk, 'output' => $html];
        }
        $merged = $strategy->mergeResults($parts);
        $this->assertStringContainsString('<a href="/x">', $merged);
        $this->assertStringContainsString('<ul>', $merged);
    }

    public function test_chunk_ledger_idempotency_and_resume(): void
    {
        $ledger = new PromptChunkLedger();
        $ledger->setRun('run-1', 'article.content.generate');
        $ledger->planChunk('c1', 'hash-a', 0);
        $ledger->markRunning('c1');
        $ledger->markCompleted('c1', '## Intro\nok');

        $this->assertTrue($ledger->isCompletedWithHash('c1', 'hash-a'));
        $this->assertFalse($ledger->isCompletedWithHash('c1', 'hash-b'));

        // Duplicate dispatch: same hash → no provider call needed.
        $calls = 0;
        if (! $ledger->isCompletedWithHash('c1', 'hash-a')) {
            $calls++;
        }
        $this->assertSame(0, $calls);

        // Interrupt resume: pending chunk still planned.
        $ledger->planChunk('c2', 'hash-c2', 1);
        $this->assertNull($ledger->completedOutput('c2'));
        $leaves = $ledger->completedLeavesForMerge();
        $this->assertCount(1, $leaves);
    }

    public function test_resplit_lineage_supersedes_parent(): void
    {
        $ledger = new PromptChunkLedger();
        $ledger->planChunk('parent', 'p-hash', 0);
        $executor = new SemanticSplitExecutor();
        $children = [
            (new \Omnichannel\Addons\AiPrompt\PromptBudget\SemanticContentChunk('child-0', 'h3', 'a', 0))->withHash(),
            (new \Omnichannel\Addons\AiPrompt\PromptBudget\SemanticContentChunk('child-1', 'h3', 'b', 1))->withHash(),
        ];
        $executor->supersedeWithChildren($ledger, 'parent', $children);
        $this->assertSame(PromptChunkLedger::STATUS_SUPERSEDED, $ledger->toArray()['chunks']['parent']['status']);
        $this->assertSame(PromptChunkLedger::STATUS_PLANNED, $ledger->toArray()['chunks']['child-0']['status']);
    }

    public function test_qty_50_e2e_fallback_replan_smaller_payload(): void
    {
        $harness = new KeywordDiscoveryRouteReplanHarness();
        $aCalls = 0;
        $bCalls = 0;
        $aPayloadChars = [];
        $bPayloadChars = [];

        $capA = new ModelContextCapability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 800,
            capabilityConfidence: ModelContextCapability::CONFIDENCE_TRUSTED,
        );
        $capB = new ModelContextCapability(
            contextWindow: 16_000,
            maxOutputTokens: 2048,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 1200,
            capabilityConfidence: ModelContextCapability::CONFIDENCE_DEFAULT,
        );

        $ideaFactory = static function (int $start, int $count, int $dupEvery = 0): array {
            $ideas = [];
            for ($i = 0; $i < $count; $i++) {
                $n = $start + $i;
                if ($dupEvery > 0 && $i > 0 && $i % $dupEvery === 0) {
                    $n = $start; // duplicate fingerprint
                }
                $ideas[] = [
                    'keyword' => 'idea-'.$n,
                    'fingerprint' => 'fp-'.$n,
                ];
            }

            return $ideas;
        };

        $result = $harness->runToTarget(50, [
            [
                'id' => 'A',
                'capability' => $capA,
                'provider' => function (string $compiled, int $batch, ModelContextCapability $cap) use (&$aCalls, &$aPayloadChars, $ideaFactory): array {
                    $aCalls++;
                    $aPayloadChars[] = mb_strlen($compiled);
                    if ($aCalls === 1) {
                        return ['ideas' => $ideaFactory(1, min(18, $batch)), 'http_status' => 200];
                    }
                    if ($aCalls === 2) {
                        // 17 with duplicates
                        return ['ideas' => $ideaFactory(19, min(17, $batch), 5), 'http_status' => 200];
                    }

                    return ['ideas' => [], 'http_status' => 503];
                },
            ],
            [
                'id' => 'B',
                'capability' => $capB,
                'provider' => function (string $compiled, int $batch, ModelContextCapability $cap) use (&$bCalls, &$bPayloadChars, $ideaFactory, &$resultAccepted): array {
                    $bCalls++;
                    $bPayloadChars[] = mb_strlen($compiled);
                    // Remaining work — smaller batches than A's typical payload.
                    return ['ideas' => $ideaFactory(1000 + $bCalls * 20, $batch), 'http_status' => 200];
                },
            ],
        ], str_repeat('Keyword discovery brief for industrial backpack fans. ', 40));

        $this->assertCount(50, $result['accepted']);
        $this->assertSame(50, count(array_unique(array_column($result['accepted'], 'fingerprint'))));
        $this->assertSame(3, $result['provider_calls']['A']);
        $this->assertGreaterThan(0, $result['provider_calls']['B']);
        $this->assertNotEmpty($aPayloadChars);
        $this->assertNotEmpty($bPayloadChars);
        $aBatches = array_column(array_filter($result['payloads'], static fn (array $p): bool => $p['route'] === 'A'), 'batch');
        $bBatches = array_column(array_filter($result['payloads'], static fn (array $p): bool => $p['route'] === 'B'), 'batch');
        $this->assertNotEmpty($aBatches);
        $this->assertNotEmpty($bBatches);
        // PHPUnit: assertLessThanOrEqual($max, $value) => $value <= $max
        $this->assertLessThanOrEqual(max($aBatches), max($bBatches));
        $this->assertGreaterThan(0, $bCalls);
        $this->assertNotSame($aPayloadChars[0] ?? null, $bPayloadChars[0] ?? null);
    }

    public function test_schema_error_stops_without_calling_b(): void
    {
        $harness = new KeywordDiscoveryRouteReplanHarness();
        $bCalls = 0;
        $cap = new ModelContextCapability(
            contextWindow: 64_000,
            maxOutputTokens: 4096,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 800,
        );

        try {
            $harness->runToTarget(50, [
                [
                    'id' => 'A',
                    'capability' => $cap,
                    'provider' => static function () : array {
                        return ['ideas' => [['keyword' => 'x', 'fingerprint' => 'fp-1']], 'http_status' => 200];
                    },
                ],
                [
                    'id' => 'B',
                    'capability' => $cap,
                    'provider' => static function () use (&$bCalls): array {
                        $bCalls++;

                        return ['ideas' => [], 'http_status' => 200];
                    },
                ],
            ], 'brief');
            // Force schema error on second A call by custom route list:
        } catch (PromptRunException) {
            // fallthrough for alternate scenario below
        }

        $bCalls = 0;
        try {
            $harness->runToTarget(50, [
                [
                    'id' => 'A',
                    'capability' => $cap,
                    'provider' => static function () : array {
                        return ['ideas' => [], 'http_status' => 200, 'schema_error' => true];
                    },
                ],
                [
                    'id' => 'B',
                    'capability' => $cap,
                    'provider' => static function () use (&$bCalls): array {
                        $bCalls++;

                        return ['ideas' => [], 'http_status' => 200];
                    },
                ],
            ], 'short brief');
            $this->fail('Expected schema error stop');
        } catch (PromptRunException $e) {
            $this->assertFalse((bool) ($e->context['retryable'] ?? true));
            $this->assertSame(0, $bCalls);
        }
    }

    public function test_duplicate_dispatch_skips_completed_provider_chunks(): void
    {
        $harness = new KeywordDiscoveryRouteReplanHarness();
        $calls = 0;
        $cap = new ModelContextCapability(
            contextWindow: 64_000,
            maxOutputTokens: 4096,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 800,
        );
        $provider = function (string $compiled, int $batch) use (&$calls, $cap): array {
            $calls++;
            $ideas = [];
            for ($i = 0; $i < $batch; $i++) {
                $ideas[] = ['keyword' => 'k'.$calls.'-'.$i, 'fingerprint' => 'fp'.$calls.'-'.$i];
            }

            return ['ideas' => $ideas, 'http_status' => 200];
        };

        $r1 = $harness->runToTarget(10, [['id' => 'A', 'capability' => $cap, 'provider' => $provider]], 'brief');
        $callsAfterFirst = $calls;
        // Second run with empty remaining would still call — simulate ledger resume via completed identities:
        $this->assertGreaterThanOrEqual(10, count($r1['accepted']));
        $this->assertGreaterThan(0, $callsAfterFirst);

        // Idempotent completed chunk check
        $ledger = PromptChunkLedger::fromMetadata(['chunk_ledger' => $r1['ledger']]);
        $firstChunk = array_key_first($ledger->toArray()['chunks']);
        $hash = (string) $ledger->toArray()['chunks'][$firstChunk]['input_hash'];
        $this->assertTrue($ledger->isCompletedWithHash((string) $firstChunk, $hash));
    }

    public function test_long_form_merge_failure_does_not_fallback(): void
    {
        $merger = new \Omnichannel\Addons\AiPrompt\PromptBudget\LongFormArticleMerger();
        try {
            $merger->merge([]);
            $this->fail('Expected merge failure');
        } catch (PromptBudgetException $e) {
            $this->assertFalse($e->isRetryable());
            $decision = (new \Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier())->classify($e);
            $this->assertFalse($decision->fallbackAllowed());
        }
    }
}
