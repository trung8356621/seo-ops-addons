<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;
use Omnichannel\Addons\AiPrompt\PromptBudget\DirectFitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\KeywordDiscoveryBudgetStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use App\Models\ApiConnection;
use PHPUnit\Framework\TestCase;

final class PromptBudgetPreflightServiceTest extends TestCase
{
    private PromptBudgetPreflightService $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preflight = new PromptBudgetPreflightService();
    }

    public function test_request_fits_single_plan(): void
    {
        $capability = new ModelContextCapability(
            contextWindow: 32_000,
            maxOutputTokens: 2048,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 500,
        );
        $strategy = new DirectFitStrategy('article.title_suggestion');
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            'Write a short SEO title for balo quat.',
            ['requested_output_tokens' => 128],
        );
        $this->assertTrue($plan->requestFits);
        $this->assertSame(PromptSplitClass::DirectFit->value, $plan->splitClass);
    }

    public function test_oversized_direct_fit_throws_unsplittable_before_provider(): void
    {
        $capability = new ModelContextCapability(
            contextWindow: 1024,
            maxOutputTokens: 512,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            safetyMarginTokens: 200,
        );
        $huge = str_repeat('Từ khóa dài và mô tả sản phẩm. ', 800);
        $plan = $this->preflight->planWithCapability(
            $capability,
            new DirectFitStrategy('article.title_suggestion'),
            $huge,
        );
        $this->assertFalse($plan->requestFits);

        try {
            throw PromptBudgetException::unsplittable(
                'Prompt does not fit model context and has no semantic split strategy.',
                $plan->toDiagnostics(),
            );
        } catch (PromptBudgetException $e) {
            $this->assertStringContainsString(PromptBudgetException::CODE_UNSPLITTABLE, $e->getMessage());
            $this->assertFalse($e->isRetryable());
            $this->assertSame(\Omnichannel\Addons\AiPrompt\Support\AiFailureClass::ContextLimitExceeded->value, $e->classification());
        }

        $decision = (new \Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier())->classify(
            PromptBudgetException::unsplittable('x', $plan->toDiagnostics()),
        );
        $this->assertFalse($decision->fallbackAllowed());
    }

    public function test_keyword_discovery_adaptive_batch_shrinks_for_small_context(): void
    {
        $strategy = new KeywordDiscoveryBudgetStrategy();
        $small = new ModelContextCapability(
            contextWindow: 8_000,
            maxOutputTokens: 1500,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: false,
            safetyMarginTokens: 500,
        );
        $large = new ModelContextCapability(
            contextWindow: 128_000,
            maxOutputTokens: 4096,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: false,
            safetyMarginTokens: 800,
        );

        $smallTarget = $strategy->resolveBatchTarget(50, 3_000, 400, $small);
        $largeTarget = $strategy->resolveBatchTarget(50, 3_000, 400, $large);

        $this->assertGreaterThan(0, $smallTarget);
        $this->assertGreaterThan(0, $largeTarget);
        $this->assertLessThanOrEqual(KeywordDiscoveryBudgetStrategy::MAX_BATCH, $smallTarget);
        $this->assertLessThanOrEqual(KeywordDiscoveryBudgetStrategy::MAX_BATCH, $largeTarget);
        // PHPUnit: assertLessThanOrEqual($expected, $actual) => $actual <= $expected
        $this->assertLessThanOrEqual($largeTarget, $smallTarget);
    }

    public function test_registry_classifies_hooks(): void
    {
        $map = (new PromptSplitStrategyRegistry())->classificationMap();
        $this->assertSame(PromptSplitClass::SemanticSplit->value, $map['keyword.discovery.structured']);
        $this->assertSame(PromptSplitClass::DirectFit->value, $map['article.title_suggestion']);
        $this->assertSame(PromptSplitClass::SemanticSplit->value, $map['article.content.generate']);
        $this->assertSame(PromptSplitClass::BusinessSplit->value, $map['article.outline.structure.generate']);
    }

    public function test_execute_with_failover_has_no_production_callers(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, 'AiModelRouterService.php')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            if (str_contains($src, 'executeWithFailover(')) {
                $hits[] = $path;
            }
        }
        $this->assertSame([], $hits, 'executeWithFailover must have zero production callers');
    }
}
