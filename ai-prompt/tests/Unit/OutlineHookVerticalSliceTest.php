<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBinding;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class OutlineHookVerticalSliceTest extends TestCase
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

    private function pad(string $prefix): string
    {
        return $prefix.' '.str_repeat('x', 120);
    }

    private function validTwoSectionOutput(): string
    {
        $outline = $this->pad('# Outline body with enough length for validation');
        $vocab = $this->pad('- term: meaning with enough length for validation');

        return "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";
    }

    public function test_outline_appears_in_dropdown_with_vietnamese_label_and_version(): void
    {
        app()->setLocale('vi');
        $options = $this->catalog()->optionsForTextPromptBlock();
        $outline = null;
        foreach ($options as $row) {
            if ($row['hook_key'] === 'article.outline.generate') {
                $outline = $row;
                break;
            }
        }
        self::assertNotNull($outline);
        self::assertSame('0.1.0', $outline['version']);
        self::assertTrue($outline['experimental']);
        self::assertStringContainsString('Thử nghiệm', $outline['option_label']);
        self::assertSame('Tạo dàn ý bài viết', $outline['display_name']);
        self::assertSame('markdown_sections', $outline['output_type']);

        $keys = array_column($options, 'hook_key');
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_normalize_for_save_persists_semver_binding(): void
    {
        app()->instance(PromptHookEditorCatalog::class, $this->catalog());

        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.outline.generate',
            'hook_version' => '',
            'hook_settings' => [],
            'tools' => 'default',
        ]);

        self::assertSame('article.outline.generate', $data['hook_key']);
        self::assertSame('0.1.0', $data['hook_version']);
    }

    public function test_normalize_clears_binding_when_none(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => '',
            'hook_version' => '0.1.0',
            'hook_settings' => ['x' => 1],
            'tools' => 'default',
        ]);
        self::assertNull($data['hook_key']);
        self::assertNull($data['hook_version']);
        self::assertNull($data['hook_settings']);
    }

    public function test_binding_from_prompt_phase1_int_maps_to_semver(): void
    {
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 9, 'hook_key' => 'article.title_suggestion', 'hook_version' => 1]);
        $binding = PromptHookBinding::tryFromPrompt($prompt);
        self::assertNotNull($binding);
        self::assertSame('0.1.0', $binding->hookVersion);
    }

    public function test_definition_declares_markdown_sections_and_legacy_template(): void
    {
        $definition = $this->catalog()->find('article.outline.generate', '0.1.0');
        self::assertTrue($definition->outputSchema->isMarkdownSections());
        self::assertSame('legacy_prompt_content', $definition->template['source'] ?? null);
        self::assertSame('0.1.0', $definition->version->toString());
        self::assertCount(2, $definition->outputSchema->sections);
        self::assertSame('task_1_outline', $definition->outputSchema->sections[0]['output_port'] ?? null);
        self::assertSame('task_2_vocabulary', $definition->outputSchema->sections[1]['output_port'] ?? null);
        self::assertSame('total', $definition->outputSchema->totalPort);
        self::assertTrue(PromptHookFormSchema::usesLegacyPromptTemplate('article.outline.generate', '0.1.0'));
    }

    public function test_explicit_binding_calls_provider_once_with_section_ports(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $raw = $this->validTwoSectionOutput();
        $provider = new FakePromptProviderAdapter(['text' => $raw]);
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
        $runner->expects(self::once())
            ->method('compilePrompt')
            ->willReturn('LEGACY COMPILED OUTLINE PROMPT');
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 42,
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => 'Do not change this legacy body',
        ]);

        $result = $executor->execute($prompt, [
            'post_title' => 'SEO guide',
            'keyword' => 'seo',
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
        ]);

        self::assertCount(1, $provider->calls);
        self::assertSame('LEGACY COMPILED OUTLINE PROMPT', $provider->calls[0]->messages[0]['content'] ?? null);
        self::assertSame(PromptHookExecutionIntent::ExplicitBinding->value, $result['execution_source']);
        self::assertSame($raw, $result['output']);
        self::assertSame($raw, $result['ports']['total'] ?? null);
        self::assertStringContainsString('Outline body', $result['ports']['task_1_outline'] ?? '');
        self::assertStringContainsString('term: meaning', $result['ports']['task_2_vocabulary'] ?? '');
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result['ports']['task_1_outline'] ?? '');
        self::assertStringNotContainsString('[START_TASK_2_VOCABULARY]', $result['ports']['task_2_vocabulary'] ?? '');
        self::assertStringContainsString('[START_TASK_1_OUTLINE]', $result['ports']['total'] ?? '');
    }

    public function test_explicit_binding_accepts_keyword_only_without_invalid_input(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $raw = $this->validTwoSectionOutput();
        $provider = new FakePromptProviderAdapter(['text' => $raw]);
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
        $runner->expects(self::once())
            ->method('compilePrompt')
            ->willReturn('LEGACY COMPILED OUTLINE PROMPT');
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 43,
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => 'outline body',
        ]);

        $result = $executor->execute($prompt, [
            'keyword' => 'nghệ thuật Typography',
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
        ]);

        self::assertCount(1, $provider->calls);
        self::assertSame($raw, $result['output']);
    }

    public function test_explicit_binding_accepts_title_only_without_invalid_input(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $raw = $this->validTwoSectionOutput();
        $provider = new FakePromptProviderAdapter(['text' => $raw]);
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
        $runner->expects(self::once())
            ->method('compilePrompt')
            ->willReturn('LEGACY COMPILED OUTLINE PROMPT');
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 44,
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => 'outline body',
        ]);

        $result = $executor->execute($prompt, [
            'post_title' => 'Cách chọn balo laptop',
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
        ]);

        self::assertCount(1, $provider->calls);
        self::assertSame($raw, $result['output']);
    }

    public function test_explicit_binding_rejects_when_both_post_title_and_keyword_missing(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $provider = new FakePromptProviderAdapter(['text' => 'x']);
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
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $this->createMock(PromptRunnerService::class),
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 45,
            'hook_key' => 'article.outline.generate',
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => 'outline body',
        ]);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Missing required hook input [post_title|keyword].');
        $executor->execute($prompt, [
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
        ]);
    }

    public function test_eloquent_input_rejected(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', ['article.outline.generate']);

        $provider = new FakePromptProviderAdapter(['text' => 'x']);
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
            new InMemoryPromptHookBudgetGuard(new InMemoryPromptBudgetStore),
            new PromptHookAuditRecorder,
            new PromptHookMigrationFlags,
        );
        $runner = $this->createMock(PromptRunnerService::class);
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 1, 'hook_key' => 'article.outline.generate', 'hook_version' => '0.1.0']);

        $this->expectException(InvalidInput::class);
        $executor->execute($prompt, [
            'post_title' => 'ok',
            'keyword' => new class extends Model {},
        ]);
    }

    public function test_output_maps_to_node_port_shape(): void
    {
        $ports = [
            'task_1_outline' => 'outline only',
            'task_2_vocabulary' => 'vocab only',
            'total' => 'full with markers',
        ];
        $outputs = [
            'out_main' => $ports['total'],
            'out_task_1_outline' => $ports['task_1_outline'],
            'out_task_2_vocabulary' => $ports['task_2_vocabulary'],
            'out_task_1' => $ports['task_1_outline'],
            'out_task_2' => $ports['task_2_vocabulary'],
        ];
        self::assertSame('outline only', $outputs['out_task_1']);
        self::assertSame('vocab only', $outputs['out_task_2']);
        self::assertSame('full with markers', $outputs['out_main']);
    }

    public function test_global_migration_still_legacy_by_default(): void
    {
        Config::set('seo-content-ai.prompt_hooks.migration.article.outline.generate', 'legacy');
        self::assertSame('legacy', (new PromptHookMigrationFlags)->mode('article.outline.generate')->value);
    }
}
