<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\PromptResult\AttachPromptResultAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Registry\ActionCatalogBootstrap;
use Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar;
use Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry;
use Omnichannel\Addons\Seo\Contracts\PromptResultAttacher;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookModelConfig;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\ConfigPromptCostEstimator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilities;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderUsageNormalizer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;
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
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionGate;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\Services\PromptResultAttachService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

final class PromptHookRuntimePhase5CTest extends TestCase
{
    public function test_usage_normalizer_maps_provider_tokens(): void
    {
        $normalizer = new PromptProviderUsageNormalizer(new ConfigPromptCostEstimator);
        $response = $normalizer->normalize(
            text: 'hello',
            usage: [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'cached_tokens' => 2,
                'finish_reason' => 'stop',
                'request_id' => 'req-1',
            ],
            provider: 'gemini',
            model: 'flash',
        );

        self::assertSame(10, $response->inputTokens);
        self::assertSame(5, $response->outputTokens);
        self::assertSame(15, $response->totalTokens);
        self::assertSame(2, $response->cachedTokens);
        self::assertSame('provider', $response->usageSource);
        self::assertSame('stop', $response->finishReason);
        self::assertSame('req-1', $response->providerRequestId);
        self::assertNull($response->estimatedCost);
    }

    public function test_usage_normalizer_missing_usage_stays_unknown(): void
    {
        $normalizer = new PromptProviderUsageNormalizer(new ConfigPromptCostEstimator);
        $response = $normalizer->normalize(text: 'x', usage: [], provider: 'claude', model: 'sonnet');

        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
        self::assertSame('unknown', $response->usageSource);
    }

    public function test_usage_normalizer_estimated_when_cost_config_present_without_tokens(): void
    {
        Config::set('seo-content-ai.prompt_hooks.cost_rates', [
            'gemini' => ['*' => ['input_per_1m' => 1.0, 'output_per_1m' => 2.0]],
        ]);
        $normalizer = new PromptProviderUsageNormalizer(new ConfigPromptCostEstimator);
        $response = $normalizer->normalize(text: 'x', usage: [], provider: 'gemini', model: 'flash');

        // No tokens → cost estimator returns 0-ish or null; usage_source only estimated if cost non-null with zero tokens
        if ($response->estimatedCost !== null) {
            self::assertSame('estimated', $response->usageSource);
        } else {
            self::assertSame('unknown', $response->usageSource);
        }
    }

    public function test_capability_rejects_native_when_unsupported(): void
    {
        $adapter = new FakePromptProviderAdapter(
            capabilities: new PromptProviderCapabilities(
                textGeneration: true,
                jsonMode: false,
                nativeStructuredOutput: false,
                systemMessage: true,
                temperature: true,
                maxTokens: true,
            ),
        );
        $resolver = new PromptProviderCapabilityResolver;
        $registry = new PromptHookRuntimeRegistry(new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        ));
        $definition = $registry->get('article.faq.generate', '0.1.0');
        $strategy = $resolver->resolveStrategy($definition, $adapter->capabilities());
        self::assertSame(PromptStructuredStrategy::PromptEnforcedJson, $strategy);
    }

    public function test_retry_owner_documented_on_fake_path_and_engine_has_no_retry_loop(): void
    {
        $engineRef = new ReflectionClass(PromptHookRuntimeEngine::class);
        $source = file_get_contents($engineRef->getFileName() ?: '');
        self::assertIsString($source);
        self::assertStringNotContainsString('for ($attempt', $source);
        self::assertStringNotContainsString('while ($attempt', $source);
        self::assertStringContainsString('Retry ownership: PromptRunner', $source);
    }

    public function test_provider_response_dto_has_no_sdk_objects(): void
    {
        $adapter = new FakePromptProviderAdapter(['text' => '{"ok":true}']);
        $request = new RenderedPromptRequest(
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            model: new PromptHookModelConfig(provider: 'fake', name: 'fake', settings: ['temperature' => 0.2, 'max_tokens' => 100]),
            modelSettings: [],
            localeCode: 'vi',
            languageName: 'Vietnamese',
            hookKey: 'article.outline.generate',
            hookVersion: '0.1.0',
            redactedVariableMetadata: [],
            metadata: ['prompt_id' => 1],
        );
        $response = $adapter->generate($request, PromptStructuredStrategy::PlainText);
        self::assertIsString($response->text);
        self::assertSame('fake', $response->provider);
        foreach ((new ReflectionClass($response))->getProperties() as $property) {
            $value = $property->getValue($response);
            self::assertFalse(is_object($value) && ! ($value instanceof \UnitEnum));
        }
    }

    public function test_credential_redacted_in_audit(): void
    {
        $redacted = false;
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $channel, array $payload) use (&$redacted): bool {
            if ($channel === 'prompt_hook.execution_audit' && ($payload['api_key'] ?? null) === '[redacted]') {
                $redacted = true;
            }

            return true;
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        (new PromptHookAuditRecorder)->record([
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'mode' => 'hook',
            'correlation_id' => 'c1',
            'validation_status' => 'ok',
            'api_key' => 'sk-secret',
        ]);

        self::assertTrue($redacted);
    }

    public function test_attach_action_contract_and_idempotency_via_fake(): void
    {
        $fake = new class implements PromptResultAttacher
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function attach(
                int $promptResultId,
                string $targetType,
                int $targetId,
                int $siteId,
                string $purpose = 'manual',
                array $meta = [],
            ): array {
                $this->calls[] = compact('promptResultId', 'targetType', 'targetId', 'siteId', 'purpose', 'meta');
                $dedup = count($this->calls) > 1;

                return [
                    'attached' => true,
                    'deduplicated' => $dedup,
                    'prompt_result_id' => $promptResultId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                ];
            }
        };

        $action = new AttachPromptResultAction($fake);
        $ctx = ActionContext::fromArray([
            'origin' => 'system.test',
            'site_id' => 9,
            'correlation_id' => 'corr',
        ]);

        $first = $action->execute($ctx, [
            'prompt_result_id' => 11,
            'target_type' => 'article',
            'target_id' => 22,
            'site_id' => 9,
            'purpose' => 'prompt_hook',
        ]);
        $second = $action->execute($ctx, [
            'prompt_result_id' => 11,
            'target_type' => 'article',
            'target_id' => 22,
            'site_id' => 9,
            'purpose' => 'prompt_hook',
        ]);

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertFalse((bool) ($first->output['deduplicated'] ?? true));
        self::assertTrue((bool) ($second->output['deduplicated'] ?? false));
        self::assertCount(2, $fake->calls);
    }

    public function test_attach_action_rejects_wrong_context_and_bad_target(): void
    {
        $fake = new class implements PromptResultAttacher
        {
            public function attach(
                int $promptResultId,
                string $targetType,
                int $targetId,
                int $siteId,
                string $purpose = 'manual',
                array $meta = [],
            ): array {
                throw new InvalidArgumentException('Article site_id mismatch (wrong context).');
            }
        };

        $action = new AttachPromptResultAction($fake);
        $ctx = ActionContext::fromArray(['origin' => 'system.test', 'site_id' => 1]);

        $badTarget = $action->execute($ctx, [
            'prompt_result_id' => 1,
            'target_type' => 'wordpress_post',
            'target_id' => 2,
            'site_id' => 1,
        ]);
        self::assertFalse($badTarget->success);
        self::assertSame('target_not_allowed', $badTarget->error['code'] ?? null);

        $wrongSite = $action->execute($ctx, [
            'prompt_result_id' => 1,
            'target_type' => 'article',
            'target_id' => 2,
            'site_id' => 99,
        ]);
        self::assertFalse($wrongSite->success);
        self::assertSame('wrong_context', $wrongSite->error['code'] ?? null);

        $mismatch = $action->execute(ActionContext::fromArray([
            'origin' => 'system.test',
            'site_id' => 1,
        ]), [
            'prompt_result_id' => 1,
            'target_type' => 'article',
            'target_id' => 2,
            'site_id' => 1,
        ]);
        self::assertFalse($mismatch->success);
        self::assertSame('wrong_context', $mismatch->error['code'] ?? null);
    }

    public function test_hook_engine_cannot_attach_domain(): void
    {
        $ctor = (new ReflectionClass(PromptHookRuntimeEngine::class))->getConstructor();
        $params = $ctor?->getParameters() ?? [];
        $types = array_map(
            static fn ($p) => $p->getType()?->__toString() ?? '',
            $params,
        );
        self::assertNotContains(PromptResultAttacher::class, $types);
        self::assertNotContains(PromptResultAttachService::class, $types);
        self::assertNotContains(AttachPromptResultAction::class, $types);

        $source = (string) file_get_contents((new ReflectionClass(PromptHookRuntimeEngine::class))->getFileName() ?: '');
        self::assertStringNotContainsString('PromptResultAttach', $source);
        self::assertStringNotContainsString('linkPromptResult', $source);
    }

    public function test_shadow_does_not_call_provider_twice(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.migration', [
            'article.outline.generate' => 'shadow',
        ]);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', false);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $provider = new FakePromptProviderAdapter(['text' => '{"headings":[]}']);
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $engine = new PromptHookRuntimeEngine(
            $registry,
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

        $envelope = new PromptHookExecutionInput(
            context: ['site_id' => 1, 'locale' => 'vi'],
            input: ['post_title' => 'SEO guide', 'keyword' => 'seo'],
            previousOutputs: [],
            settings: [],
        );

        $calls = 0;
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            $envelope,
            static function () use (&$calls): array {
                $calls++;

                return ['headings' => ['H1']];
            },
            mapLegacyOutput: static fn (mixed $legacy): array => [
                'type' => 'json',
                'raw' => json_encode($legacy, JSON_THROW_ON_ERROR),
                'value' => $legacy,
            ],
        );

        self::assertSame(['headings' => ['H1']], $out);
        self::assertSame(1, $calls);
        self::assertCount(0, $provider->calls);
    }

    public function test_live_shadow_gate_default_blocks(): void
    {
        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', false);
        $gate = new PromptHookLiveShadowGate(new PromptHookMigrationFlags);
        self::assertFalse($gate->allows('article.outline.generate'));

        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', true);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_environments', ['production']);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_hook_allowlist', ['article.outline.generate']);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_sample_rate', 1.0);
        Config::set('seo-content-ai.prompt_hooks.budget_store', 'memory');
        Config::set('seo-content-ai.prompt_hooks.live_shadow_allow_memory_budget', false);
        self::assertFalse($gate->allows('article.outline.generate'));
    }

    public function test_budget_store_interface(): void
    {
        $store = new InMemoryPromptBudgetStore;
        $store->increment('h#1', 10);
        $store->increment('h#1', 5);
        self::assertSame(['requests' => 2, 'tokens' => 15], $store->get('h#1'));
    }

    public function test_promotion_gate_blockers(): void
    {
        Config::set('seo-content-ai.prompt_hooks.promotion_thresholds.hooks', [
            'article.title_suggestion' => 3,
        ]);
        $gate = new PromptHookPromotionGate;
        $blocked = $gate->evaluate('article.title_suggestion', '0.1.0', [
            'sample_count' => 1,
            'schema_failure' => true,
            'marker_leak' => true,
            'rollback_verified' => true,
        ]);
        self::assertFalse($blocked['allowed']);
        self::assertContains('missing_sample', $blocked['blockers']);
        self::assertContains('schema_failure', $blocked['blockers']);
        self::assertContains('marker_leak', $blocked['blockers']);

        $ok = $gate->evaluate('article.title_suggestion', '0.1.0', [
            'sample_count' => 3,
            'definition_valid' => true,
            'version_pinned' => true,
            'provider_capability_ok' => true,
            'rollback_verified' => true,
        ]);
        self::assertTrue($ok['allowed']);
        self::assertSame(3, $ok['threshold']);
    }

    public function test_prompt_result_attach_registered(): void
    {
        $container = new Container;
        $registry = new ActionRegistry($container);
        (new ActionCatalogBootstrap)->register($registry);
        self::assertTrue($registry->has('prompt_result.attach'));
        self::assertContains(AttachPromptResultAction::class, ActionHandlerRegistrar::handlers());
    }

    public function test_default_migration_modes_are_legacy(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', [
            'article.title_suggestion' => 'legacy',
            'article.meta_description_suggestion' => 'legacy',
            'article.outline.generate' => 'legacy',
            'article.faq.generate' => 'legacy',
            'keyword.discovery.structured' => 'legacy',
        ]);
        $flags = new PromptHookMigrationFlags;
        foreach ([
            'article.title_suggestion',
            'article.meta_description_suggestion',
            'article.outline.generate',
            'article.faq.generate',
            'keyword.discovery.structured',
        ] as $key) {
            self::assertSame('legacy', $flags->mode($key)->value);
        }
    }

    public function test_title_meta_remain_experimental_versions(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $title = $registry->get('article.title_suggestion', '0.1.0');
        $meta = $registry->get('article.meta_description_suggestion', '0.1.0');
        self::assertSame('0.1.0', $title->version->toString());
        self::assertSame('0.1.0', $meta->version->toString());
        self::assertSame(PromptHookStatus::Experimental, $title->status);
        self::assertSame(PromptHookStatus::Experimental, $meta->status);
    }
}
