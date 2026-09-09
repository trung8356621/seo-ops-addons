<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Support\ArticleContentGenerationHooks;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\ArticleModelOrderAuthority;
use Omnichannel\Addons\AiPrompt\Support\ArticlePrimaryRoutingSnapshot;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationRoutingPreference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Real-behavior tests: AI Center order authority for article.content.generate.
 */
final class ArticleModelOrderAuthorityTest extends TestCase
{
    public function test_generation_mode_reorder_forbidden_for_article_hooks(): void
    {
        self::assertFalse(ArticleModelOrderAuthority::allowsGenerationModeReorder(
            ArticleContentGenerationHooks::GENERATE,
        ));
        self::assertFalse(ArticleModelOrderAuthority::allowsGenerationModeReorder(
            ArticleContentGenerationHooks::REWRITE,
        ));
        self::assertFalse(ArticleModelOrderAuthority::allowsGenerationModeReorder('keyword.discovery'));
        self::assertFalse(ArticleModelOrderAuthority::allowsGenerationModeReorder(null));
    }

    public function test_a_null_mode_preserves_ai_center_order_and_primary_claude(): void
    {
        $ordered = $this->applyPreferences($this->aiCenterTrio(), null, ArticleContentGenerationHooks::GENERATE);
        self::assertSame(['claude', 'nemotron', 'gpt-mini'], $this->models($ordered));
        self::assertSame('claude', $ordered[0]->model);
        self::assertFalse($ordered[0]->isFree);
    }

    public function test_b_fast_economy_does_not_reorder_article_candidates(): void
    {
        $ordered = $this->applyPreferences(
            $this->aiCenterTrio(),
            ItemGenerationMode::FastEconomy->value,
            ArticleContentGenerationHooks::GENERATE,
        );
        self::assertSame(['claude', 'nemotron', 'gpt-mini'], $this->models($ordered));
        self::assertSame('claude', $ordered[0]->model);

        // Non-article hooks also preserve order (manual sortable wins everywhere).
        $nonArticle = ItemGenerationRoutingPreference::orderCandidates(
            $this->aiCenterTrio(),
            ItemGenerationMode::FastEconomy,
        );
        self::assertSame(['claude', 'nemotron', 'gpt-mini'], $this->models($nonArticle));
    }

    public function test_c_best_quality_does_not_reorder_article_candidates(): void
    {
        $ordered = $this->applyPreferences(
            $this->aiCenterTrio(),
            ItemGenerationMode::BestQuality->value,
            ArticleContentGenerationHooks::GENERATE,
        );
        self::assertSame(['claude', 'nemotron', 'gpt-mini'], $this->models($ordered));
        self::assertSame('claude', $ordered[0]->model);

        $nonArticle = ItemGenerationRoutingPreference::orderCandidates(
            $this->aiCenterTrio(),
            ItemGenerationMode::BestQuality,
        );
        self::assertSame(['claude', 'nemotron', 'gpt-mini'], $this->models($nonArticle));
    }

    public function test_d_explicit_preferred_override_prepends_nemotron(): void
    {
        $ordered = $this->applyPreferences(
            $this->aiCenterTrio(),
            ItemGenerationMode::FastEconomy->value,
            ArticleContentGenerationHooks::GENERATE,
            preferredModelId: 2,
            requirePreferred: false,
        );
        self::assertSame(['nemotron', 'claude', 'gpt-mini'], $this->models($ordered));
    }

    public function test_e_explicit_required_override_only_nemotron_eligible(): void
    {
        $ordered = $this->applyPreferences(
            $this->aiCenterTrio(),
            null,
            ArticleContentGenerationHooks::GENERATE,
            preferredModelId: 2,
            requirePreferred: true,
        );
        // applyItemRoutingPreferences validates presence + prepends; executeWithProfile then filters.
        $eligible = array_values(array_filter(
            $ordered,
            static fn (RoutedAiCandidate $c): bool => (int) ($c->seoAiModelId ?? 0) === 2,
        ));
        self::assertSame(['nemotron'], $this->models($eligible));

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AiModelRouterService::class))->getFileName(),
        );
        $execPos = strpos($src, 'function executeWithProfile');
        self::assertNotFalse($execPos);
        $slice = substr($src, $execPos, 2500);
        self::assertStringContainsString('requirePreferredModel', $slice);
        self::assertStringContainsString('seoAiModelId', $slice);
    }

    public function test_f_free_only_filter_preserves_relative_order(): void
    {
        $aiCenter = [
            $this->candidate('claude', false, 1),
            $this->candidate('nemotron', true, 2),
            $this->candidate('gpt-mini', false, 3),
            $this->candidate('another-free', true, 4),
        ];
        // Simulate Free Only / paid_locked filter (skip paid, keep relative free order).
        $remaining = array_values(array_filter(
            $aiCenter,
            static fn (RoutedAiCandidate $c): bool => $c->isFree,
        ));
        self::assertSame(['nemotron', 'another-free'], $this->models($remaining));
    }

    public function test_g_health_skip_preserves_relative_order_of_remainder(): void
    {
        $aiCenter = $this->aiCenterTrio();
        // Claude skipped by health → Nemotron, GPT Mini.
        $remaining = array_values(array_filter(
            $aiCenter,
            static fn (RoutedAiCandidate $c): bool => $c->model !== 'claude',
        ));
        self::assertSame(['nemotron', 'gpt-mini'], $this->models($remaining));
    }

    public function test_h_snapshot_does_not_fabricate_item_model_override(): void
    {
        $primary = $this->candidate('claude', false, 11);
        $vars = ArticlePrimaryRoutingSnapshot::fromCandidate(
            $primary,
            ArticleGenerationShape::SinglePass,
        )->mergeIntoVariables([]);

        self::assertSame('11', $vars['_article_primary_model_id']);
        self::assertSame(11, $vars['primary_model_id']);
        self::assertSame('claude', $vars['primary_model']);
        self::assertFalse($vars['primary_is_free']);
        self::assertArrayNotHasKey('_item_model_override_id', $vars);
        self::assertArrayNotHasKey('_item_model_override_mode', $vars);
    }

    public function test_i_snapshot_preserves_existing_user_override(): void
    {
        $primary = $this->candidate('claude', false, 11);
        $vars = ArticlePrimaryRoutingSnapshot::fromCandidate(
            $primary,
            ArticleGenerationShape::SinglePass,
        )->mergeIntoVariables([
            '_item_model_override_id' => '2',
            '_item_model_override_mode' => 'preferred',
        ]);

        self::assertSame('2', $vars['_item_model_override_id']);
        self::assertSame('preferred', $vars['_item_model_override_mode']);
        self::assertSame('11', $vars['_article_primary_model_id']);
    }

    public function test_required_missing_model_throws(): void
    {
        $this->expectException(\Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException::class);
        $this->applyPreferences(
            $this->aiCenterTrio(),
            null,
            ArticleContentGenerationHooks::GENERATE,
            preferredModelId: 999,
            requirePreferred: true,
        );
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    private function applyPreferences(
        array $candidates,
        ?string $generationMode,
        ?string $hookKey,
        ?int $preferredModelId = null,
        bool $requirePreferred = false,
    ): array {
        $router = (new ReflectionClass(AiModelRouterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiModelRouterService::class, 'applyItemRoutingPreferences');
        $method->setAccessible(true);

        $context = new AiRoutingContext(
            userId: 1,
            preferredModelId: $preferredModelId,
            requirePreferredModel: $requirePreferred,
            itemGenerationMode: $generationMode,
            hookKey: $hookKey,
        );

        /** @var list<RoutedAiCandidate> $result */
        $result = $method->invoke($router, $candidates, $context);

        return $result;
    }

    /**
     * @return list<RoutedAiCandidate>
     */
    private function aiCenterTrio(): array
    {
        return [
            $this->candidate('claude', false, 1),
            $this->candidate('nemotron', true, 2),
            $this->candidate('gpt-mini', false, 3),
        ];
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<string>
     */
    private function models(array $candidates): array
    {
        return array_map(static fn (RoutedAiCandidate $c): string => $c->model, $candidates);
    }

    private function candidate(string $model, bool $isFree, int $id): RoutedAiCandidate
    {
        $connection = new ApiConnection([
            'id' => 1,
            'name' => 'openrouter',
            'provider' => 'openrouter',
            'paid_locked' => false,
        ]);
        $connection->id = 1;

        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: $model,
            capabilities: [],
            priority: $id,
            isFree: $isFree,
            seoAiModelId: $id,
        );
    }
}
