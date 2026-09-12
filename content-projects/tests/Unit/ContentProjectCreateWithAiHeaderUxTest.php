<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Content Project header owns "Tạo bài bằng AI" split control — not GlobalSeoBar gear.
 */
final class ContentProjectCreateWithAiHeaderUxTest extends TestCase
{
    public function test_gear_menu_no_longer_hosts_create_with_ai(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/views/livewire/global-seo-bar.blade.php',
        );
        $bar = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());

        self::assertStringContainsString('view_as', $blade);
        self::assertStringContainsString('simulatedRole', $blade);
        self::assertStringNotContainsString('ai_generation_heading', $blade);
        self::assertStringNotContainsString('generation_mode_label', $blade);
        self::assertStringNotContainsString('generation_mode_normal', $blade);
        self::assertStringNotContainsString('Bình thường', $blade);
        self::assertStringNotContainsString('articleGenerationMode', $bar);
        self::assertStringNotContainsString('updatedArticleGenerationMode', $bar);
    }

    public function test_header_has_ai_generation_action_without_legacy_generate_working_items(): void
    {
        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());

        self::assertStringNotContainsString('makeGeneratePendingItemsAction', $view);
        self::assertStringContainsString('makeCreateWithAiAction', $view);
        self::assertStringContainsString('makeCreateWithAiModeGroup', $view);

        $aiPos = strpos($view, 'makeCreateWithAiAction');
        $modePos = strpos($view, 'makeCreateWithAiModeGroup');

        self::assertNotFalse($aiPos);
        self::assertNotFalse($modePos);
        self::assertGreaterThan($aiPos, $modePos);

        // Single primary AI control — not duplicated into overflow More group as a second generate.
        self::assertSame(1, substr_count($view, "makeCreateWithAiAction"));
        self::assertSame(1, substr_count($view, "makeCreateWithAiModeGroup"));
    }

    public function test_dropdown_has_exactly_fast_and_free_modes(): void
    {
        $resource = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());

        self::assertStringContainsString('create_with_ai_mode_fast', $resource);
        self::assertStringContainsString('create_with_ai_mode_free', $resource);
        self::assertStringContainsString("\$setMode('normal')", $resource);
        self::assertStringContainsString("\$setMode('free_only')", $resource);
        self::assertStringContainsString('mode_fast', $resource);
        self::assertStringContainsString('mode_free', $resource);
        self::assertStringNotContainsString('mode_slow', $resource);
        self::assertStringNotContainsString('Bình thường', $resource);
        self::assertStringNotContainsString('Paid mode', $resource);
        self::assertStringNotContainsString('FreeOnly', $resource);
        self::assertStringNotContainsString('routing_mode', $resource);

        self::assertSame(1, substr_count($resource, "Action::make('create_with_ai_mode_fast')"));
        self::assertSame(1, substr_count($resource, "Action::make('create_with_ai_mode_free')"));
    }

    public function test_fast_and_free_map_to_existing_cost_policy_not_new_router(): void
    {
        self::assertSame(AiCostPolicy::Default, AiCostPolicy::tryFromMixed('normal'));
        self::assertSame(AiCostPolicy::FreeOnly, AiCostPolicy::tryFromMixed('free_only'));
        self::assertSame('normal', AiCostPolicy::Default->generationModeValue());
        self::assertSame('free_only', AiCostPolicy::FreeOnly->generationModeValue());

        $resource = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());
        self::assertStringContainsString('AiCostPolicy::SETTING_KEY', $resource);
        self::assertStringContainsString('AiCostPolicy::tryFromMixed', $resource);
        self::assertStringNotContainsString('manual_free_only', $resource);
        self::assertStringNotContainsString('paid_locked', $resource);
    }

    public function test_create_with_ai_stamps_selected_mode_into_bulk_launch_settings(): void
    {
        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());

        self::assertStringContainsString('createWithAiLaunchSettings', $view);
        self::assertStringContainsString('AiCostPolicy::SETTING_KEY', $view);
        self::assertStringContainsString('ArticleGenerationModePreference::persistForUserId', $view);
        self::assertStringContainsString('setArticleGenerationMode', $view);
        // Mode change must not auto-start generation.
        self::assertStringContainsString('makeCreateWithAiModeGroup', $view);
        self::assertDoesNotMatchRegularExpression(
            '/makeCreateWithAiModeGroup[\s\S]{0,400}startGeneratePendingItems/',
            $view,
        );
    }

    public function test_free_mode_does_not_mutate_api_connections(): void
    {
        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
        $pref = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleGenerationModePreference::class))->getFileName(),
        );

        self::assertStringNotContainsString('ApiConnection', $view);
        self::assertStringNotContainsString('paid_locked', $view);
        self::assertStringNotContainsString('manual_free_only', $view);
        self::assertStringNotContainsString('ApiConnection', $pref);
        self::assertStringNotContainsString('paid_locked', $pref);
    }

    public function test_generate_working_items_still_registered(): void
    {
        $view = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
        $resource = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());

        self::assertStringNotContainsString('makeGeneratePendingItemsAction', $view);
        self::assertStringContainsString("'generate_pending_items'", $resource);
        self::assertStringContainsString('generate_working_items', $resource);
    }

    public function test_permission_and_disabled_gates_reuse_generate_pending(): void
    {
        $resource = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());

        self::assertStringContainsString('canAccessContentProjectRun', $resource);
        self::assertStringContainsString('canGeneratePendingItems', $resource);
        self::assertStringContainsString('generatePendingDisabledReason', $resource);
        // create_with_ai reuses makeGeneratePendingItemsAction — same gates.
        self::assertStringContainsString("'create_with_ai'", $resource);
        self::assertStringContainsString('makeGeneratePendingItemsAction(', $resource);
    }

    public function test_i18n_keys_use_user_facing_wording(): void
    {
        $vi = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php',
        );
        $en = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php',
        );

        foreach ([$vi, $en] as $lang) {
            self::assertStringContainsString("'create_with_ai'", $lang);
            self::assertStringContainsString("'mode_fast'", $lang);
            self::assertStringContainsString("'mode_fast_description'", $lang);
            self::assertStringContainsString("'mode_free'", $lang);
            self::assertStringContainsString("'mode_free_description'", $lang);
        }

        self::assertStringContainsString('Tạo bài bằng AI', $vi);
        self::assertStringContainsString('Ưu tiên nhanh', $vi);
        self::assertStringContainsString('Miễn phí', $vi);
        self::assertStringNotContainsString("'mode_fast' => 'Bình thường'", $vi);
        self::assertStringNotContainsString('Chậm', $vi);
        self::assertStringNotContainsString('FreeOnly', $vi);
        self::assertStringNotContainsString('Paid mode', $vi);
    }
}
