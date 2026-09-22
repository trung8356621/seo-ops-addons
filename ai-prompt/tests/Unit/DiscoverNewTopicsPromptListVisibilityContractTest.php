<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class DiscoverNewTopicsPromptListVisibilityContractTest extends TestCase
{
    public function test_installer_repairs_ownership_to_managed_owner(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DefaultDiscoverNewTopicsPromptInstaller::class))->getFileName()
        );
        self::assertStringContainsString('managedPromptOwnerUserId', $src);
        self::assertStringContainsString('ownership_repaired', $src);
        self::assertStringContainsString('accountSiteOwnerId', $src);
        self::assertStringContainsString('Keep canonical prompt_id', $src);
        self::assertSame('seo_audit.discover_new_topics', DefaultDiscoverNewTopicsPromptInstaller::HOOK_KEY);
        self::assertSame('Discover New Topics', DefaultDiscoverNewTopicsPromptInstaller::PROMPT_NAME);
        self::assertSame('0.1.0', DefaultDiscoverNewTopicsPromptInstaller::HOOK_VERSION);
    }

    public function test_prompt_resource_scopes_by_account_owner_user_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PromptResource::class))->getFileName()
        );
        self::assertStringContainsString("where('user_id', SeoAccessControl::accountSiteOwnerId())", $src);
    }

    public function test_repair_migration_exists_and_calls_installer(): void
    {
        $path = ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_09_22_160000_repair_discover_new_topics_prompt_list_ownership.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('DefaultDiscoverNewTopicsPromptInstaller', $src);
        self::assertStringContainsString('->install()', $src);
    }

    public function test_hook_is_settings_visible_and_resolves_longform_profile(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $definition = $registry->get('seo_audit.discover_new_topics', '0.1.0');
        self::assertTrue($definition->settingsVisible);

        $catalog = new PromptHookEditorCatalog($registry);
        $keys = array_column($catalog->settingsVisibleHooks(), 'hook_key');
        self::assertContains('seo_audit.discover_new_topics', $keys);

        $profile = (new PromptExecutionProfileResolver)->resolve(null, 'seo_audit.discover_new_topics');
        self::assertSame(AiExecutionProfile::TextReasoning, $profile);
    }
}
