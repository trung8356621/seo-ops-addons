<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use PHPUnit\Framework\TestCase;

final class ArticleLengthWordsValidationTest extends TestCase
{
    public function test_word_count_matches_editor_whitespace_split(): void
    {
        self::assertSame(0, PromptTextMetrics::wordCount(''));
        self::assertSame(3, PromptTextMetrics::wordCount("  a  b\nc  "));
        self::assertSame(5, PromptTextMetrics::wordCount('một hai ba bốn năm'));
    }

    public function test_minimum_acceptable_words_uses_floor_not_target(): void
    {
        $validator = new ArticleGenerationLengthValidator;
        self::assertSame(1400, $validator->configuredMinimum());
        self::assertSame(1400, $validator->minimumForTarget(2000));
        self::assertSame(1400, $validator->minimumForTarget(3000));
        self::assertSame(1000, $validator->minimumForTarget(1000));
        self::assertSame(300, $validator->minimumForTarget(300));
        self::assertSame(1400, PromptTextMetrics::minWordsFromArticleLength(2000));
        self::assertSame(1000, PromptTextMetrics::minWordsFromArticleLength(1000));
        self::assertSame(300, PromptTextMetrics::minWordsFromArticleLength(0));
    }

    public function test_prompt_variables_resolve_product_1000_and_other_2000(): void
    {
        $service = SeoPromptSettingsService::withDefaults();

        self::assertSame('1000', $service->promptVariables('product')['article_length']);
        self::assertSame('2000', $service->promptVariables('article')['article_length']);
        self::assertSame('2000', $service->promptVariables('post')['article_length']);
        self::assertSame(1000, $service->resolveArticleLengthTarget('product'));
        self::assertSame(2000, $service->resolveArticleLengthTarget('article'));
    }

    public function test_content_hooks_declare_length_unit_words(): void
    {
        $dir = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01';
        foreach (['article.content.generate@0.1.0.json', 'article.content.rewrite@0.1.0.json'] as $file) {
            $json = json_decode((string) file_get_contents($dir.'/'.$file), true);
            self::assertIsArray($json);
            self::assertSame('words', $json['output_schema']['validation']['length_unit'] ?? null, $file);
            self::assertSame('words', $json['metadata']['article_length_unit'] ?? null, $file);
        }
    }

    public function test_output_pipeline_words_rejects_short_even_if_many_chars(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $words = [];
        for ($i = 0; $i < 60; $i++) {
            $words[] = 'từkhoádài'.str_repeat('x', 5);
        }
        $text = implode(' ', $words);
        self::assertGreaterThan(400, mb_strlen($text));
        self::assertSame(60, PromptTextMetrics::wordCount($text));

        $this->expectException(OutputTruncated::class);
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_output_pipeline_words_passes_at_exact_article_length_when_below_floor(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1000));
        self::assertSame(1000, PromptTextMetrics::wordCount($text));

        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
        self::assertSame($text, $out['value']);
        self::assertSame('accepted', $out['length_validation']['length_validation_result'] ?? null);
        self::assertSame(1000, $out['length_validation']['minimum_acceptable_words'] ?? null);
        self::assertSame(1000, $out['length_validation']['target_article_length'] ?? null);
    }

    public function test_output_pipeline_words_fails_below_clamped_minimum(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 999));
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('actual: 999 words, minimum: 1000 words, target: 1000 words');
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_gemini_bulkget_1534_words_target_2000_passes(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1534));
        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 2000]);
        self::assertSame('accepted', $out['length_validation']['length_validation_result']);
        self::assertSame(1534, $out['length_validation']['actual_word_count']);
        self::assertSame(1400, $out['length_validation']['minimum_acceptable_words']);
        self::assertSame(2000, $out['length_validation']['target_article_length']);
    }

    public function test_output_pipeline_words_passes_at_minimum_1400(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1400));
        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 2000]);
        self::assertSame('accepted', $out['length_validation']['length_validation_result']);
    }

    public function test_output_pipeline_words_fails_at_1399(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1399));
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('actual: 1399 words, minimum: 1400 words, target: 2000 words');
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 2000]);
    }

    public function test_output_pipeline_words_target_3000_actual_1500_passes(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1500));
        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 3000]);
        self::assertSame('accepted', $out['length_validation']['length_validation_result']);
        self::assertSame(1400, $out['length_validation']['minimum_acceptable_words']);
    }

    public function test_finish_reason_length_fails_even_when_word_count_ok(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1500));
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('Provider output was truncated.');
        $pipeline->process($def, [
            'text' => $text,
            'finish_reason' => 'length',
        ], null, ['article_length' => 2000]);
    }

    public function test_truncated_flag_fails_even_when_word_count_ok(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1500));
        $this->expectException(OutputTruncated::class);
        $pipeline->process($def, [
            'text' => $text,
            'truncated' => true,
        ], null, ['article_length' => 2000]);
    }

    public function test_malformed_json_fails_even_when_long(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $loader = new PromptHookDefinitionLoader(
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01',
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks',
        );
        $def = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.test.json',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => [
                'type' => 'json',
                'validation' => [
                    'not_empty' => true,
                    'json_object' => true,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);

        $this->expectException(InvalidOutput::class);
        $pipeline->process($def, ['text' => '{'.str_repeat('word ', 1500)]);
    }

    public function test_output_pipeline_words_fails_500_words_when_target_1000(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 500));
        $this->expectException(OutputTruncated::class);
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_output_pipeline_chars_still_used_when_unit_missing(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $loader = new PromptHookDefinitionLoader(
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01',
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks',
        );
        $def = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.test.chars',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => [
                'type' => 'text',
                'validation' => [
                    'not_empty' => true,
                    'minimum_length' => 50,
                    'max_length' => 80,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);

        $ok = $pipeline->process($def, ['text' => str_repeat('a', 60)]);
        self::assertSame(60, mb_strlen((string) $ok['value']));

        $this->expectException(InvalidOutput::class);
        $pipeline->process($def, ['text' => str_repeat('a', 90)]);
    }

    public function test_shared_validator_wired_across_generation_paths(): void
    {
        $validator = ArticleGenerationLengthValidator::class;
        $files = [
            'PromptHooks/Output/PromptHookRuntimeOutputPipeline.php',
            'PromptHooks/PromptHookOutputNormalizer.php',
            'PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
            'Services/ArticleWritingExecutionService.php',
            'Services/TaskWorkflowTestRunner.php',
            'Services/ArticleWritingLegacyRewriteAdapter.php',
            'Services/ArticleImproveExecutionService.php',
        ];
        $root = ProjectRoot::addonsPath().'/ai-prompt';
        foreach ($files as $relative) {
            $src = (string) file_get_contents($root.'/'.$relative);
            if ($relative === 'Services/ArticleImproveExecutionService.php') {
                self::assertStringNotContainsString($validator, $src);
                self::assertStringContainsString('Không truyền article_length', $src);
                continue;
            }
            if ($relative === 'Services/ArticleWritingLegacyRewriteAdapter.php') {
                self::assertStringContainsString('article.content.generate', $src);
                self::assertStringNotContainsString('article_length', $src);
                continue;
            }
            if ($relative === 'Services/TaskWorkflowTestRunner.php') {
                self::assertStringContainsString('minimum_acceptable_words', $src);
                self::assertStringContainsString('PromptHookExplicitBindingExecutor', $src);
                continue;
            }
            if ($relative === 'Services/ArticleWritingExecutionService.php') {
                self::assertStringContainsString('length_validation', $src);
                continue;
            }
            if ($relative === 'PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php') {
                self::assertStringContainsString('length_validation', $src);
                self::assertStringContainsString('persistLengthValidationToPromptResult', $src);
                continue;
            }
            self::assertStringContainsString($validator, $src, $relative);
        }

        $config = (string) file_get_contents(dirname(__DIR__, 4).'/config/seo-content-ai.php');
        self::assertStringContainsString("'minimum_acceptable_words'", $config);
        self::assertStringContainsString('1400', $config);
    }

    public function test_improve_hook_has_no_word_length_unit(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01/article.content.improve@0.1.0.json';
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        self::assertArrayNotHasKey('length_unit', $json['output_schema']['validation'] ?? []);
        self::assertArrayNotHasKey('minimum_length', $json['output_schema']['validation'] ?? []);
    }

    private function markdownWordsDefinition(int $minimumLength): \Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition
    {
        $loader = new PromptHookDefinitionLoader(
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01',
            ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks',
        );

        return $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.test.words',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [
                'article_length' => [
                    'type' => 'integer',
                    'required' => false,
                    'nullable' => true,
                ],
            ],
            'output_schema' => [
                'type' => 'markdown',
                'validation' => [
                    'not_empty' => true,
                    'length_unit' => 'words',
                    'minimum_length' => $minimumLength,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);
    }
}
