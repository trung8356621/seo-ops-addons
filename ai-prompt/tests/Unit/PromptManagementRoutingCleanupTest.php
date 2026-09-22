<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use ReflectionClass;
use Tests\TestCase;

final class PromptManagementRoutingCleanupTest extends TestCase
{
    public function test_prompt_level_override_wins_over_hook_default(): void
    {
        $prompt = new SeoPrompt;
        $prompt->hook_key = 'seo_audit.discover_new_topics';
        $prompt->routing_mode = 'override';
        $prompt->routing_profile_key = AiExecutionProfile::TextLongform->value;
        $prompt->tools = 'default';

        $resolved = (new PromptExecutionProfileResolver)->resolve($prompt);

        $this->assertSame(AiExecutionProfile::TextLongform, $resolved);
    }

    public function test_empty_override_falls_back_to_hook_default(): void
    {
        $prompt = new SeoPrompt;
        $prompt->hook_key = 'seo_audit.discover_new_topics';
        $prompt->routing_mode = 'auto';
        $prompt->routing_profile_key = null;
        $prompt->tools = 'default';

        $this->assertSame(
            AiExecutionProfile::TextReasoning,
            (new PromptExecutionProfileResolver)->resolve($prompt),
        );
    }

    public function test_core_resolver_maps_registered_hooks_read_only(): void
    {
        $resolver = new PromptExecutionProfileResolver;

        $this->assertSame(
            AiExecutionProfile::TextFast,
            $resolver->resolve(null, 'article.title_suggestion'),
        );
        $this->assertSame(
            AiExecutionProfile::TextLongform,
            $resolver->resolve(null, 'keyword.discovery.structured'),
        );
        $this->assertSame(
            AiExecutionProfile::TextLongform,
            $resolver->resolve(null, 'article.content.generate'),
        );
        $this->assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'seo_audit.discover_new_topics'),
        );
    }

    public function test_unbound_prompt_honors_explicit_override_when_set(): void
    {
        $prompt = new SeoPrompt;
        $prompt->hook_key = null;
        $prompt->tools = 'default';
        $prompt->routing_mode = 'override';
        $prompt->routing_profile_key = AiExecutionProfile::TextLongform->value;

        $this->assertSame(
            AiExecutionProfile::TextLongform,
            (new PromptExecutionProfileResolver)->resolve($prompt),
        );
    }

    public function test_prompt_form_exposes_profile_selector_without_model_provider_controls(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(PromptResource::class))->getFileName());

        $this->assertStringNotContainsString("Select::make('settings.routing_family_key')", $source);
        $this->assertStringNotContainsString("Radio::make('settings.usage_mode')", $source);
        $this->assertStringNotContainsString("Radio::make('routing_mode')", $source);
        $this->assertStringContainsString("Select::make('routing_profile_key')", $source);
        $this->assertStringContainsString('execution_profile_use_hook_default', $source);
        $this->assertStringNotContainsString("Select::make('ai_connection_id')", $source);
        $this->assertStringNotContainsString('resolvedRoutingSummary', $source);
        $this->assertStringContainsString('currentVersion.version_label', $source);
        $this->assertStringContainsString("label(__('seo-content-ai::filament.prompt.version'))", $source);
        $this->assertStringNotContainsString("TextColumn::make('updated_at')", $source);
        $posVersion = strpos($source, "TextColumn::make('currentVersion.version_label')");
        $posName = strpos($source, "TextColumn::make('name')");
        $this->assertNotFalse($posVersion);
        $this->assertNotFalse($posName);
        $this->assertLessThan($posName, $posVersion);
        $this->assertStringContainsString('PromptExecutionProfileResolver', $source);
        $this->assertStringContainsString('SeoSettingsAiCenter::getUrl', $source);
    }

    public function test_execution_profile_display_uses_hook_default(): void
    {
        $html = (string) PromptResource::executionProfileDisplayHtml(
            'keyword.discovery.structured',
            'default',
        );

        $this->assertStringContainsString(AiExecutionProfile::TextLongform->displayName(), $html);
        $this->assertStringNotContainsString(AiExecutionProfile::TextReasoning->displayName(), $html);
        $this->assertTrue(
            str_contains($html, 'AI Center') || str_contains($html, 'Prompt Hook') || str_contains($html, 'Hook'),
            'Expected execution profile helper copy in display HTML.',
        );
        $this->assertStringNotContainsString('1. ', $html);
    }

    public function test_profile_display_for_fast_and_longform_hooks(): void
    {
        $fast = (string) PromptResource::executionProfileDisplayHtml('article.faq.generate', 'default');
        $long = (string) PromptResource::executionProfileDisplayHtml('article.content.generate', 'default');

        $this->assertStringContainsString(AiExecutionProfile::TextFast->displayName(), $fast);
        $this->assertStringContainsString(AiExecutionProfile::TextLongform->displayName(), $long);
    }
}
