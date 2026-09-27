<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use PHPUnit\Framework\TestCase;

final class DecisionModelGroupTest extends TestCase
{
    public function test_decision_capabilities_are_outside_text_generation(): void
    {
        $registry = new ModelCapabilityRegistry();
        foreach (['jev', 'laya', 'acme/jev', 'vendor/laya'] as $model) {
            $caps = $registry->capabilitiesFor('openai', $model);
            self::assertContains(AiModelCapability::DecisionChoice->value, $caps, $model);
            self::assertContains(AiModelCapability::DecisionScore->value, $caps, $model);
            self::assertContains(AiModelCapability::DecisionProbability->value, $caps, $model);
            self::assertNotContains(AiModelCapability::TextGenerate->value, $caps, $model);
            self::assertNotContains(AiModelCapability::TextReasoning->value, $caps, $model);
        }

        $text = $registry->capabilitiesFor('deepseek', 'deepseek-flash');
        self::assertNotContains(AiModelCapability::DecisionChoice->value, $text);
        self::assertContains(AiModelCapability::TextGenerate->value, $text);
    }

    public function test_decision_profile_is_its_own_group(): void
    {
        $profile = AiExecutionProfile::DecisionRoute;
        self::assertSame('decision', $profile->group());
        self::assertTrue($profile->isDecision());
        self::assertFalse($profile->isMedia());
        self::assertTrue($profile->usesDedicatedAreaLane());
        self::assertSame(AiModelArea::Decision, AiModelArea::fromProfile($profile));
        self::assertNotContains($profile, AiExecutionProfile::inGroup('text'));
        self::assertSame('Decision Models', $this->label());
    }

    public function test_decision_families_are_not_text_families(): void
    {
        $catalog = new AiModelFamilyCatalog();
        self::assertSame('decision', $catalog->familyForModelId('jev')?->modality);
        self::assertSame('decision', $catalog->familyForModelId('vendor/laya')?->modality);
        self::assertSame('decision.jev', $catalog->aggregatorFamily('jev')?->familyKey);
    }

    public function test_answer_hook_is_not_a_prompt_admin_record(): void
    {
        $resolver = new PromptExecutionProfileResolver();
        self::assertArrayNotHasKey('agent.runtime.answer', $resolver->hookMap());
        self::assertArrayNotHasKey('agent.runtime.routing', $resolver->hookMap());
    }

    private function label(): string
    {
        $en = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/lang/en/filament.php');
        self::assertStringContainsString("'tab_decision' => 'Decision Models'", $en);

        return 'Decision Models';
    }
}
