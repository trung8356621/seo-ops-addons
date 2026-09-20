<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\WritingSplitPreference;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Phase 1 cleanup: writing_split preference must not drive shape and must not
 * resurface in Global SEO bar. History readers may still resolve stored values.
 */
final class WritingSplitPreferenceNonAuthorityContractTest extends TestCase
{
    public function test_preference_class_is_deprecated_history_reader_only(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WritingSplitPreference::class))->getFileName());
        self::assertStringContainsString('@deprecated', $src);
        self::assertStringContainsString('route_cost_auto', $src);
        self::assertStringContainsString('GenerationShapeResolver', $src);
        self::assertFalse(WritingSplitPreference::enabledForArticleId(99));
        self::assertTrue(WritingSplitPreference::resolveForRun(['writing_split_enabled' => true], null));
        self::assertFalse(WritingSplitPreference::resolveForRun([], null));
    }

    public function test_global_seo_bar_has_no_writing_split_surface(): void
    {
        $bar = (string) file_get_contents((new ReflectionClass(GlobalSeoBar::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/views/livewire/global-seo-bar.blade.php',
        );

        self::assertStringNotContainsString('writingSplitEnabled', $bar);
        self::assertStringNotContainsString('WritingSplitPreference', $bar);
        self::assertStringNotContainsString('syncWritingSplitPreference', $bar);
        self::assertStringNotContainsString('updatedWritingSplitEnabled', $bar);
        self::assertStringNotContainsString('wire:model.live="writingSplitEnabled"', $blade);
    }

    public function test_planner_does_not_call_writing_split_preference(): void
    {
        $planner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/ArticleGenerationExecutionPlanner.php',
        );
        $resolver = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/GenerationShapeResolver.php',
        );

        self::assertStringContainsString('SOURCE_ROUTE_COST_AUTO', $planner);
        self::assertStringNotContainsString('WritingSplitPreference', $planner);
        self::assertStringContainsString('Ignores writing_split_enabled', $resolver);
        self::assertStringNotContainsString('WritingSplitPreference', $resolver);
    }

    public function test_article_meta_catalog_marks_writing_split_legacy(): void
    {
        $catalog = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleMetaKeyCatalog.php',
        );
        self::assertStringContainsString("'writing_split_enabled' =>", $catalog);
        self::assertStringContainsString('CLASS_LEGACY', $catalog);
        self::assertStringContainsString('route_cost_auto', $catalog);
    }
}
