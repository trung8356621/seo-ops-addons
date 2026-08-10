<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCompositionPreviewService;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptCompositionSummaryPresenter;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptRuntimeRulesPresenter;
use Illuminate\Support\HtmlString;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PromptCompositionSummaryPresenterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_default_shows_runtime_rules_without_compose(): void
    {
        $rules = Mockery::mock(PromptRuntimeRulesPresenter::class);
        $rules->shouldReceive('renderHtml')
            ->once()
            ->with('article.faq.generate', '0.1.0')
            ->andReturn(new HtmlString('<div>Output Contract</div>'));

        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);
        $compose->shouldReceive('preview')->never();
        $compose->shouldReceive('formatPreviewHtml')->never();

        $html = (new PromptCompositionSummaryPresenter($rules, $compose))
            ->renderHtml('article.faq.generate', '0.1.0', "# Role\nHello user prompt", [], false)
            ->toHtml();

        self::assertStringContainsString('Output Contract', $html);
        self::assertStringNotContainsString('Hello user prompt', $html);
    }

    public function test_expanded_calls_full_compose(): void
    {
        $rules = Mockery::mock(PromptRuntimeRulesPresenter::class);
        $rules->shouldReceive('renderHtml')->never();

        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);
        $compose->shouldReceive('preview')->once()->andReturn([
            'content_mode' => 'legacy_prompt_content',
            'final_prompt' => 'FULL MERGED',
            'segments' => [],
            'unused_markdown' => false,
            'markdown_preserved' => true,
        ]);
        $compose->shouldReceive('formatPreviewHtml')->once()->andReturn('<pre>FULL MERGED</pre>');

        $html = (new PromptCompositionSummaryPresenter($rules, $compose))
            ->renderHtml('x', '0.1.0', 'md', [], true)
            ->toHtml();

        self::assertStringContainsString('FULL MERGED', $html);
        self::assertStringContainsString('Debug', $html);
    }

    public function test_no_hook_delegates_to_runtime_rules(): void
    {
        $rules = Mockery::mock(PromptRuntimeRulesPresenter::class);
        $rules->shouldReceive('renderHtml')
            ->once()
            ->with('', '')
            ->andReturn(new HtmlString('<p>No Hook selected</p>'));

        $compose = Mockery::mock(PromptHookCompositionPreviewService::class);
        $compose->shouldReceive('preview')->never();

        $html = (new PromptCompositionSummaryPresenter($rules, $compose))
            ->renderHtml('', '', 'Only markdown', [], false)
            ->toHtml();

        self::assertStringContainsString('No Hook selected', $html);
        self::assertStringNotContainsString('Only markdown', $html);
    }
}
