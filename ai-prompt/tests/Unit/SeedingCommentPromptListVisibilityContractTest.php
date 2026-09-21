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

    public function test_installer_does_not_create_duplicate_named_prompt(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(DefaultSeedingCommentPromptInstaller::class))->getFileName()
        );
        self::assertStringContainsString("where('hook_key', self::HOOK_KEY)", $src);
        self::assertStringContainsString("where('name', self::PROMPT_NAME)", $src);
        self::assertStringContainsString('create a second Prompt', $src);
        self::assertStringContainsString('Keep canonical prompt_id', $src);
        self::assertStringNotContainsString('PROMPT_NAME_ALT', $src);
        self::assertLessThanOrEqual(1, substr_count($src, 'new SeoPrompt'));
    }

    public function test_seeding_resolver_reads_shared_prompt_markdown_not_legacy_settings_table(): void
    {
        $resolverPath = dirname(__DIR__, 3).'/seeding/src/Services/SeedingSharedCommentPromptResolver.php';
        self::assertFileExists($resolverPath);
        $src = (string) file_get_contents($resolverPath);
        self::assertStringContainsString('resolveSettingsHook', $src);
        self::assertStringContainsString('markdown_content', $src);
        self::assertStringContainsString(
            'Does not read seeding_comment_prompt_settings for execution authority',
            $src,
        );
        // No query/table access beyond the doc comment above.
        self::assertSame(
            1,
            substr_count($src, 'seeding_comment_prompt_settings'),
            'resolver must not query legacy seeding_comment_prompt_settings',
        );
    }

    public function test_prompt_resource_does_not_broaden_to_all_system_prompts(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptResource::class))->getFileName()
        );
        // Still owner-scoped — do not open list to every is_system_default / all user_ids.
        self::assertStringContainsString("where('user_id', SeoAccessControl::accountSiteOwnerId())", $src);
        self::assertStringContainsString('shouldScopeToAccountOwner', $src);
        self::assertStringNotContainsString('is_system_default', $src);
        // SoftDeletes remains applied (comment may mention withoutGlobalScopes historically).
        self::assertMatchesRegularExpression(
            '/SoftDeletes scope giữ nguyên|deleted_at/',
            $src
        );
        self::assertStringNotContainsString('->withoutGlobalScopes(', $src);
    }

    public function test_edit_prompt_page_still_owns_normal_save_path(): void
    {
        $editPath = dirname(__DIR__, 2).'/src/Filament/Resources/PromptResource/Pages/EditPrompt.php';
        self::assertFileExists($editPath);
        $src = (string) file_get_contents($editPath);
        self::assertStringContainsString('mutateFormDataBeforeSave', $src);
        self::assertStringContainsString('markdown_content', $src);
        self::assertStringNotContainsString('seeding.comment.generate', $src);
    }
}
