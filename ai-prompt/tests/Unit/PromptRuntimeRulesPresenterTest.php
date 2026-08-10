<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptRuntimeRulesPresenter;
use PHPUnit\Framework\TestCase;

final class PromptRuntimeRulesPresenterTest extends TestCase
{
    private function presenter(): PromptRuntimeRulesPresenter
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();

        return new PromptRuntimeRulesPresenter(
            new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader)),
            new PromptOutputContractResolver(
                new PromptOutputContractCatalog(PromptOutputContractCatalog::defaultDirectory()),
            ),
        );
    }

    public function test_renders_output_contract_and_validation_without_user_prompt(): void
    {
        $html = $this->presenter()
            ->renderHtml('article.content.generate', '0.1.0')
            ->toHtml();

        self::assertStringContainsString('Output Contract', $html);
        self::assertStringContainsString('MARKDOWN ARTICLE OUTPUT CONTRACT', $html);
        self::assertStringContainsString('Validation', $html);
        self::assertStringContainsString('minimum_length: 300', $html);
        self::assertStringContainsString('reject_provider_preamble: true', $html);
        self::assertStringContainsString('normalize: trim', $html);
        self::assertStringContainsString('retry.max: 1', $html);
        self::assertStringContainsString('Runtime', $html);
        self::assertStringContainsString('temperature: 0.6', $html);
        self::assertStringContainsString('Source Rules', $html);
        self::assertStringContainsString('outline:', $html);
        self::assertStringContainsString('Input Schema', $html);
        self::assertStringNotContainsString('Hello user wrote this', $html);
    }

    public function test_empty_hook_message(): void
    {
        $html = $this->presenter()
            ->renderHtml('', '')
            ->toHtml();

        self::assertStringContainsString('No Hook selected', $html);
    }
}
