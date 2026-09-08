<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeTrackedProviderCall;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Tests\TestCase;

final class SectionedFreeNonFreeModelInvariantTest extends TestCase
{
    public function test_non_free_candidate_throws_sectioned_free_code(): void
    {
        $connection = new ApiConnection([
            'id' => 99,
            'name' => 'paid-test',
            'provider' => 'openrouter',
        ]);
        $connection->id = 99;

        $routed = new RoutedAiCandidate(
            profile: 'article.generate',
            connection: $connection,
            provider: 'openrouter',
            model: 'anthropic/claude-sonnet-4.6',
            capabilities: [],
            priority: 1,
            isFree: false,
        );

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->expects($this->never())->method('callProviderForSectionedFree');

        $tracked = new SectionedFreeTrackedProviderCall($runner);
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Intro',
            role: SectionedFreeSectionUnit::ROLE_INTRO,
            outlineNodes: [],
            requiredPoints: [],
            targetMinWords: 200,
            targetMaxWords: 400,
            preferredTargetWords: 300,
        );

        $prompt = new SeoPrompt();
        $prompt->id = 1;
        $prompt->hook_key = 'article.content.generate';

        try {
            $tracked->call(
                $routed,
                $prompt,
                'section prompt body',
                [],
                'default',
                $unit,
                1,
                null,
                'sf_test_run',
                [],
            );
            self::fail('Expected SECTIONED_FREE_NON_FREE_MODEL_SELECTED');
        } catch (PromptRunException $exception) {
            self::assertStringContainsString('SECTIONED_FREE_NON_FREE_MODEL_SELECTED', $exception->getMessage());
            self::assertSame('SECTIONED_FREE_NON_FREE_MODEL_SELECTED', $exception->context['failure_code'] ?? null);
            self::assertSame('anthropic/claude-sonnet-4.6', $exception->context['candidate_model'] ?? null);
            self::assertSame(99, $exception->context['connection_id'] ?? null);
            self::assertSame('section_01', $exception->context['section_id'] ?? null);
            self::assertSame('free_only', $exception->context['router_policy'] ?? null);
        }
    }
}
