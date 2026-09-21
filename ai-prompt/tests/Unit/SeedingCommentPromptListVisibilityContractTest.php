<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Prompt #26 / seeding.comment.generate must appear in existing PromptResource list.
 */
final class SeedingCommentPromptListVisibilityContractTest extends TestCase
{
    public function test_installer_repairs_ownership_to_managed_owner_without_new_prompt(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(DefaultSeedingCommentPromptInstaller::class))->getFileName()
        );
        self::assertStringContainsString('managedPromptOwnerUserId', $src);
        self::assertStringContainsString('ownership_repaired', $src);
        self::assertStringContainsString('accountSiteOwnerId', $src);
        self::assertStringContainsString('Keep canonical prompt_id', $src);
        self::assertStringNotContainsString('PROMPT_NAME_ALT', $src);
    }

    public function test_prompt_resource_scopes_by_account_owner_user_id(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptResource::class))->getFileName()
        );
        self::assertStringContainsString('shouldScopeToAccountOwner', $src);
        self::assertStringContainsString("where('user_id', SeoAccessControl::accountSiteOwnerId())", $src);
    }

    public function test_managed_owner_prefers_account_site_owner_and_dominant_prompt_owner(): void
    {
        $installerSrc = (string) file_get_contents(
            (new ReflectionClass(DefaultSeedingCommentPromptInstaller::class))->getFileName()
        );
        self::assertStringContainsString('accountSiteOwnerId', $installerSrc);
        self::assertStringContainsString('panelOwnerId', $installerSrc);
        self::assertStringContainsString('orderByDesc(\'c\')', $installerSrc);
        self::assertStringContainsString('fallbackSystemUserId', $installerSrc);
    }

    public function test_repair_migration_exists_and_calls_installer(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_09_21_140000_repair_seeding_comment_prompt_list_ownership.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultSeedingCommentPromptInstaller', $src);
        self::assertStringContainsString('->install()', $src);
        self::assertStringContainsString('does not create a duplicate', $src);
    }

    public function test_no_new_prompt_ui_in_installer_or_migration(): void
    {
        $files = [
            (new ReflectionClass(DefaultSeedingCommentPromptInstaller::class))->getFileName(),
            dirname(__DIR__, 2).'/database/migrations/2026_09_21_140000_repair_seeding_comment_prompt_list_ownership.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents((string) $file);
            self::assertStringNotContainsString('PromptResource extends', $src);
            self::assertStringNotContainsString('create new Resource', strtolower($src));
            self::assertStringNotContainsString('Livewire', $src);
        }
    }

    public function test_hook_key_and_prompt_name_remain_canonical(): void
    {
        self::assertSame('seeding.comment.generate', DefaultSeedingCommentPromptInstaller::HOOK_KEY);
        self::assertSame('Generate seeding social comments (default)', DefaultSeedingCommentPromptInstaller::PROMPT_NAME);
    }
}
