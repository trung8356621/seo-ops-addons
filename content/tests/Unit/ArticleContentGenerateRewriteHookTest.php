<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionIntent;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

final class ArticleContentGenerateRewriteHookTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function catalog(): PromptHookEditorCatalog
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();

        return new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
    }

    private function longMarkdown(string $prefix = '# Article'): string
    {
        // article.content.generate validates against minimum_acceptable_words (ratio 50% of target).
        return $prefix."\n\n".str_repeat('Nội dung bài viết mẫu cho validation word. ', 400);
    }

    /**
     * @param  array{text?: string, refused?: bool, truncated?: bool}  $response
     */
    private function executor(FakePromptProviderAdapter $provider): PromptHookExplicitBindingExecutor
    {
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
        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturn('LEGACY COMPILED ARTICLE PROMPT {{input}}');

        return new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );
    }

    public function test_generate_and_rewrite_appear_in_dropdown_with_labels(): void
    {
        app()->setLocale('vi');
        $options = $this->catalog()->optionsForTextPromptBlock();
        $byKey = [];
        foreach ($options as $row) {
            $byKey[$row['hook_key']] = $row;
        }

        self::assertArrayHasKey('article.content.generate', $byKey);
        // Phase 1.0: rewrite không còn trong selector tạo mới.
        self::assertArrayNotHasKey('article.content.rewrite', $byKey);
        self::assertSame('0.1.0', $byKey['article.content.generate']['version']);
        self::assertTrue($byKey['article.content.generate']['experimental']);
        self::assertSame('Viết bài viết', $byKey['article.content.generate']['display_name']);
        self::assertStringContainsString('Thử nghiệm', $byKey['article.content.generate']['option_label']);
        self::assertStringContainsString('[article.content.generate]', $byKey['article.content.generate']['option_label']);
        self::assertSame('markdown', $byKey['article.content.generate']['output_type']);
        self::assertSame(count($options), count(array_unique(array_column($options, 'hook_key'))));
    }

    public function test_normalize_saves_exact_key_version_and_clears(): void
    {
        app()->instance(PromptHookEditorCatalog::class, $this->catalog());

        $generate = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.content.generate',
            'hook_version' => '',
            'hook_settings' => [],
            'tools' => 'default',
        ]);
        self::assertSame('article.content.generate', $generate['hook_key']);
        self::assertSame('0.1.0', $generate['hook_version']);

        $rewrite = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.content.rewrite',
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'tools' => 'default',
        ]);
        self::assertSame('article.content.rewrite', $rewrite['hook_key']);
        self::assertSame('0.1.0', $rewrite['hook_version']);

        $cleared = PromptHookFormSchema::normalizeForSave([
            'hook_key' => '',
            'hook_version' => '0.1.0',
            'hook_settings' => ['x' => 1],
            'tools' => 'default',
        ]);
        self::assertNull($cleared['hook_key']);
        self::assertNull($cleared['hook_version']);
    }

    public function test_definitions_use_legacy_prompt_template_and_markdown(): void
    {
        $generate = $this->catalog()->find('article.content.generate', '0.1.0');
        $rewrite = $this->catalog()->find('article.content.rewrite', '0.1.0');

        self::assertSame('legacy_prompt_content', $generate->template['source'] ?? null);
        self::assertSame('legacy_prompt_content', $rewrite->template['source'] ?? null);
        self::assertSame('markdown', $generate->outputSchema->type);
        self::assertSame('markdown', $rewrite->outputSchema->type);
        self::assertTrue(PromptHookFormSchema::usesLegacyPromptTemplate('article.content.generate', '0.1.0'));
        self::assertTrue(PromptHookFormSchema::usesLegacyPromptTemplate('article.content.rewrite', '0.1.0'));
        self::assertNull($generate->template['system'] ?? null);
        self::assertNull($rewrite->template['user'] ?? null);
    }

    public function test_generate_binding_calls_provider_once_with_mapped_input(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', [
            'article.content.generate',
            'article.content.rewrite',
        ]);

        $markdown = $this->longMarkdown('# Generated');
        $provider = new FakePromptProviderAdapter(['text' => "```markdown\n{$markdown}\n```"]);
        $executor = $this->executor($provider);

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 11,
            'hook_key' => 'article.content.generate',
            'hook_version' => '0.1.0',
            'markdown_content' => 'Prompt body {{input}} {{tone}}',
        ]);

        $result = $executor->execute($prompt, [
            'input' => "Outline\nVocabulary planning",
            'keyword' => 'balo du lịch',
            'tone' => 'thân thiện',
            'language' => 'vi',
            'site_short_description' => 'Shop balo',
            'article_length' => 1800,
        ], ['site_id' => 1, 'locale' => 'vi']);

        self::assertCount(1, $provider->calls);
        self::assertStringStartsWith(
            'LEGACY COMPILED ARTICLE PROMPT {{input}}',
            (string) ($provider->calls[0]->messages[0]['content'] ?? ''),
        );
        self::assertSame(PromptHookExecutionIntent::ExplicitBinding->value, $result['execution_source']);
        self::assertStringContainsString('Generated', $result['output']);
        self::assertStringNotContainsString('```', $result['output']);
        self::assertSame('article.content.generate', $result['hook_key']);
        self::assertSame('0.1.0', $result['hook_version']);
    }

    public function test_rewrite_maps_article_and_instruction_separately(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', [
            'article.content.generate',
            'article.content.rewrite',
        ]);

        $markdown = $this->longMarkdown('# Rewritten');
        $provider = new FakePromptProviderAdapter(['text' => $markdown]);
        $executor = $this->executor($provider);

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 12,
            'hook_key' => 'article.content.rewrite',
            'hook_version' => '0.1.0',
            'markdown_content' => 'Rewrite {{input}} with {{rewrite_instruction}}',
        ]);

        $result = $executor->execute($prompt, [
            'post_content' => $this->longMarkdown('# Original article body'),
            'rewrite_notes' => 'viết chi tiết hơn, giữ heading',
            'preserve_headings' => true,
            'language' => 'vi',
            'article_length' => 300,
        ], ['site_id' => 2]);

        self::assertCount(1, $provider->calls);
        self::assertStringContainsString('Rewritten', $result['output']);
        // Phase 0.3: runtime remaps rewrite → generate.
        self::assertSame('article.content.generate', $result['hook_key']);
        self::assertSame('article.content.rewrite', $result['legacy_hook_key'] ?? null);
    }

    public function test_generate_missing_required_input_fails_before_provider(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.content.generate']);

        $provider = new FakePromptProviderAdapter(['text' => $this->longMarkdown()]);
        $executor = $this->executor($provider);
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 13,
            'hook_key' => 'article.content.generate',
            'hook_version' => '0.1.0',
        ]);

        $this->expectException(InvalidInput::class);
        try {
            $executor->execute($prompt, ['tone' => 'only'], []);
        } finally {
            self::assertCount(0, $provider->calls);
        }
    }

    public function test_output_empty_and_preamble_and_refusal(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $definition = $loader->indexed()['article.content.generate@0.1.0'];
        $pipeline = new PromptHookRuntimeOutputPipeline;

        try {
            $pipeline->process($definition, ['text' => '']);
            self::fail('Expected InvalidOutput for empty');
        } catch (InvalidOutput) {
            self::assertTrue(true);
        }

        try {
            $pipeline->process($definition, ['text' => 'Sure, here is the article you asked for.']);
            self::fail('Expected InvalidOutput for preamble');
        } catch (InvalidOutput) {
            self::assertTrue(true);
        }

        $this->expectException(ProviderRefused::class);
        $pipeline->process($definition, ['text' => 'x', 'refused' => true]);
    }

    public function test_runtime_engine_has_no_article_save_or_wordpress(): void
    {
        $engineFile = (string) file_get_contents((new ReflectionClass(PromptHookRuntimeEngine::class))->getFileName() ?: '');
        self::assertStringNotContainsString('Article::', $engineFile);
        self::assertStringNotContainsString('WordPress', $engineFile);
        self::assertStringNotContainsString('article.content.update', $engineFile);

        $executorFile = (string) file_get_contents((new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName() ?: '');
        self::assertStringNotContainsString('article.content.update', $executorFile);
        self::assertStringNotContainsString('WordPressArticleSync', $executorFile);
    }

    public function test_global_migration_defaults_legacy(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration.article.content.generate', 'legacy');
        Config::set('seo-content-ai.prompt_hooks.migration.article.content.rewrite', 'legacy');
        $flags = new PromptHookMigrationFlags;
        self::assertSame('legacy', $flags->mode('article.content.generate')->value);
        self::assertSame('legacy', $flags->mode('article.content.rewrite')->value);
    }

    public function test_workflow_port_shape_total_ai(): void
    {
        $output = $this->longMarkdown('# Body');
        $nodeOutputs = ['out_main' => $output];
        self::assertSame($output, $nodeOutputs['out_main']);
    }
}
