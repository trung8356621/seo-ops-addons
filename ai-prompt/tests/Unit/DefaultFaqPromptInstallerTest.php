<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Console\InstallDefaultFaqPromptCommand;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultFaqPromptInstaller;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class DefaultFaqPromptInstallerTest extends TestCase
{
    public function test_hook_key_name_and_canonical_markdown(): void
    {
        self::assertSame('article.faq.generate', DefaultFaqPromptInstaller::HOOK_KEY);
        self::assertSame('0.1.0', DefaultFaqPromptInstaller::HOOK_VERSION);
        self::assertSame('Tạo FAQ', DefaultFaqPromptInstaller::PROMPT_NAME);

        $markdown = DefaultFaqPromptInstaller::canonicalDefaultMarkdown();

        self::assertStringContainsString('{{title}}', $markdown);
        self::assertStringContainsString('{{content_excerpt}}', $markdown);
        self::assertStringContainsString('{{language}}', $markdown);
    }

    public function test_installer_does_not_overwrite_existing_binding_without_restore(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptOwnership/DefaultFaqPromptInstaller.php',
        );

        self::assertStringContainsString('if (! isset($bindings[self::HOOK_KEY]))', $source);
        self::assertStringContainsString('canonicalDefaultMarkdown()', $source);
        self::assertStringContainsString('restoreCanonical', $source);
    }

    public function test_command_and_migration_are_registered(): void
    {
        $props = (new \ReflectionClass(InstallDefaultFaqPromptCommand::class))->getDefaultProperties();
        self::assertSame(
            'seo:prompt:install-default-faq {--restore : Restore markdown from canonical Hook spec}',
            $props['signature'] ?? null,
        );

        self::assertFileExists(
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_10_02_150000_install_default_faq_prompt_binding.php',
        );
    }
}
