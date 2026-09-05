<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use PHPUnit\Framework\TestCase;

final class OpenRouterTextRoutingCatalogTest extends TestCase
{
    public function test_curated_models_map_to_distinct_families(): void
    {
        $catalog = new AiModelFamilyCatalog();
        $expected = [
            'openai/gpt-5.4' => 'openai.gpt54',
            'openai/gpt-5.4-mini' => 'openai.gpt54_mini',
            'openai/gpt-5.4-nano' => 'openai.gpt54_nano',
            'anthropic/claude-sonnet-4.6' => 'claude.sonnet',
            'anthropic/claude-haiku-4.5' => 'claude.haiku',
            'google/gemini-3.5-flash' => 'gemini.flash',
            'google/gemini-3.5-flash-lite' => 'gemini.flash_lite',
            'google/gemini-3.1-pro-preview' => 'gemini.pro',
            'deepseek/deepseek-v3.2' => 'deepseek.v32',
            'qwen/qwen3.6-flash' => 'qwen.flash',
        ];
        foreach ($expected as $raw => $familyKey) {
            $family = $catalog->familyForModelId($raw);
            $this->assertNotNull($family, $raw);
            $this->assertSame($familyKey, $family->familyKey, $raw);
        }
        $this->assertNotSame(
            $catalog->familyForModelId('google/gemini-3.5-flash')?->familyKey,
            $catalog->familyForModelId('google/gemini-3.5-flash-lite')?->familyKey,
        );
    }

    public function test_profile_model_lists_match_product_spec(): void
    {
        $this->assertSame([
            'openai/gpt-5.4-nano',
            'openai/gpt-5.4-mini',
            'anthropic/claude-haiku-4.5',
            'google/gemini-3.5-flash-lite',
            'google/gemini-3.5-flash',
            'deepseek/deepseek-v3.2',
            'qwen/qwen3.6-flash',
        ], OpenRouterTextRoutingCatalog::PROFILE_MODELS[AiExecutionProfile::TextFast->value]);

        $this->assertSame([
            'anthropic/claude-sonnet-4.6',
            'google/gemini-3.5-flash',
            'openai/gpt-5.4-mini',
            'openai/gpt-5.4',
            'deepseek/deepseek-v3.2',
            'qwen/qwen3.6-flash',
        ], OpenRouterTextRoutingCatalog::PROFILE_MODELS[AiExecutionProfile::TextLongform->value]);

        $this->assertSame([
            'openai/gpt-5.4',
            'anthropic/claude-sonnet-4.6',
            'google/gemini-3.1-pro-preview',
            'google/gemini-3.5-flash',
            'openai/gpt-5.4-mini',
            'deepseek/deepseek-v3.2',
        ], OpenRouterTextRoutingCatalog::PROFILE_MODELS[AiExecutionProfile::TextReasoning->value]);

        $this->assertCount(10, OpenRouterTextRoutingCatalog::MODELS);
        $this->assertSame('GPT-5.4 Mini', OpenRouterTextRoutingCatalog::MODELS['openai/gpt-5.4-mini']);

        $this->assertSame(
            [
                \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextFast,
                \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextLongform,
                \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextReasoning,
            ],
            OpenRouterTextRoutingCatalog::membershipAreasForRaw('google/gemini-3.5-flash'),
        );
        $this->assertSame(
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextReasoning,
            OpenRouterTextRoutingCatalog::primaryAreaForRaw('openai/gpt-5.4'),
        );
        $this->assertContains(
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextReasoning,
            OpenRouterTextRoutingCatalog::membershipAreasForRaw('openai/gpt-5.4'),
        );
    }
}
