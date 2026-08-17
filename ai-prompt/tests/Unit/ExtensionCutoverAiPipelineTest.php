<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\AiProvidersExtensionProvider;
use Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\ClaudeAiTextProvider;
use Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\DeepSeekAiTextProvider;
use Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\GeminiAiTextProvider;
use Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\OpenRouterAiTextProvider;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\ContentPipelinesExtensionProvider;
use Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions\ArticlePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ImprovePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ProductPipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\RewritePipelineDefinition;
use Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\TranslatePipelineDefinition;
use Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo\LocalSeoExtensionProvider;
use Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo\LocalSeoProvider;
use Omnichannel\Addons\Media\Extension\Contracts\AiImageProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;
use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\Agent\Extension\Resolvers\PipelineResolver;
use Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\GeminiGenerateContentClient;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use PHPUnit\Framework\TestCase;

/**
 * Pure source/reflection assertions for the AI provider + pipeline + SEO extension cutover
 * scaffolds. Intentionally does NOT boot Laravel (no container, no DB) so it can run with
 * plain PHPUnit on the remote host — see `.cursor/rules/phpunit-remote.mdc`.
 */
final class ExtensionCutoverAiPipelineTest extends TestCase
{
    public function test_ai_provider_resolver_exists_and_is_fail_closed(): void
    {
        $this->assertTrue(class_exists(AiProviderResolver::class));
        $this->assertSame('ai-providers', AiProviderResolver::BUILTIN_EXTENSION_ID);
        $this->assertSame('ai_provider.not_configured', AiProviderResolver::ERROR_NOT_CONFIGURED);
        $this->assertSame('ai_provider.not_registered', AiProviderResolver::ERROR_NOT_REGISTERED);
        $this->assertSame('ai_provider.disabled', AiProviderResolver::ERROR_DISABLED);

        $methods = get_class_methods(AiProviderResolver::class);
        $this->assertContains('resolveText', $methods);
        $this->assertContains('resolveImage', $methods);
        $this->assertContains('assertTextReady', $methods);
    }

    public function test_ai_text_and_image_provider_contracts_exist(): void
    {
        $this->assertTrue(interface_exists(AiTextProviderInterface::class));
        $this->assertTrue(interface_exists(AiImageProviderInterface::class));

        $this->assertTrue((new \ReflectionClass(AiTextProviderInterface::class))->hasMethod('generate'));
        $this->assertTrue((new \ReflectionClass(AiTextProviderInterface::class))->hasMethod('health'));
        $this->assertTrue((new \ReflectionClass(AiImageProviderInterface::class))->hasMethod('generateImage'));
    }

    public function test_builtin_ai_providers_extension_wires_gemini_and_claude(): void
    {
        $this->assertTrue(class_exists(AiProvidersExtensionProvider::class));
        $this->assertTrue(class_exists(GeminiAiTextProvider::class));
        $this->assertTrue(class_exists(ClaudeAiTextProvider::class));
        $this->assertTrue(class_exists(DeepSeekAiTextProvider::class));
        $this->assertTrue(class_exists(GeminiGenerateContentClient::class));
        $this->assertTrue(class_exists(ClaudeMessagesClient::class));
        $this->assertTrue(class_exists(DeepSeekChatClient::class));
        $this->assertTrue(class_exists(OpenRouterAiTextProvider::class));
        $this->assertContains(
            AiTextProviderInterface::class,
            class_implements(OpenRouterAiTextProvider::class),
        );

        $this->assertContains(
            AiTextProviderInterface::class,
            class_implements(GeminiAiTextProvider::class),
        );
        $this->assertContains(
            AiTextProviderInterface::class,
            class_implements(ClaudeAiTextProvider::class),
        );

        $gemini = new \ReflectionClass(GeminiAiTextProvider::class);
        $this->assertTrue($gemini->hasMethod('key'));
        $keyMethod = $gemini->getMethod('key');
        // key() needs instance — verify source declares gemini slug.
        $src = (string) file_get_contents((string) $gemini->getFileName());
        $this->assertStringContainsString("return 'gemini';", $src);
    }

    public function test_extension_context_uses_seo_extension_provider_registry_not_catalog(): void
    {
        $contextSrc = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\Agent\Extension\ExtensionContext::class))->getFileName(),
        );
        $this->assertStringContainsString(
            'Omnichannel\\Addons\\Seo\\Extension\\Registry\\SeoProviderRegistry',
            $contextSrc,
        );
        $this->assertStringNotContainsString(
            'Omnichannel\\Addons\\SearchIntelligence\\Services\\SeoProviderRegistry',
            $contextSrc,
        );

        $compatSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/SeoContentAiServiceProvider.php',
        );
        $this->assertStringContainsString(
            'Omnichannel\\Addons\\Seo\\Extension\\Registry\\SeoProviderRegistry::class',
            $compatSrc,
        );
        $this->assertStringContainsString('discoverAndRegister', $compatSrc);
        $this->assertStringContainsString('bootExtensions', $compatSrc);
        $this->assertStringContainsString('RuntimeLogger::report', $compatSrc);
    }

    public function test_ai_providers_extension_registers_gemini_text_key(): void
    {
        $registry = new \Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry;

        $geminiProvider = new GeminiAiTextProvider(new GeminiGenerateContentClient);
        $claudeProvider = new ClaudeAiTextProvider(new ClaudeMessagesClient);
        $deepseekProvider = new DeepSeekAiTextProvider(new DeepSeekChatClient);
        $openrouterProvider = new OpenRouterAiTextProvider(new OpenAiCompatibleProtocolAdapter);
        $healthDriver = new \Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\AiProvidersHealthDriver(
            $geminiProvider,
            $claudeProvider,
            $deepseekProvider,
            $openrouterProvider,
        );

        // Same registration path as AiProvidersExtensionProvider::register().
        $registry->registerText($geminiProvider);
        $registry->registerText($claudeProvider);
        $registry->registerText($deepseekProvider);
        $registry->registerText($openrouterProvider);
        $registry->register('ai-providers', $healthDriver);

        $this->assertTrue($registry->hasText('gemini'));
        $this->assertTrue($registry->hasText('claude'));
        $this->assertTrue($registry->hasText('deepseek'));
        $this->assertTrue($registry->hasText('openrouter'));
        $this->assertSame(['gemini', 'claude', 'deepseek', 'openrouter'], $registry->textKeys());
        $this->assertSame('gemini', $registry->getText('gemini')?->key());

        $extSrc = (string) file_get_contents(
            (new \ReflectionClass(AiProvidersExtensionProvider::class))->getFileName(),
        );
        $this->assertStringContainsString('registerText($this->gemini)', $extSrc);
        $this->assertStringContainsString('registerText($this->claude)', $extSrc);
        $this->assertStringContainsString('registerText($this->openrouter)', $extSrc);

        $resolverSrc = (string) file_get_contents(
            (new \ReflectionClass(AiProviderResolver::class))->getFileName(),
        );
        $this->assertStringContainsString('hasText($key)', $resolverSrc);
        $this->assertStringContainsString(AiProviderResolver::ERROR_NOT_REGISTERED, $resolverSrc);
    }

    public function test_builtin_ai_providers_plugin_manifest_exists_and_is_valid(): void
    {
        $manifestPath = ProjectRoot::addonsPath().'/agent/src/Extension/Builtin/AiProviders/plugin.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ai-providers', $manifest['id']);
        $this->assertSame(AiProvidersExtensionProvider::class, $manifest['provider']);
        $this->assertContains('ai', $manifest['providers']);
        $this->assertSame(1, $manifest['sdk']);
    }

    public function test_prompt_runner_service_source_uses_ai_provider_resolver(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(PromptRunnerService::class))->getFileName());

        $this->assertStringContainsString(AiProviderResolver::class, $source);
        $this->assertStringContainsString('assertTextReady', $source);
        $this->assertStringContainsString(GeminiGenerateContentClient::class, $source);
    }

    public function test_pipeline_resolver_exists_and_is_fail_closed(): void
    {
        $this->assertTrue(class_exists(PipelineResolver::class));
        $this->assertSame('content-pipelines', PipelineResolver::BUILTIN_EXTENSION_ID);
        $this->assertSame('pipeline.not_configured', PipelineResolver::ERROR_NOT_CONFIGURED);
        $this->assertSame('pipeline.not_registered', PipelineResolver::ERROR_NOT_REGISTERED);
        $this->assertSame('pipeline.disabled', PipelineResolver::ERROR_DISABLED);

        $this->assertContains('resolve', get_class_methods(PipelineResolver::class));
    }

    public function test_pipeline_definition_contract_exists(): void
    {
        $this->assertTrue(interface_exists(PipelineDefinitionInterface::class));

        $reflection = new \ReflectionClass(PipelineDefinitionInterface::class);
        foreach (['key', 'name', 'version', 'supportedContentTypes', 'steps', 'requiredCapabilities', 'validate'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), "Missing method [{$method}] on PipelineDefinitionInterface.");
        }
    }

    public function test_builtin_content_pipelines_definitions_have_unique_keys_and_validate_shape(): void
    {
        $definitions = [
            new ArticlePipelineDefinition,
            new RewritePipelineDefinition,
            new ImprovePipelineDefinition,
            new TranslatePipelineDefinition,
            new ProductPipelineDefinition,
        ];

        $keys = array_map(static fn (PipelineDefinitionInterface $definition): string => $definition->key(), $definitions);
        $this->assertSame(['article', 'rewrite', 'improve', 'translate', 'product'], $keys);
        $this->assertSame($keys, array_unique($keys));

        foreach ($definitions as $definition) {
            $this->assertNotSame([], $definition->steps());
            $this->assertNotSame([], $definition->supportedContentTypes());

            $result = $definition->validate([]);
            $this->assertArrayHasKey('ok', $result);
            $this->assertArrayHasKey('errors', $result);
            $this->assertFalse($result['ok'], sprintf('Pipeline [%s] should fail validation with empty context.', $definition->key()));
            $this->assertNotSame([], $result['errors']);
        }
    }

    public function test_builtin_content_pipelines_plugin_manifest_exists_and_is_valid(): void
    {
        $manifestPath = ProjectRoot::addonsPath().'/agent/src/Extension/Builtin/ContentPipelines/plugin.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('content-pipelines', $manifest['id']);
        $this->assertSame(ContentPipelinesExtensionProvider::class, $manifest['provider']);
        $this->assertContains('pipeline', $manifest['providers']);
        $this->assertSame(1, $manifest['sdk']);
    }

    public function test_seo_provider_contract_and_local_seo_builtin_exist(): void
    {
        $this->assertTrue(interface_exists(SeoProviderInterface::class));
        $this->assertTrue(class_exists(LocalSeoProvider::class));
        $this->assertTrue(class_exists(LocalSeoExtensionProvider::class));

        $this->assertContains(SeoProviderInterface::class, class_implements(LocalSeoProvider::class));

        $provider = new LocalSeoProvider;
        $this->assertSame('local-seo', $provider->key());
        $this->assertSame([
            'seo.local.health',
            'seo.local.audit_placeholder',
        ], $provider->capabilities());
        $this->assertTrue($provider->health()->ok);

        $manifestPath = ProjectRoot::addonsPath().'/agent/src/Extension/Builtin/LocalSeo/plugin.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('local-seo', $manifest['id']);
        $this->assertContains('seo', $manifest['providers']);
    }
}
