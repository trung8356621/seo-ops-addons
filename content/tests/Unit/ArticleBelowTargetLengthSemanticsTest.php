<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

final class ArticleBelowTargetLengthSemanticsTest extends TestCase
{
    public function test_target_501_actual_479_is_success_with_warning(): void
    {
        $text = trim(str_repeat('word ', 479));
        $out = $this->pipeline()->process(
            $this->markdownWordsDefinition(300),
            ['text' => $text],
            null,
            ['article_length' => 501],
        );

        self::assertSame('accepted_with_warning', $out['length_validation']['length_validation_result'] ?? null);
        self::assertSame('success_with_warning', $out['length_validation']['outcome'] ?? null);
        self::assertSame(ArticleGenerationLengthValidator::WARNING_BELOW_TARGET, $out['length_validation']['warning_code'] ?? null);
        self::assertSame(479, $out['length_validation']['actual_words'] ?? null);
        self::assertSame(501, $out['length_validation']['target_words'] ?? null);
        self::assertSame(300, $out['length_validation']['hard_floor_words'] ?? null);
        self::assertContains(ArticleGenerationLengthValidator::UI_WARNING, $out['warnings'] ?? []);
    }

    public function test_prompt_runner_failover_gate_does_not_throw_for_usable_short_article(): void
    {
        $text = trim(str_repeat('word ', 479));
        $method = new ReflectionMethod(PromptRunnerService::class, 'assertArticleRouteOutputEligibleForFailover');
        $runner = (new \ReflectionClass(PromptRunnerService::class))->newInstanceWithoutConstructor();

        $result = $method->invoke($runner, $text, [], ['article_length' => 501]);

        self::assertIsArray($result);
        self::assertSame('success_with_warning', $result['outcome'] ?? null);
    }

    public function test_true_provider_max_token_truncation_still_fails(): void
    {
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('OUTPUT_TRUNCATED: provider terminal reason=output_truncated');
        $this->pipeline()->process(
            $this->markdownWordsDefinition(300),
            [
                'text' => trim(str_repeat('word ', 600)),
                'finish_reason' => 'length',
            ],
            null,
            ['article_length' => 501],
        );
    }

    public function test_below_hard_floor_still_fails_and_keeps_failover_exception(): void
    {
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('ARTICLE_BELOW_HARD_FLOOR');
        $this->pipeline()->process(
            $this->markdownWordsDefinition(300),
            ['text' => trim(str_repeat('word ', 250))],
            null,
            ['article_length' => 501],
        );
    }

    public function test_valid_target_length_article_is_normal_success(): void
    {
        $text = trim(str_repeat('word ', 501));
        $out = $this->pipeline()->process(
            $this->markdownWordsDefinition(300),
            ['text' => $text],
            null,
            ['article_length' => 501],
        );

        self::assertSame('accepted', $out['length_validation']['length_validation_result'] ?? null);
        self::assertSame('success', $out['length_validation']['outcome'] ?? null);
        self::assertNull($out['length_validation']['warning_code'] ?? null);
        self::assertSame([], $out['warnings'] ?? []);
    }

    public function test_warning_does_not_bypass_persist_hash_gate(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(ArticleWritingExecutionService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('ensureGeneratedContentPersisted', $src);
        self::assertStringNotContainsString("warning_code === 'ARTICLE_BELOW_TARGET_LENGTH'", $src);
        self::assertStringContainsString('PERSIST_FAILED', $src);
        $persistPos = strpos($src, 'ensureGeneratedContentPersisted');
        $warningHelperPos = strpos($src, 'successMessage');
        self::assertNotFalse($persistPos);
        self::assertNotFalse($warningHelperPos);
    }

    public function test_router_attempt_still_throws_inside_planned_route_for_hard_fail_only(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/ai-prompt/src/Services/PromptRunnerService.php',
        );
        self::assertStringContainsString('assertArticleRouteOutputEligibleForFailover', $src);
        self::assertStringContainsString('ARTICLE_BELOW_HARD_FLOOR', (string) file_get_contents(
            (new \ReflectionClass(ArticleGenerationLengthValidator::class))->getFileName() ?: '',
        ));
        self::assertStringContainsString('isWarningResult', $src);
        self::assertStringContainsString('preferPaidAfterOutputTruncation', (string) file_get_contents(
            dirname(__DIR__, 3).'/ai-prompt/src/Services/AiModelRouterService.php',
        ));
        $routerSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/ai-prompt/src/Services/AiModelRouterService.php',
        );
        self::assertStringContainsString('if ($exception instanceof \\Omnichannel\\Addons\\AiPrompt\\PromptHooks\\Exceptions\\OutputTruncated)', $routerSrc);
    }

    public function test_word_count_helper_matches_479(): void
    {
        self::assertSame(479, PromptTextMetrics::wordCount(trim(str_repeat('word ', 479))));
    }

    private function pipeline(): PromptHookRuntimeOutputPipeline
    {
        return new PromptHookRuntimeOutputPipeline;
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
