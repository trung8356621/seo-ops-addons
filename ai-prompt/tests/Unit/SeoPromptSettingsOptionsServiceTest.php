<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsOptionsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SeoPromptSettingsOptionsServiceTest extends TestCase
{
    public function test_prompt_options_for_hook_accepts_include_prompt_id(): void
    {
        $method = new ReflectionMethod(SeoPromptSettingsOptionsService::class, 'promptOptionsForHook');
        self::assertSame(2, $method->getNumberOfParameters());
        self::assertTrue($method->getParameters()[1]->allowsNull());
    }

    public function test_ensure_prompt_option_appends_selected_when_missing(): void
    {
        $service = new SeoPromptSettingsOptionsService();
        $method = new ReflectionMethod($service, 'ensurePromptOption');
        $method->setAccessible(true);

        $options = $method->invoke($service, [12 => 'Gen title'], null, 'article.faq.generate');
        self::assertSame([12 => 'Gen title'], $options);
    }

    public function test_workflows_page_wires_get_option_label_using(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/src/Filament/Pages/SeoSettingsWorkflows.php'
        );

        self::assertStringContainsString('getOptionLabelUsing', $source);
        self::assertStringContainsString('promptOptionsForHook($hookKey, $selected > 0 ? $selected : null)', $source);
        self::assertStringContainsString('taskOptionsForSettings($selected > 0 ? $selected : null)', $source);
    }
}
