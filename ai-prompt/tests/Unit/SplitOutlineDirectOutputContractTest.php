<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\FakePromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
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
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Markerless split contract: direct Outline/Vocabulary output + retry checkpoint semantics.
 */
final class SplitOutlineDirectOutputContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function pad(string $prefix, int $min = 120): string
    {
        return $prefix.' '.str_repeat('x', $min);
    }

    private function outlineBody(): string
    {
        return $this->pad("# Outline H1\n## Intro\n## Body\n## FAQ");
    }

    private function vocabularyBody(): string
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

    private function vocabularyWithReasoningMentioningMarkers(): string
    {
        $reasoning = "We must wrap output in [START_TASK_2_VOCABULARY] and [END_TASK_2_VOCABULARY] before returning.\n\n";

        return $reasoning.$this->vocabularyBody();
    }

    /**
     * @param  list<array<string, mixed>|\Throwable>  $queue
     */
    private function splitExecutor(array $queue): ArticleOutlineVocabularySplitExecutor
    {
        $settings = (new ReflectionClass(SeoCreateArticleSettingsService::class))
            ->newInstanceWithoutConstructor();

        return new ArticleOutlineVocabularySplitExecutor(
            new QueuedPromptHookBindingRunner($queue),
            $settings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeWithPrompts(SeoPrompt $outline, SeoPrompt $vocab): array
    {
        return [
            'outline_prompt' => $outline,
            'vocabulary_prompt' => $vocab,
        ];
    }

    private function fakePrompt(int $id, string $hookKey): SeoPrompt
    {
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => $id,
            'hook_key' => $hookKey,
            'hook_version' => '0.1.0',
            'name' => 'test-'.$id,
            'markdown_content' => $hookKey === ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK
                ? DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN
                : DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN,
        ]);

        return $prompt;
    }

    public function test_m_outline_direct_output_without_markers(): void
    {
        $body = $this->outlineBody();
        $outlinePrompt = $this->fakePrompt(22, ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $vocabPrompt = $this->fakePrompt(23, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);

        $executor = $this->splitExecutor([
            ['output' => $body, 'prompt_result_id' => 1001, 'correlation_id' => 'c1', 'model' => 'm1'],
            ['output' => $this->vocabularyBody(), 'prompt_result_id' => 1002, 'correlation_id' => 'c2', 'model' => 'm2'],
        ]);
        $result = $executor->execute(
            $this->nodeWithPrompts($outlinePrompt, $vocabPrompt),
            $outlinePrompt,
            ['input' => 'kw'],
            ['article_id' => 1],
        );

        self::assertSame('completed', $result['status']);
        self::assertSame($body, $result['sections']['outline']);
        self::assertSame($body, $result['ports']['task_1_outline']);
        self::assertStringNotContainsString('Mismatched', $result['message']);
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result['ports']['task_1_outline']);
        self::assertTrue($result['outline_ai_invoked']);
        self::assertTrue($result['vocabulary_ai_invoked']);
    }

    public function test_n_vocabulary_direct_output_without_markers(): void
    {
        $vocab = $this->vocabularyBody();
        $outlinePrompt = $this->fakePrompt(22, ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $vocabPrompt = $this->fakePrompt(23, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);

        $executor = $this->splitExecutor([
            ['output' => $this->outlineBody(), 'prompt_result_id' => 1, 'correlation_id' => 'c1'],
            ['output' => $vocab, 'prompt_result_id' => 2, 'correlation_id' => 'c2'],
        ]);
        $result = $executor->execute(
            $this->nodeWithPrompts($outlinePrompt, $vocabPrompt),
            $outlinePrompt,
            ['input' => 'kw'],
            [],
        );

        self::assertSame('completed', $result['status']);
        self::assertSame($vocab, $result['sections']['vocabulary']);
        self::assertSame($vocab, $result['ports']['task_2_vocabulary']);
        self::assertStringNotContainsString('[START_TASK_2_VOCABULARY]', $result['ports']['task_2_vocabulary']);
    }

    public function test_o_reasoning_mention_markers_still_validates_final_content(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.migration.explicit_binding_enabled', true);

        $raw = $this->vocabularyWithReasoningMentioningMarkers();
        [$executor] = $this->hookExecutor($raw);

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 23,
            'hook_key' => ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK,
            'hook_version' => '0.1.0',
            'hook_settings' => [],
            'markdown_content' => DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN,
        ]);

        $result = $executor->execute($prompt, [
            'input' => 'kw',
            'post_title' => 'SEO guide',
            'outline' => $this->outlineBody(),
            'language' => 'vi',
        ], [
            'site_id' => 1,
            'locale' => 'vi',
            'outline_subtask' => 'vocabulary',
        ]);

        self::assertStringContainsString('### Holonymy', $result['output'] ?? '');
        self::assertGreaterThan(100, mb_strlen((string) ($result['output'] ?? '')));
    }

    public function test_p_outline_success_vocab_fail_preserves_outline_status(): void
    {
        $outlinePrompt = $this->fakePrompt(22, ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $vocabPrompt = $this->fakePrompt(23, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);
        $outlineBody = $this->outlineBody();

        $executor = $this->splitExecutor([
            ['output' => $outlineBody, 'prompt_result_id' => 501, 'correlation_id' => 'o1'],
            (new PromptHookFailure(PromptHookFailureCode::InvalidOutput, 'Section shorter than min_length'))
                ->bindPromptResultId(502),
        ]);
        $result = $executor->execute(
            $this->nodeWithPrompts($outlinePrompt, $vocabPrompt),
            $outlinePrompt,
            ['input' => 'kw'],
            [],
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('completed', $result['outline_status']);
        self::assertSame('failed', $result['vocabulary_status']);
        self::assertSame('vocabulary_failed', $result['outline_subtask']);
        self::assertSame($outlineBody, $result['sections']['outline']);
        self::assertTrue($result['outline_ai_invoked']);
        self::assertTrue($result['vocabulary_ai_invoked']);
        self::assertSame(501, $result['outline_result']['prompt_result_id']);
    }

    public function test_q_retry_reuses_outline_and_only_calls_vocabulary(): void
    {
        $outlinePrompt = $this->fakePrompt(22, ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK);
        $vocabPrompt = $this->fakePrompt(23, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK);
        $outlineBody = $this->outlineBody();

        $executor = $this->splitExecutor([
            ['output' => $this->vocabularyBody(), 'prompt_result_id' => 602, 'correlation_id' => 'v2', 'model' => 'm'],
        ]);
        $result = $executor->execute(
            $this->nodeWithPrompts($outlinePrompt, $vocabPrompt),
            $outlinePrompt,
            ['input' => 'kw'],
            [
                'reused_outline_markdown' => $outlineBody,
                'reused_outline_prompt_result_id' => 501,
            ],
        );

        self::assertSame('completed', $result['status']);
        self::assertFalse($result['outline_ai_invoked']);
        self::assertTrue($result['vocabulary_ai_invoked']);
        self::assertSame($outlineBody, $result['sections']['outline']);
        self::assertTrue((bool) ($result['outline_result']['reused'] ?? false));
    }

    public function test_r_legacy_assembler_wraps_markers_in_code(): void
    {
        $executor = (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();
        $ports = $executor->assemblePorts('OUTLINE BODY', 'VOCAB BODY');

        self::assertSame('OUTLINE BODY', $ports['task_1_outline']);
        self::assertSame('VOCAB BODY', $ports['task_2_vocabulary']);
        self::assertSame(
            ArticleGenerationInputResolver::OUTLINE_START."\nOUTLINE BODY\n".ArticleGenerationInputResolver::OUTLINE_END
            ."\n\n"
            .ArticleGenerationInputResolver::VOCABULARY_START."\nVOCAB BODY\n".ArticleGenerationInputResolver::VOCABULARY_END,
            $ports['total'],
        );
    }

    public function test_prompts_and_hooks_are_markerless(): void
    {
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN);
        self::assertStringNotContainsString('START_TASK_2_VOCABULARY', DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN);
        self::assertStringContainsString('{{input}}', DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN);
        self::assertStringContainsString('Không bọc START/END marker', DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN);

        $outlineSpec = json_decode((string) file_get_contents(
            dirname(__DIR__, 2).'/resources/prompt-hooks/v01/article.outline.structure.generate@0.1.0.json',
        ), true);
        $vocabSpec = json_decode((string) file_get_contents(
            dirname(__DIR__, 2).'/resources/prompt-hooks/v01/article.vocabulary.generate@0.1.0.json',
        ), true);
        self::assertSame('markdown', $outlineSpec['output_schema']['type']);
        self::assertSame('markdown', $vocabSpec['output_schema']['type']);
        self::assertArrayNotHasKey('sections', $outlineSpec['output_schema']);
        self::assertArrayNotHasKey('sections', $vocabSpec['output_schema']);
    }

    public function test_pipeline_validates_direct_markdown_not_markers(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $definition = $registry->get(ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK, '0.1.0');
        $pipeline = new PromptHookRuntimeOutputPipeline;

        $out = $pipeline->process($definition, [
            'text' => $this->vocabularyWithReasoningMentioningMarkers(),
        ]);

        self::assertSame('markdown', $out['type']);
        self::assertStringContainsString('### Holonymy', (string) $out['value']);
        self::assertArrayNotHasKey('sections', $out);
    }

    public function test_runner_checkpoint_source_contracts(): void
    {
        $runnerSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString('SPLIT_OUTLINE_CHECKPOINT_META', $runnerSource);
        self::assertStringContainsString('reused_outline_markdown', $runnerSource);
        self::assertStringContainsString('persistSplitOutlineCheckpoint', $runnerSource);
        self::assertStringContainsString('clearSplitOutlineCheckpoint', $runnerSource);
        self::assertStringContainsString('input_fingerprint', $runnerSource);
        self::assertStringContainsString('splitOutlineCheckpointIdentity', $runnerSource);
        self::assertStringContainsString("'outline_status'", $runnerSource);
        self::assertStringContainsString("'vocabulary_status'", $runnerSource);
    }

    /**
     * @return array{0: PromptHookExplicitBindingExecutor, 1: FakePromptProviderAdapter}
     */
    private function hookExecutor(string $providerText): array
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowed', true);
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', [
            ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK,
            ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK,
        ]);
        Config::set('seo-content-ai.prompt_hooks.migration.explicit_binding_enabled', true);

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
            ->willReturn(DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN);
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
}

/**
 * @internal
 */
final class QueuedPromptHookBindingRunner implements PromptHookBindingRunner
{
    /** @var list<array<string, mixed>|\Throwable> */
    private array $queue;

    /**
     * @param  list<array<string, mixed>|\Throwable>  $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = array_values($queue);
    }

    public function execute(
        SeoPrompt $prompt,
        array $variables = [],
        array $contextExtras = [],
        array $previousOutputs = [],
    ): array {
        if ($this->queue === []) {
            throw new \RuntimeException('QueuedPromptHookBindingRunner exhausted.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
