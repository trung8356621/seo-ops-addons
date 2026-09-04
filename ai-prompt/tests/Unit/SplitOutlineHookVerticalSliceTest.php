<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Split outline hooks: single-task validation (Test B/C/D from split prompt spec).
 */
final class SplitOutlineHookVerticalSliceTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function pad(string $prefix): string
    {
        return $prefix.' '.str_repeat('x', 120);
    }

    private function outlineOnlyOutput(): string
    {
        return $this->pad('# Outline H1 with enough length for validation');
    }

    private function vocabularyOnlyOutput(): string
    {
        $groups = [
            'Holonymy', 'Synonyms', 'Antonyms', 'Long-tail keywords', 'Semantic keywords',
            'Salient keywords', 'Salient LSI keywords', 'Semantic LSI entities', 'Relational entities',
            'Relevant entities', 'Semantic entities', 'Close entities', 'Salient entities', 'Related topics',
            'Unigrams', 'Bigrams', 'Trigrams', 'Quadgrams', 'Quinquigrams',
        ];
        $lines = [];
        foreach ($groups as $group) {
            $lines[] = "### {$group}";
            for ($i = 1; $i <= 5; $i++) {
                $lines[] = "- {$group} item {$i} with enough length for validation";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{0: PromptHookExplicitBindingExecutor, 1: FakePromptProviderAdapter}
     */
    private function executorForHook(string $compiledPrompt, string $providerText): array
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', [
            ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK,
            ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK,
        ]);

        $provider = new FakePromptProviderAdapter(['text' => $providerText]);
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
            ->willReturn($compiledPrompt);
        $executor = new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new \Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter(
                new \Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter,
            ),
        );

        return [$executor, $provider];
    }

    public function test_b_outline_hook_accepts_single_task_output(): void
    {
        $raw = $this->outlineOnlyOutput();
        [$executor] = $this->executorForHook(
            DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN,
            $raw,
        );

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 101,
            'hook_key' => ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK,
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN,
        ]);

        $result = $executor->execute($prompt, [
            'post_title' => 'SEO guide',
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
            'outline_subtask' => 'outline',
        ]);

        self::assertStringContainsString('Outline H1', $result['output'] ?? '');
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result['output'] ?? '');
        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', $result['output'] ?? '');
    }

    public function test_c_vocabulary_hook_accepts_single_task_output_with_headings(): void
    {
        $raw = $this->vocabularyOnlyOutput();
        [$executor] = $this->executorForHook(
            DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN,
            $raw,
        );

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 102,
            'hook_key' => ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK,
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN,
        ]);

        $result = $executor->execute($prompt, [
            'post_title' => 'SEO guide',
            'outline' => "## H1\nIntro section for vocabulary context.",
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
            'outline_subtask' => 'vocabulary',
        ]);

        self::assertStringContainsString('### Holonymy', $result['output'] ?? '');
        self::assertStringNotContainsString('[START_TASK_2_VOCABULARY]', $result['output'] ?? '');
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $result['output'] ?? '');
    }

    public function test_d_split_prompts_do_not_cross_contain_tasks(): void
    {
        $outlineMd = DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN;
        $vocabMd = DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN;

        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', $outlineMd);
        self::assertStringNotContainsString('Holonymy', $outlineMd);
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $vocabMd);
        self::assertStringNotContainsString('Nhiệm vụ: Dàn ý', $vocabMd);
    }
}
