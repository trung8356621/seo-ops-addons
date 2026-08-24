<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiCallRawDetailService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryArtifactRef;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ViewArticlePrompts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ArticleAiCallRawDetailTest extends TestCase
{
    public function test_resolve_raw_prompt_prefers_compiled_prompt(): void
    {
        $result = $this->makePromptResult(
            inputSnapshot: ['compiled_prompt' => 'PROMPT ABC'],
            outputText: 'OUTPUT XYZ',
        );

        self::assertSame('PROMPT ABC', ArticleAiCallRawDetailService::resolveRawPromptText($result));
        self::assertSame('OUTPUT XYZ', ArticleAiCallRawDetailService::resolveRawOutputText($result));
    }

    public function test_resolve_raw_prompt_falls_back_to_markdown_content(): void
    {
        $prompt = new SeoPrompt;
        $prompt->markdown_content = 'MARKDOWN TEMPLATE';

        $result = $this->makePromptResult(
            inputSnapshot: [],
            outputText: 'OUTPUT XYZ',
            prompt: $prompt,
        );

        self::assertSame('MARKDOWN TEMPLATE', ArticleAiCallRawDetailService::resolveRawPromptText($result));
    }

    public function test_artifact_ref_encodes_prompt_result_id_for_call_identity(): void
    {
        self::assertSame('pr:123', ArticleAiHistoryArtifactRef::encodePromptResult(123));
        self::assertSame(123, ArticleAiHistoryArtifactRef::parse('pr:123')['prompt_result_id'] ?? null);
    }

    public function test_view_article_prompts_exposes_raw_ai_call_loader(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ViewArticlePrompts::class))->getFileName());
        self::assertStringContainsString('loadRawAiCallDetail', $src);
        self::assertStringContainsString('rawAiCallDetail', $src);
    }

    public function test_blade_uses_raw_ai_call_detail_not_apply_preview(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'),
        );
        self::assertStringContainsString('openRawAiCall', $blade);
        self::assertStringContainsString('loadRawAiCallDetail', $blade);
        self::assertStringContainsString('seo-run-history-page--workflow-tool', $blade);
        self::assertStringContainsString('seo-execution-history-workspace', $blade);
        self::assertStringNotContainsString('$wire.loadPreview($event.detail.ref)', $blade);
    }

    public function test_execution_history_ai_call_payload_includes_artifact_ref(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ArticleExecutionHistoryService::class))->getFileName(),
        );
        self::assertStringContainsString("'artifact_ref'", $src);
        self::assertStringContainsString("'prompt_result_id'", $src);
    }

    /**
     * @param  array<string, mixed>  $inputSnapshot
     */
    private function makePromptResult(array $inputSnapshot, string $outputText, ?SeoPrompt $prompt = null): PromptResult
    {
        $result = new PromptResult;
        $result->forceFill([
            'input_snapshot' => $inputSnapshot,
            'output_text' => $outputText,
        ]);
        if ($prompt instanceof SeoPrompt) {
            $result->setRelation('prompt', $prompt);
        }

        return $result;
    }
}
