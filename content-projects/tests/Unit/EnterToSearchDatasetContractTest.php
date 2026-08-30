<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Tests\Support\LegacyAddonPath;

/**
 * Dataset list search must commit on Enter (searchInput → applySearch → search),
 * never via wire:model.live.debounce on list/table filters.
 */
final class EnterToSearchDatasetContractTest extends TestCase
{
    public function test_content_project_toolbar_and_archive_use_enter_search(): void
    {
        $toolbar = LegacyAddonPath::read('resources/views/components/content-project-filter-toolbar.blade.php');
        $archive = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $suggestions = LegacyAddonPath::read('resources/views/components/content-project-seo-audit-planner.blade.php');
        $auditNotes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        $ideas = LegacyAddonPath::read('resources/views/components/content-project-idea-candidate-picker.blade.php');

        foreach ([$toolbar, $archive, $suggestions, $auditNotes, $ideas] as $blade) {
            self::assertStringNotContainsString('wire:model.live.debounce', $blade);
            self::assertStringContainsString('wire:submit=', $blade);
            self::assertStringContainsString('wire:model="', $blade);
        }

        self::assertStringContainsString('searchInput', $toolbar);
        self::assertStringContainsString('applySearch', $toolbar);
        self::assertStringContainsString('suggestionSearchInput', $suggestions);
        self::assertStringContainsString('auditNoteSearchInput', $auditNotes);
        self::assertStringContainsString('ideaCandidateSearchInput', $ideas);
    }

    public function test_backend_exposes_apply_search_and_does_not_react_on_updated_search(): void
    {
        $view = (string) file_get_contents((new ReflectionClass(ViewSeoProject::class))->getFileName());
        $archive = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());
        $queue = (string) file_get_contents((new ReflectionClass(PublishingQueueHub::class))->getFileName());

        foreach ([$view, $archive, $queue] as $source) {
            self::assertStringContainsString('public string $searchInput', $source);
            self::assertStringContainsString('public function applySearch(): void', $source);
            self::assertStringNotContainsString('public function updatedSearch(): void', $source);
        }
    }

    public function test_media_library_and_performance_hub_blades_use_enter_search(): void
    {
        $media = LegacyAddonPath::read('resources/views/filament/pages/media-library.blade.php');
        $gsc = LegacyAddonPath::read('resources/views/seo/performance-hub/partials/gsc-queries-table.blade.php');
        $rank = LegacyAddonPath::read('resources/views/seo/performance-hub/partials/rankings-table.blade.php');
        $topic = LegacyAddonPath::read('resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        $indexHealth = LegacyAddonPath::read('resources/views/filament/pages/article-index-health.blade.php');

        foreach ([$media, $gsc, $rank, $topic, $indexHealth] as $blade) {
            self::assertStringNotContainsString('wire:model.live.debounce', $blade);
            self::assertStringContainsString('wire:submit=', $blade);
        }

        self::assertStringContainsString('filterSearchInput', $media);
        self::assertStringContainsString('gscQuerySearchInput', $gsc);
        self::assertStringContainsString('keywordSearchInput', $rank);
        self::assertStringContainsString('clusterSearchInput', $topic);
        self::assertStringContainsString('searchInput', $indexHealth);
    }

    public function test_autocomplete_pickers_keep_live_debounce(): void
    {
        $existingArticle = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');
        $mcp = LegacyAddonPath::read('resources/views/filament/resources/keywords/pages/partials/mcp-group-modal.blade.php');
        $aiCenter = LegacyAddonPath::read('resources/views/filament/pages/seo-settings-ai-center.blade.php');

        self::assertStringContainsString('wire:model.live.debounce.300ms="selectExistingArticleQuery"', $existingArticle);
        self::assertStringContainsString('wire:model.live.debounce.250ms="mcpGroupSearch"', $mcp);
        self::assertStringContainsString('wire:model.live.debounce.300ms="modelSearch"', $aiCenter);
        self::assertStringContainsString('wire:model.live.debounce.300ms="pickerSearch"', $aiCenter);
    }
}
