<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadiness;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadinessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class NewContentGenerationReadinessTest extends TestCase
{
    public function test_ready_snapshot_shape_and_legacy_prompt_routing_ignored_by_core_profile(): void
    {
        $prompt = new SeoPrompt;
        $prompt->hook_key = NewContentGenerationReadiness::HOOK_KEY;
        $prompt->routing_mode = 'override';
        $prompt->routing_profile_key = 'text.longform';
        $prompt->settings = ['usage_mode' => 'economy', 'routing_family_key' => 'claude.opus'];
        $prompt->ai_connection_id = null;

        $readiness = new NewContentGenerationReadiness(
            ready: true,
            quantityEnabled: true,
            generateEnabled: true,
            draft: ['ready' => true, 'reason' => null],
            language: ['ready' => true, 'value' => 'vi', 'reason' => null],
            prompt: [
                'ready' => true,
                'hook' => NewContentGenerationReadiness::HOOK_KEY,
                'prompt_id' => 7,
                'prompt_name' => 'Content Planning Assistant',
                'reason' => null,
            ],
            profile: [
                'value' => AiExecutionProfile::TextReasoning->value,
                'label' => AiExecutionProfile::TextReasoning->displayName(),
            ],
            generation: ['active' => false, 'status' => null, 'run_id' => null, 'reason' => null],
            permission: ['ready' => true, 'reason' => null],
            blockReasons: [],
        );

        self::assertTrue($readiness->ready);
        self::assertTrue($readiness->generateEnabled);
        self::assertTrue($readiness->quantityEnabled);
        self::assertSame('vi', $readiness->language['value']);
        self::assertSame('Content Planning Assistant', $readiness->prompt['prompt_name']);
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            (new PromptExecutionProfileResolver)->resolve($prompt, NewContentGenerationReadiness::HOOK_KEY),
        );
    }

    public function test_missing_prompt_keeps_quantity_enabled_but_blocks_generate(): void
    {
        $readiness = new NewContentGenerationReadiness(
            ready: false,
            quantityEnabled: true,
            generateEnabled: false,
            draft: ['ready' => true, 'reason' => null],
            language: ['ready' => true, 'value' => 'vi', 'reason' => null],
            prompt: [
                'ready' => false,
                'hook' => NewContentGenerationReadiness::HOOK_KEY,
                'prompt_id' => null,
                'prompt_name' => null,
                'reason' => 'Keyword Discovery prompt is not configured.',
            ],
            profile: ['value' => AiExecutionProfile::TextReasoning->value, 'label' => 'Reasoning Text'],
            generation: ['active' => false, 'status' => null, 'run_id' => null, 'reason' => null],
            permission: ['ready' => true, 'reason' => null],
            blockReasons: ['Keyword Discovery prompt is not configured.'],
        );

        self::assertFalse($readiness->generateEnabled);
        self::assertTrue($readiness->quantityEnabled);
        self::assertSame(
            ['Keyword Discovery prompt is not configured.'],
            $readiness->blockReasons,
        );
    }

    public function test_queued_run_disables_generate_and_quantity(): void
    {
        $readiness = new NewContentGenerationReadiness(
            ready: false,
            quantityEnabled: false,
            generateEnabled: false,
            draft: ['ready' => true, 'reason' => null],
            language: ['ready' => true, 'value' => 'vi', 'reason' => null],
            prompt: [
                'ready' => true,
                'hook' => NewContentGenerationReadiness::HOOK_KEY,
                'prompt_id' => 1,
                'prompt_name' => 'Content Planning Assistant',
                'reason' => null,
            ],
            profile: ['value' => AiExecutionProfile::TextReasoning->value, 'label' => 'Reasoning Text'],
            generation: [
                'active' => true,
                'status' => 'queued',
                'run_id' => 44,
                'reason' => 'Generation is already queued.',
            ],
            permission: ['ready' => true, 'reason' => null],
            blockReasons: ['Generation is already queued.'],
        );

        self::assertFalse($readiness->generateEnabled);
        self::assertFalse($readiness->quantityEnabled);
    }

    public function test_service_uses_canonical_resolvers_not_legacy_prompt_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentGenerationReadinessService::class))->getFileName(),
        );
        self::assertStringContainsString('SettingsPromptBindingResolver', $src);
        self::assertStringContainsString('PromptExecutionProfileResolver', $src);
        self::assertStringContainsString('resolvePrimaryLanguage', $src);
        self::assertStringContainsString('reconcileStaleActiveRun', $src);
        self::assertStringNotContainsString('SeoPrompt::query()', $src);
        self::assertStringNotContainsString('routing_family_key', $src);
        self::assertStringNotContainsString('usage_mode', $src);
        self::assertStringNotContainsString('ai_connection_id', $src);
        self::assertStringNotContainsString("['focus']", $src);
        self::assertStringNotContainsString("['taxonomy']", $src);
        self::assertStringNotContainsString("['notes']", $src);
        self::assertStringNotContainsString('getMeta(', $src);
    }

    public function test_ui_and_trait_use_readiness_flags(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        self::assertStringContainsString('quantity_enabled', $card);
        self::assertStringContainsString('generate_enabled', $card);
        self::assertStringContainsString('block_reasons', $card);
        self::assertStringContainsString('data-new-content-readiness="blocked"', $card);
        self::assertStringNotContainsString('! $canWrite || $isGenerating', $card);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('NewContentGenerationReadinessService', $trait);
        self::assertStringContainsString('generate_enabled', $trait);
        self::assertStringContainsString('quantity_enabled', $trait);
        self::assertStringNotContainsString(
            'isDraftPlanning() && (bool) $primary[\'primary_configured\']',
            $trait,
        );
        self::assertStringContainsString('reconcileStaleActiveRun', $trait);
    }

    public function test_hook_constant_matches_runtime_discovery_hook(): void
    {
        self::assertSame('keyword.discovery.structured', NewContentGenerationReadiness::HOOK_KEY);
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService::class,
            ))->getFileName(),
        );
        self::assertStringContainsString("hookKey: 'keyword.discovery.structured'", $planner);
        self::assertStringContainsString('getProjectKeywordsPromptId', $planner);
    }
}
