<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\User;
use Filament\Facades\Filament;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\CreatePrompt;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\EditPrompt;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\ListPrompts;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages\TestPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoUserNavigation;
use ReflectionClass;
use Tests\TestCase;

/**
 * Dual Admin+SEO PromptResource registration — ownership/scope/UI reuse contracts.
 */
final class PromptAdminDualRegistrationContractTest extends TestCase
{
    public function test_panel_aware_nav_group_does_not_change_seo_default(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(PromptResource::class))->getFileName());
        self::assertStringContainsString('getNavigationGroup', $src);
        self::assertStringContainsString("=== 'admin'", $src);
        self::assertStringContainsString('SeoUserNavigation::GROUP_SYSTEM', $src);
        self::assertStringContainsString('Does not change default getUrl panelId', $src);
        self::assertSame('seo-main', PromptResource::panelId());
    }

    public function test_default_panel_id_remains_seo_main(): void
    {
        self::assertSame('seo-main', PromptResource::panelId());
    }

    public function test_admin_nav_group_is_system_when_panel_is_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        self::assertSame(SeoUserNavigation::GROUP_SYSTEM, PromptResource::getNavigationGroup());

        Filament::setCurrentPanel(Filament::getPanel('seo-main'));
        self::assertNull(PromptResource::getNavigationGroup());
    }

    public function test_no_new_prompt_ui_files_under_ai_prompt_filament(): void
    {
        $root = dirname((string) (new ReflectionClass(PromptResource::class))->getFileName(), 2);
        $expected = [
            'Resources'.DIRECTORY_SEPARATOR.'PromptResource.php',
            'Resources'.DIRECTORY_SEPARATOR.'PromptResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ListPrompts.php',
            'Resources'.DIRECTORY_SEPARATOR.'PromptResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'CreatePrompt.php',
            'Resources'.DIRECTORY_SEPARATOR.'PromptResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'EditPrompt.php',
            'Resources'.DIRECTORY_SEPARATOR.'PromptResource'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'TestPrompt.php',
            'Pages'.DIRECTORY_SEPARATOR.'SeoSettingsPrompt.php',
        ];
        foreach ($expected as $rel) {
            self::assertFileExists($root.DIRECTORY_SEPARATOR.$rel);
        }

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (! str_contains($path, 'Prompt')) {
                continue;
            }
            if (str_contains($path, 'PromptHooks') || str_contains($path, 'DomainResource')) {
                continue;
            }
            $rel = str_replace($root.DIRECTORY_SEPARATOR, '', $path);
            if (in_array($rel, $expected, true)) {
                continue;
            }
            if (preg_match('/Admin.*Prompt|Prompt.*Admin/i', $rel) === 1) {
                $hits[] = $rel;
            }
        }
        self::assertSame([], $hits, 'No Admin-cloned Prompt UI under ai-prompt/Filament');
    }

    public function test_existing_page_classes_unchanged(): void
    {
        self::assertSame(PromptResource::class, (new ReflectionClass(ListPrompts::class))->getDefaultProperties()['resource'] ?? PromptResource::class);
        foreach ([ListPrompts::class, CreatePrompt::class, EditPrompt::class, TestPrompt::class] as $page) {
            $props = (new ReflectionClass($page))->getDefaultProperties();
            self::assertSame(PromptResource::class, $props['resource'] ?? null);
        }
    }

    public function test_admin_context_list_scope_matches_seo_and_sees_prompt_26_for_owner_two(): void
    {
        try {
            $owner = User::query()->whereKey(2)->first();
        } catch (\Throwable) {
            self::markTestSkipped('Live mysql users table unavailable in PHPUnit sqlite');
        }

        if (! $owner instanceof User) {
            self::markTestSkipped('Owner user id=2 not present in this environment');
        }

        auth()->login($owner);
        app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

        $seoOwner = SeoAccessControl::accountSiteOwnerId();
        $seoCount = PromptResource::getEloquentQuery()->count();
        $seoHas26 = PromptResource::getEloquentQuery()->whereKey(26)->exists();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(\App\Services\ServiceDatabaseConnectionResolver::class)->tryBootstrap('seo', false);

        $adminOwner = SeoAccessControl::accountSiteOwnerId();
        $adminCount = PromptResource::getEloquentQuery()->count();
        $adminHas26 = PromptResource::getEloquentQuery()->whereKey(26)->exists();

        self::assertSame($seoOwner, $adminOwner);
        self::assertSame($seoCount, $adminCount);
        self::assertTrue($seoHas26);
        self::assertTrue($adminHas26);

        $prompt = PromptResource::getEloquentQuery()->whereKey(26)->first();
        self::assertNotNull($prompt);
        $version = app(PromptVersionService::class)->currentVersion($prompt);
        self::assertNotNull($version);
        self::assertNotSame('', (string) ($version->version_label ?? ''));
    }

    public function test_admin_and_seo_prompt_routes_generate(): void
    {
        $admin = PromptResource::getUrl('index', panel: 'admin');
        $seo = PromptResource::getUrl('index', panel: 'seo-main');
        self::assertStringContainsString('/admin/prompts', $admin);
        self::assertStringContainsString('/seo/prompts', $seo);
        self::assertNotSame($admin, $seo);

        self::assertStringContainsString('/admin/prompts/create', PromptResource::getUrl('create', panel: 'admin'));
        self::assertStringContainsString('/admin/prompts/26/edit', PromptResource::getUrl('edit', ['record' => 26], panel: 'admin'));
        self::assertStringContainsString('/admin/prompts/26/test', PromptResource::getUrl('test', ['record' => 26], panel: 'admin'));

        self::assertStringContainsString('/seo/prompts/create', PromptResource::getUrl('create', panel: 'seo-main'));
        self::assertStringContainsString('/seo/prompts/26/edit', PromptResource::getUrl('edit', ['record' => 26], panel: 'seo-main'));
        self::assertStringContainsString('/seo/prompts/26/test', PromptResource::getUrl('test', ['record' => 26], panel: 'seo-main'));
    }
}
