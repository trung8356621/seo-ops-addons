<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionModelAttribution;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategySnapshot;
use Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter;
use PHPUnit\Framework\TestCase;

final class AiExecutionModelAttributionHistoryTest extends TestCase
{
    public function test_history_display_prefers_candidate_over_requested_claude(): void
    {
        $attr = AiExecutionModelAttribution::fromPersistence([
            'requested_model' => 'anthropic/claude-sonnet-4.6',
            'candidate_model' => 'nvidia/nemotron-test:free',
            'actual_provider_model' => null,
            'is_free_candidate' => true,
        ]);

        self::assertSame('nvidia/nemotron-test:free', $attr->displayModel());
        self::assertSame(AiExecutionModelAttribution::SOURCE_ROUTING_CANDIDATE, $attr->displayModelSource());

        $compact = ArticleAiHistoryCardPresenter::compactModel(
            $attr->displayModel(),
            $attr->isFreeCandidate,
        );
        self::assertSame('nemotron-test · free', $compact);
        self::assertStringNotContainsString('claude', strtolower($compact));
    }

    public function test_history_display_unknown_when_only_requested_present(): void
    {
        $attr = AiExecutionModelAttribution::fromPersistence([
            'requested_model' => 'anthropic/claude-sonnet-4.6',
            'candidate_model' => null,
            'actual_provider_model' => null,
            // Explicitly no legacy raw_model_used
        ]);

        self::assertSame('Unknown model', $attr->displayModel());
        self::assertSame(AiExecutionModelAttribution::SOURCE_UNKNOWN, $attr->displayModelSource());
        self::assertSame(
            'Unknown model',
            ArticleAiHistoryCardPresenter::compactModel($attr->displayModel()),
        );
    }

    public function test_actual_provider_model_wins_over_candidate(): void
    {
        $attr = AiExecutionModelAttribution::fromProviderAttempt(
            requestedModel: 'anthropic/claude-sonnet-4.6',
            candidateModel: 'nvidia/nemotron-test:free',
            isFreeCandidate: true,
            provider: 'openrouter',
            connectionId: 1,
            usage: ['resolved_model' => 'nvidia/nemotron-test:free'],
            attempt: 2,
            status: 'SUCCESS',
        );

        self::assertSame('nvidia/nemotron-test:free', $attr->displayModel());
        self::assertSame(AiExecutionModelAttribution::SOURCE_PROVIDER_RESPONSE, $attr->displayModelSource());
        self::assertSame('anthropic/claude-sonnet-4.6', $attr->requestedModel);
    }

    public function test_strategy_snapshot_null_override_is_default_single_pass(): void
    {
        $snap = ArticleGenerationStrategySnapshot::fromVariables([], null);

        self::assertNull($snap->strategyOverride);
        self::assertSame('single_pass', $snap->strategyResolved);
        self::assertSame(ArticleGenerationStrategySnapshot::SOURCE_DEFAULT, $snap->strategySource);

        $vars = $snap->mergeIntoVariables([]);
        self::assertNull($vars['strategy_override']);
        self::assertSame('single_pass', $vars['strategy_resolved']);
    }

    public function test_strategy_snapshot_task_override_sectioned_free(): void
    {
        $snap = ArticleGenerationStrategySnapshot::fromVariables([], 'sectioned_free');

        self::assertSame('sectioned_free', $snap->strategyOverride);
        self::assertSame('sectioned_free', $snap->strategyResolved);
        self::assertSame(ArticleGenerationStrategySnapshot::SOURCE_TASK_OVERRIDE, $snap->strategySource);
    }

    public function test_compact_model_uses_is_free_candidate_not_only_string_suffix(): void
    {
        self::assertSame(
            'nemotron-test · free',
            ArticleAiHistoryCardPresenter::compactModel('nvidia/nemotron-test', true),
        );
        self::assertSame(
            'claude-sonnet-4.6',
            ArticleAiHistoryCardPresenter::compactModel('anthropic/claude-sonnet-4.6', false),
        );
    }
}
