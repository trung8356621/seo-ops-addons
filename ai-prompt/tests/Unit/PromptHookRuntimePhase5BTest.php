<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\BudgetExceeded;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ExperimentalNotAllowed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\HookDisabled;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InputTooLarge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\TemplateRenderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\VersionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PromptHookRuntimePhase5BTest extends TestCase
{
    private function loader(): PromptHookDefinitionLoader
    {
        return new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
    }

    private function registry(): PromptHookRuntimeRegistry
    {
        $loader = $this->loader();
        $loader->clearCache();

        return new PromptHookRuntimeRegistry($loader);
    }

    private function engine(FakePromptProviderAdapter $provider): PromptHookRuntimeEngine
    {
        return new PromptHookRuntimeEngine(
            $this->registry(),
            new PromptHookEnvelopeValidator,
            new PromptHookRuntimeLocaleResolver,
            new PromptHookRuntimeSettingsResolver,
            new PromptHookDeterministicTemplateRenderer,
            new PromptProviderCapabilityResolver,
            $provider,
            new PromptHookRuntimeOutputPipeline,
            new InMemoryPromptHookBudgetGuard(maxRequests: 10, maxTokens: 10_000),
            new PromptHookAuditRecorder,
            new PromptHookMigrationFlags,
        );
    }

    public function test_v01_definitions_registered_with_explicit_versions(): void
    {
        $registry = $this->registry();
        self::assertTrue($registry->has('article.title_suggestion', '0.1.0'));
        self::assertTrue($registry->has('article.meta_description_suggestion', '0.1.0'));
        self::assertTrue($registry->has('article.outline.generate', '0.1.0'));
        self::assertTrue($registry->has('article.content.generate', '0.1.0'));
        self::assertTrue($registry->has('article.content.rewrite', '0.1.0'));
        self::assertTrue($registry->has('article.faq.generate', '0.1.0'));
        self::assertTrue($registry->has('keyword.discovery.structured', '0.1.0'));

        $title = $registry->get('article.title_suggestion', '0.1.0');
        self::assertSame(PromptHookStatus::Experimental, $title->status);
    }

    public function test_version_not_found_and_no_latest_fallback(): void
    {
        $this->expectException(VersionNotFound::class);
        $this->registry()->get('article.title_suggestion', '9.9.9');
    }

    public function test_duplicate_key_version_rejected_on_load(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ph_dup_'.uniqid('', true);
        mkdir($dir);
        $spec = [
            'spec_version' => '0.1',
            'key' => 'article.dup.test',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => ['system' => 'a', 'user' => 'b'],
            'side_effects' => [],
        ];
        file_put_contents($dir.'/a.json', json_encode($spec));
        file_put_contents($dir.'/b.json', json_encode($spec));

        $loader = new PromptHookDefinitionLoader($dir, sys_get_temp_dir().'/empty_phase1_'.uniqid());
        try {
            $this->expectException(\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure::class);
            $loader->indexed();
        } finally {
            @unlink($dir.'/a.json');
            @unlink($dir.'/b.json');
            @rmdir($dir);
        }
    }

    public function test_eloquent_model_rejected_in_envelope(): void
    {
        $model = new class extends Model {};
        $this->expectException(InvalidInput::class);
        PromptHookExecutionInput::fromArray([
            'context' => [],
            'input' => ['keyword' => $model],
            'previous_outputs' => [],
            'settings' => [],
        ]);
    }

    public function test_previous_outputs_size_reject_without_truncate(): void
    {
        $def = $this->registry()->get('article.outline.generate', '0.1.0');
        // Shrink limits via reflection-free: use guard with custom def clone â€” hydrate small limits
        $loader = $this->loader();
        $def = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.limits.test',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => ['system' => 's', 'user' => 'u'],
            'limits' => ['max_item_bytes' => 10, 'max_total_bytes' => 100, 'max_items' => 5],
            'side_effects' => [],
        ]);

        $guard = new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPreviousOutputsGuard;
        $this->expectException(InputTooLarge::class);
        $guard->enforce($def, ['big' => str_repeat('x', 50)]);
    }

    public function test_locale_fallback_and_language_name(): void
    {
        $resolver = new PromptHookRuntimeLocaleResolver;
        $def = $this->registry()->get('article.title_suggestion', '0.1.0');
        $out = $resolver->resolve($def->locale, ['site_locale' => 'vi']);
        self::assertSame('vi-VN', $out['locale_code']);
        self::assertSame('Vietnamese', $out['language_name']);
    }

    public function test_template_strict_missing_variable(): void
    {
        $def = $this->registry()->get('keyword.discovery.structured', '0.1.0');
        // keyword discovery has strict_template_variables false â€” use hydrate strict
        $loader = $this->loader();
        $strict = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.strict.tpl',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => ['system' => 'Hi {{name}}', 'user' => 'x'],
            'side_effects' => [],
            'strict_template_variables' => true,
        ]);
        $renderer = new PromptHookDeterministicTemplateRenderer;
        $this->expectException(TemplateRenderFailed::class);
        $renderer->render($strict, [], ['locale_code' => 'en-US', 'language_name' => 'English'], []);
    }

    public function test_json_fence_and_invalid_output(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->registry()->get('article.faq.generate', '0.1.0');
        $ok = $pipeline->process($def, ['text' => "```json\n[{\"q\":\"a\"}]\n```"]);
        self::assertIsArray($ok['value']);

        $this->expectException(InvalidOutput::class);
        $pipeline->process($def, ['text' => 'not-json']);
    }

    public function test_capability_strategy_json_mode(): void
    {
        $resolver = new PromptProviderCapabilityResolver;
        $def = $this->registry()->get('keyword.discovery.structured', '0.1.0');
        $fake = new FakePromptProviderAdapter;
        $strategy = $resolver->resolveStrategy($def, $fake->capabilities());
        self::assertSame(PromptStructuredStrategy::JsonMode, $strategy);
    }

    public function test_legacy_mode_does_not_call_provider(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', array_merge(
            (array) config('seo-content-ai.prompt_hooks.migration', []),
            ['article.outline.generate' => 'legacy'],
        ));
        $provider = new FakePromptProviderAdapter(['text' => 'from-fake']);
        $bridge = new PromptHookCallerBridge(new PromptHookMigrationFlags, $this->engine($provider));
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            PromptHookExecutionInput::fromArray([
                'context' => [],
                'input' => ['post_title' => 'SEO guide', 'keyword' => 'k'],
                'previous_outputs' => [],
                'settings' => [],
            ]),
            static fn (): string => 'legacy-ok',
        );
        self::assertSame('legacy-ok', $out);
        self::assertCount(0, $provider->calls);
    }

    public function test_shadow_no_provider_by_default(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', array_merge(
            (array) config('seo-content-ai.prompt_hooks.migration', []),
            ['article.outline.generate' => 'shadow'],
        ));
        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', false);
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $provider = new FakePromptProviderAdapter;
        $bridge = new PromptHookCallerBridge(new PromptHookMigrationFlags, $this->engine($provider));
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            PromptHookExecutionInput::fromArray([
                'context' => [],
                'input' => ['post_title' => 'SEO guide', 'keyword' => 'k'],
                'previous_outputs' => [],
                'settings' => [],
            ]),
            static fn (): string => 'legacy-shadow',
        );
        self::assertSame('legacy-shadow', $out);
        self::assertCount(0, $provider->calls);
    }

    public function test_hook_mode_calls_provider_once(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', array_merge(
            (array) config('seo-content-ai.prompt_hooks.migration', []),
            ['article.outline.generate' => 'hook'],
        ));
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $sectionRaw = "[START_TASK_1_OUTLINE]\n".str_repeat('outline ', 30)."\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\n".str_repeat('vocab ', 30)."\n[END_TASK_2_VOCABULARY]";
        $provider = new FakePromptProviderAdapter(['text' => $sectionRaw]);
        $bridge = new PromptHookCallerBridge(new PromptHookMigrationFlags, $this->engine($provider));
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            PromptHookExecutionInput::fromArray([
                'context' => [
                    'legacy_compiled_prompt' => 'compiled outline prompt',
                    'prompt_id' => 1,
                ],
                'input' => ['post_title' => 'SEO guide', 'keyword' => 'balo'],
                'previous_outputs' => [],
                'settings' => [],
            ]),
            static fn (): string => 'should-not-run',
            mapHookResult: static fn ($r): string => (string) ($r->output['ports']['task_1_outline'] ?? ''),
        );
        self::assertStringContainsString('outline', $out);
        self::assertCount(1, $provider->calls);
    }

    public function test_live_shadow_default_off(): void
    {
        self::assertFalse((new PromptHookMigrationFlags)->liveShadowEnabled());
        self::assertFalse((new PromptHookMigrationFlags)->liveShadowProviderEnabled());
    }

    public function test_shadow_live_gate_without_provider_flag_does_not_double_call_provider(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', array_merge(
            (array) config('seo-content-ai.prompt_hooks.migration', []),
            ['article.outline.generate' => 'shadow'],
        ));
        Config::set('seo-content-ai.prompt_hooks.live_shadow_enabled', true);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_provider_enabled', false);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_environments', [app()->environment()]);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_hook_allowlist', ['article.outline.generate']);
        Config::set('seo-content-ai.prompt_hooks.live_shadow_sample_rate', 1.0);
        Config::set('seo-content-ai.prompt_hooks.budget_store', 'memory');
        Config::set('seo-content-ai.prompt_hooks.live_shadow_allow_memory_budget', true);
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $legacyCalls = 0;
        $provider = new FakePromptProviderAdapter(['text' => 'should-not-run']);
        $bridge = new PromptHookCallerBridge(new PromptHookMigrationFlags, $this->engine($provider));
        $out = $bridge->run(
            'article.outline.generate',
            '0.1.0',
            PromptHookExecutionInput::fromArray([
                'context' => [],
                'input' => ['post_title' => 'SEO guide', 'keyword' => 'k'],
                'previous_outputs' => [],
                'settings' => [],
            ]),
            static function () use (&$legacyCalls): string {
                $legacyCalls++;

                return 'legacy-only';
            },
        );

        self::assertSame('legacy-only', $out);
        self::assertSame(1, $legacyCalls);
        self::assertCount(0, $provider->calls);
    }

    public function test_budget_guard_exceeded(): void
    {
        $guard = new InMemoryPromptHookBudgetGuard(maxRequests: 1, maxTokens: 100);
        $guard->record('article.outline.generate', 1, 1, 1);
        $this->expectException(BudgetExceeded::class);
        $guard->assertWithinBudget('article.outline.generate', 1);
    }

    public function test_experimental_not_allowed(): void
    {
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', false);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', []);
        $registry = $this->registry();
        $def = $registry->get('article.title_suggestion', '0.1.0');
        $this->expectException(ExperimentalNotAllowed::class);
        $registry->assertExecutable($def, false, []);
    }

    public function test_disabled_hook_not_executable(): void
    {
        $loader = $this->loader();
        $def = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.disabled.x',
            'version' => '0.1.0',
            'enabled' => false,
        ]);
        $this->expectException(HookDisabled::class);
        $this->registry()->assertExecutable($def, true, []);
    }

    public function test_boundary_runtime_avoids_eloquent_filament_wordpress(): void
    {
        $forbidden = [
            'WordPressArticleSyncService',
            'ArticleEditorSyncOrchestrator',
            'Filament\\Resources\\',
            'Illuminate\\Database\\Eloquent\\Model',
        ];
        $dirs = [
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Runtime',
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Canonical',
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Provider',
            ProjectRoot::addonsPath().'/ai-prompt/src/PromptHooks/Output',
        ];
        foreach ($dirs as $dir) {
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                // ExecutionInput intentionally mentions Model for rejection.
                if (str_ends_with($file, 'PromptHookExecutionInput.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($file);
                foreach ($forbidden as $needle) {
                    self::assertStringNotContainsString($needle, $contents, basename($file));
                }
            }
        }
    }

    public function test_spec_validator_still_passes_v01_files(): void
    {
        $validator = new PromptHookSpecV01Validator;
        $dir = PromptHookDefinitionLoader::defaultV01Directory();
        self::assertDirectoryExists($dir);
        $files = glob($dir.'/*.json') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($data);
            self::assertSame([], $validator->validate($data), basename($file));
        }
    }

    public function test_migration_flags_default_legacy(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration', array_merge(
            (array) config('seo-content-ai.prompt_hooks.migration', []),
            ['article.outline.generate' => null],
        ));
        $mode = (new PromptHookMigrationFlags)->mode('article.outline.generate');
        self::assertSame('legacy', $mode->value);
    }
}
