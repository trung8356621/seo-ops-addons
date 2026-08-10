<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookLiveShadowGate;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookModeTransitionPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionGate;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionThresholds;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRollbackPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeMode;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PromptHookRuntimePhase5D1Test extends TestCase
{
    public function test_sample_aggregation_counts(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        $recorder = new PromptHookShadowParityRecorder;

        $recorder->record([
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'mode' => 'shadow',
            'environment' => 'testing',
            'schema_ok' => true,
            'correlation_id' => 'c1',
        ]);
        $recorder->record([
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'mode' => 'shadow',
            'environment' => 'testing',
            'schema_ok' => false,
            'marker_leak' => true,
            'correlation_id' => 'c2',
        ]);
        $recorder->record([
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'mode' => 'shadow',
            'environment' => 'testing',
            'schema_ok' => true,
            'cost_anomaly' => true,
            'correlation_id' => 'c3',
        ]);

        $report = $recorder->reportFor('article.outline.generate', '0.1.0', 'shadow', 'testing');
        self::assertSame(3, $report['sample_count']);
        self::assertSame(1, $report['match_count']);
        self::assertSame(2, $report['mismatch_count']);
        self::assertSame(1, $report['schema_failure_count']);
        self::assertSame(1, $report['marker_leak_count']);
        self::assertSame(1, $report['token_cost_anomaly_count']);
        self::assertNotNull($report['first_seen']);
        self::assertNotNull($report['last_seen']);
    }

    public function test_promotion_threshold_per_hook(): void
    {
        Config::set('seo-content-ai.prompt_hooks.promotion_thresholds', [
            'default' => 20,
            'hooks' => [
                'article.outline.generate' => 20,
                'article.title_suggestion' => 30,
            ],
        ]);
        $t = new PromptHookPromotionThresholds;
        self::assertSame(20, $t->forHook('article.outline.generate'));
        self::assertSame(30, $t->forHook('article.title_suggestion'));
        self::assertSame(20, $t->forHook('unknown.hook'));
    }

    public function test_unexplained_mismatch_and_cost_anomaly_blockers(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.promotion_thresholds', [
            'default' => 20,
            'hooks' => [
                'article.faq.generate' => 2,
            ],
        ]);
        $recorder = new PromptHookShadowParityRecorder;
        $recorder->record([
            'hook_key' => 'article.faq.generate',
            'hook_version' => '0.1.0',
            'mode' => 'shadow',
            'environment' => 'testing',
            'schema_ok' => true,
            'cost_anomaly' => true,
        ]);
        $recorder->record([
            'hook_key' => 'article.faq.generate',
            'hook_version' => '0.1.0',
            'mode' => 'shadow',
            'environment' => 'testing',
            'schema_ok' => true,
            'matched' => false,
        ]);

        $gate = new PromptHookPromotionGate($recorder);
        $result = $gate->evaluate('article.faq.generate', '0.1.0', [
            'rollback_verified' => true,
        ]);
        self::assertFalse($result['allowed']);
        self::assertContains('unexplained_parity_mismatch', $result['blockers']);
        self::assertContains('cost_token_anomaly', $result['blockers']);
    }

    public function test_mode_transition_and_rollback_policy(): void
    {
        $transitions = new PromptHookModeTransitionPolicy;
        self::assertTrue($transitions->allows(PromptHookRuntimeMode::Legacy, PromptHookRuntimeMode::Shadow));
        self::assertTrue($transitions->allows(PromptHookRuntimeMode::Shadow, PromptHookRuntimeMode::Hook));
        self::assertTrue($transitions->allows(PromptHookRuntimeMode::Hook, PromptHookRuntimeMode::Legacy));
        self::assertFalse($transitions->allows(PromptHookRuntimeMode::Legacy, PromptHookRuntimeMode::Hook));
        self::assertFalse($transitions->allowsAutomaticStableVersionPromotion());

        $rollback = new PromptHookRollbackPolicy;
        self::assertSame(PromptHookRuntimeMode::Legacy, $rollback->targetMode());
        self::assertFalse($rollback->deletesDefinitions());
        self::assertFalse($rollback->deletesExecutionLogs());
        self::assertFalse($rollback->allowsSilentLegacyFallbackAfterProviderCall());
        self::assertNotEmpty($rollback->hostingSteps('article.outline.generate'));
    }

    public function test_hook_mode_provider_once_legacy_not_called(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.migration', [
            'article.outline.generate' => 'hook',
        ]);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', false);

        $provider = new FakePromptProviderAdapter(['text' => 'Heading outline']);
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $engine = new PromptHookRuntimeEngine(
            new PromptHookRuntimeRegistry($loader),
            new PromptHookEnvelopeValidator,
            new PromptHookRuntimeLocaleResolver,
            new PromptHookRuntimeSettingsResolver,
            new PromptHookDeterministicTemplateRenderer,
            new PromptProviderCapabilityResolver,
            $provider,
            new PromptHookRuntimeOutputPipeline,
            new InMemoryPromptHookBudgetGuard(new InMemoryPromptBudgetStore, 100, 1_000_000),
            new PromptHookAuditRecorder,
            new PromptHookMigrationFlags,
            new PromptHookShadowParityRecorder,
        );
        $bridge = new PromptHookCallerBridge(
            new PromptHookMigrationFlags,
            $engine,
            new PromptHookLiveShadowGate(new PromptHookMigrationFlags),
        );

        $legacyCalls = 0;
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            new PromptHookExecutionInput(
                context: ['site_id' => 1, 'locale' => 'vi'],
                input: ['keyword' => 'seo'],
                previousOutputs: [],
                settings: [],
            ),
            static function () use (&$legacyCalls): string {
                $legacyCalls++;

                return 'LEGACY';
            },
            mapHookResult: static fn ($result): string => (string) ($result->output['value'] ?? $result->output['raw'] ?? ''),
        );

        self::assertSame(0, $legacyCalls);
        self::assertCount(1, $provider->calls);
        self::assertNotSame('LEGACY', $out);
    }

    public function test_title_meta_remain_experimental_and_stable_not_automatic(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        foreach (['article.title_suggestion', 'article.meta_description_suggestion'] as $key) {
            $def = $registry->get($key, '0.1.0');
            self::assertSame(PromptHookStatus::Experimental, $def->status);
            self::assertSame('0.1.0', $def->version->toString());
        }
        self::assertFalse((new PromptHookModeTransitionPolicy)->allowsAutomaticStableVersionPromotion());
    }

    public function test_default_repo_modes_still_legacy(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', [
            'article.outline.generate' => 'legacy',
            'article.faq.generate' => 'legacy',
            'keyword.discovery.structured' => 'legacy',
            'article.title_suggestion' => 'legacy',
            'article.meta_description_suggestion' => 'legacy',
        ]);
        $flags = new PromptHookMigrationFlags;
        foreach ([
            'article.outline.generate',
            'article.faq.generate',
            'keyword.discovery.structured',
            'article.title_suggestion',
            'article.meta_description_suggestion',
        ] as $key) {
            self::assertSame('legacy', $flags->mode($key)->value);
        }
    }

    public function test_gate_blocks_legacy_to_hook_skip(): void
    {
        Config::set('seo-content-ai.prompt_hooks.promotion_thresholds', [
            'default' => 20,
            'hooks' => [
                'article.outline.generate' => 1,
            ],
        ]);
        $gate = new PromptHookPromotionGate;
        $result = $gate->evaluate('article.outline.generate', '0.1.0', [
            'sample_count' => 1,
            'from_mode' => 'legacy',
            'to_mode' => 'hook',
            'rollback_verified' => true,
        ]);
        self::assertFalse($result['allowed']);
        self::assertContains('mode_transition_forbidden', $result['blockers']);
    }
}
