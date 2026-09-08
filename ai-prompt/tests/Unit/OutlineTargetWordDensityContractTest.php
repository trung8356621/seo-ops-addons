<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Outline receives authoritative article_length for heading-density guidance.
 * No hard H2/H3 validation — prompt/heuristic only.
 */
final class OutlineTargetWordDensityContractTest extends TestCase
{
    public function test_outline_hook_schema_declares_optional_article_length(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $def = (new PromptHookRuntimeRegistry($loader))->get('article.outline.structure.generate', '0.1.0');
        $fields = $def->inputSchema->fields;

        self::assertArrayHasKey('article_length', $fields);
        self::assertFalse((bool) ($fields['article_length']['required'] ?? false));
        self::assertTrue((bool) ($fields['article_length']['nullable'] ?? false));
        self::assertSame('integer', (string) ($fields['article_length']['type'] ?? ''));
    }

    public function test_map_input_receives_article_length_from_runtime(): void
    {
        $input = $this->mapOutlineInput([
            'input' => 'máy lọc nước',
            'article_length' => '1000',
        ]);

        self::assertSame(1000, $input['article_length'] ?? null);
        self::assertSame('máy lọc nước', $input['input'] ?? null);
    }

    public function test_map_input_uses_item_target_words_alias(): void
    {
        $input = $this->mapOutlineInput([
            'input' => 'máy lọc nước',
            '_item_content_length_target_words' => '2000',
        ]);

        self::assertSame(2000, $input['article_length'] ?? null);
    }

    public function test_missing_article_length_does_not_break_outline_input(): void
    {
        $input = $this->mapOutlineInput([
            'input' => 'máy lọc nước',
            'keyword' => 'máy lọc nước',
        ]);

        self::assertSame('máy lọc nước', $input['input'] ?? null);
        // Optional nullable field may be present as null — must not fail generation.
        self::assertTrue(! array_key_exists('article_length', $input) || $input['article_length'] === null);
    }

    public function test_compile_mirrors_target_words_from_article_length(): void
    {
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'expandCompileAliasMirrors');
        $method->setAccessible(true);
        $executor = (new \ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();

        /** @var array<string, mixed> $compiled */
        $compiled = $method->invoke($executor, [
            'input' => 'topic',
            'article_length' => 1000,
        ]);

        self::assertSame(1000, $compiled['article_length'] ?? null);
        self::assertSame('1000', (string) ($compiled['target_words'] ?? ''));
    }

    public function test_default_outline_markdown_renders_target_length_guidance(): void
    {
        $md = DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN;
        self::assertStringContainsString('{{article_length}}', $md);
        self::assertStringContainsString('{{input}}', $md);
        self::assertStringContainsString('[MỞ BÀI — KHÔNG HEADING]', $md);
        self::assertStringContainsString('Không tạo H3 trừ khi', $md);
        self::assertStringContainsString('Không tạo heading kiểu', $md);
        self::assertStringNotContainsString('max_h3', strtolower($md));
        self::assertStringNotContainsString('START_TASK_1_OUTLINE', $md);

        $rendered1000 = str_replace(
            ['{{input}}', '{{article_length}}', '{{language}}'],
            ['máy lọc nước RO', '1000', 'vi'],
            $md,
        );
        $rendered2000 = str_replace(
            ['{{input}}', '{{article_length}}', '{{language}}'],
            ['máy lọc nước RO', '2000', 'vi'],
            $md,
        );

        self::assertStringContainsString('Độ dài bài mục tiêu (số từ): 1000', $rendered1000);
        self::assertStringContainsString('Độ dài bài mục tiêu (số từ): 2000', $rendered2000);
        self::assertStringContainsString('800–1200 từ', $rendered1000);
        self::assertNotSame($rendered1000, $rendered2000);
    }

    public function test_no_backend_hard_cap_for_h2_h3_in_outline_path(): void
    {
        $executorSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        $bindingSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
        );

        self::assertStringNotContainsString('max_h3', strtolower($executorSrc));
        self::assertStringNotContainsString('max_h2', strtolower($executorSrc));
        self::assertStringNotContainsString('max_h3', strtolower($bindingSrc));
        self::assertStringNotContainsString('if target_words', $bindingSrc);
    }

    public function test_outline_density_refresh_method_exists(): void
    {
        self::assertTrue(method_exists(
            DefaultSplitOutlinePromptsInstaller::class,
            'refreshOutlineTargetWordDensityContract',
        ));
        self::assertSame('{{article_length}}', DefaultSplitOutlinePromptsInstaller::OUTLINE_DENSITY_SIGNATURE);
        self::assertFileExists(
            dirname(__DIR__, 2).'/database/migrations/2026_09_08_120000_refresh_outline_target_word_density_contract.php',
        );
    }

    public function test_prompt_runner_still_omits_writing_pass_mode_for_outline(): void
    {
        $method = new ReflectionMethod(PromptRunnerService::class, 'strategySnapshotFields');
        $method->setAccessible(true);
        $service = $this->app->make(PromptRunnerService::class);

        $fields = $method->invoke($service, [
            'article_length' => '1000',
            'hook_key' => 'article.outline.structure.generate',
        ]);

        self::assertArrayNotHasKey('strategy_resolved', $fields);
        self::assertArrayNotHasKey('generation_strategy', $fields);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mapOutlineInput(array $variables): array
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $def = (new PromptHookRuntimeRegistry($loader))->get('article.outline.structure.generate', '0.1.0');

        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'mapInput');
        $method->setAccessible(true);
        $executor = (new \ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();

        /** @var array<string, mixed> $input */
        $input = $method->invoke($executor, $def->inputSchema->fields, $variables, []);

        return $input;
    }
}
