<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\Content\Livewire\AssignToContentProjectModal;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Architecture guard: Assign-to-Content-Project stays one drawer + one open contract.
 */
final class AssignToContentProjectUiArchitectureGuardTest extends TestCase
{
    public function test_canonical_classes_exist(): void
    {
        self::assertTrue(class_exists(AssignToContentProjectContract::class));
        self::assertTrue(class_exists(AssignToContentProjectActionFactory::class));
        self::assertTrue(class_exists(AssignToContentProjectDrawer::class));
        self::assertTrue(is_subclass_of(AssignToContentProjectModal::class, AssignToContentProjectDrawer::class));
        self::assertSame('assign-content-project:open', AssignToContentProjectContract::OPEN_EVENT);
        self::assertSame('assign-content-project:shell-open', AssignToContentProjectContract::SHELL_OPEN_EVENT);
        self::assertSame('assign-content-project:shell-close', AssignToContentProjectContract::SHELL_CLOSE_EVENT);
        self::assertSame('heroicon-o-folder-plus', AssignToContentProjectContract::ICON);
        self::assertSame('warning', AssignToContentProjectContract::COLOR);
        self::assertSame('vocabulary_items', AssignToContentProjectContract::MODE_VOCABULARY_ITEMS);
    }

    public function test_canonical_drawer_view_owned_by_content_addon(): void
    {
        $view = ProjectRoot::addonsPath()
            .'/content/resources/views/livewire/assign-to-content-project-drawer.blade.php';
        self::assertFileExists($view);

        $trigger = ProjectRoot::addonsPath()
            .'/content/resources/views/components/assign-to-content-project-trigger.blade.php';
        self::assertFileExists($trigger);

        $compatView = ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/livewire/assign-to-content-project-drawer.blade.php';
        self::assertFileDoesNotExist($compatView);

        $compatTrigger = ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/components/assign-to-content-project-trigger.blade.php';
        self::assertFileDoesNotExist($compatTrigger);

        $drawer = (string) file_get_contents(
            (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName()
        );
        self::assertStringContainsString("view('content::livewire.assign-to-content-project-drawer')", $drawer);
        self::assertStringNotContainsString('seo-content-ai::livewire.assign-to-content-project', $drawer);

        $drawerBlade = (string) file_get_contents($view);
        self::assertStringContainsString('role="dialog"', $drawerBlade);
        self::assertStringContainsString('assign-to-content-project-drawer', $drawerBlade);
        // Prefer Livewire can_submit — Alpine $wire.uiState?.can_submit permanently disabled Assign.
        self::assertStringContainsString(":disabled=\"! \$this->uiState['can_submit']\"", $drawerBlade);
        self::assertStringNotContainsString('$wire.uiState?.can_submit', $drawerBlade);
        self::assertStringContainsString('inset-y-0 right-0', $drawerBlade);
        self::assertStringContainsString('translate-x-full', $drawerBlade);
        self::assertStringContainsString('z-[10050]', $drawerBlade);
        self::assertStringContainsString('shellOpen', $drawerBlade);
        self::assertStringContainsString('clientPreparing', $drawerBlade);
        self::assertStringContainsString('x-show="shellOpen"', $drawerBlade);
        self::assertStringContainsString('assign-drawer-open', $drawerBlade);
        self::assertStringNotContainsString('x-show="$wire.open"', $drawerBlade);
        self::assertStringNotContainsString('inset-y-0 left-0', $drawerBlade);
        self::assertStringNotContainsString('items-center justify-center p-4 sm:p-6', $drawerBlade);
    }

    public function test_trigger_merges_extra_click_without_duplicate_alpine_attribute(): void
    {
        $trigger = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content/resources/views/components/assign-to-content-project-trigger.blade.php'
        );

        self::assertStringContainsString("\$attributes->except(['x-on:click'", $trigger);
        self::assertStringContainsString('$mergedClick', $trigger);
        self::assertStringContainsString('$safeAttributes', $trigger);
        self::assertStringContainsString('x-on:click="{{ $mergedClick }}"', $trigger);
        self::assertStringContainsString('$assignLabel', $trigger);
        self::assertStringContainsString('tooltip="{{ $assignLabel }}"', $trigger);
        // Single HTML escape via {{ }} only — e(json)+{{ }} → &amp;quot; → Alpine SyntaxError.
        self::assertStringContainsString('json_encode(AssignToContentProjectContract::OPEN_EVENT)', $trigger);
        self::assertStringContainsString('json_encode($payload', $trigger);
        self::assertStringNotContainsString('.e(json_encode', $trigger);
        self::assertStringNotContainsString('e(json_encode(', $trigger);
        self::assertStringNotContainsString(':tooltip="$label"', $trigger);
        self::assertStringNotContainsString(':label="$label"', $trigger);
        self::assertStringNotContainsString(
            "x-on:click=\"{{ trim((\$attributes->get('x-on:click') ?? '').'; '.\$openScript) }}\"",
            $trigger,
        );
    }

    public function test_modal_view_is_compatibility_shim_only(): void
    {
        $modalView = ProjectRoot::addonsPath()
            .'/content/resources/views/livewire/assign-to-content-project-modal.blade.php';
        self::assertFileExists($modalView);
        $contents = (string) file_get_contents($modalView);
        self::assertStringContainsString('assign-to-content-project-drawer', $contents);
        self::assertStringNotContainsString('items-center justify-center', $contents);
    }

    public function test_panel_mounts_canonical_drawer_livewire_tag(): void
    {
        $provider = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/Providers/SeoPanelProvider.php'
        );
        self::assertStringContainsString('assign-to-content-project-drawer', $provider);
        self::assertStringContainsString("@livewire('assign-to-content-project-drawer')", str_replace("\\'", "'", $provider));
        self::assertStringContainsString('AssignToContentProjectDrawer::class', $provider);
    }

    public function test_legacy_centered_modal_partial_removed(): void
    {
        $legacy = ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-assign-content-project-modals.blade.php';
        self::assertFileDoesNotExist($legacy);
    }

    public function test_production_code_has_no_legacy_assign_open_events(): void
    {
        $roots = [
            ProjectRoot::addonsPath().'/content',
            ProjectRoot::addonsPath().'/search-intelligence',
            ProjectRoot::addonsPath().'/seo-content-ai-compat',
            ProjectRoot::addonsPath().'/seo',
            ProjectRoot::addonsPath().'/content-projects',
        ];

        $forbidden = [
            'open-keyword-assign-content-project-modal',
            'open-article-assign-content-project-modal',
            'article-assign-content-project-modal',
            'keyword-assign-content-project-modal',
            'seo-content-project-assign-modal',
            'openAssignSidebar',
            'assignCurrentArticleToContentProject',
            'assignFromSidebar',
        ];

        $hits = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (! in_array($ext, ['php', 'js', 'jsx', 'ts', 'tsx', 'css'], true)
                    && ! str_ends_with($path, '.blade.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                foreach ($forbidden as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $needle.' @ '.$path;
                    }
                }
            }
        }

        self::assertSame([], $hits, "Legacy assign UI artifacts still referenced:\n".implode("\n", $hits));
    }

    public function test_article_and_keyword_resources_do_not_attach_assign_modal_forms(): void
    {
        $article = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource::class))->getFileName()
        );
        $keyword = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource::class))->getFileName()
        );

        self::assertStringContainsString('AssignToContentProjectActionFactory', $article);
        self::assertStringContainsString('AssignToContentProjectActionFactory', $keyword);

        self::assertDoesNotMatchRegularExpression(
            '/assign_to_content_project[\s\S]{0,400}->form\(/',
            $article,
        );
        self::assertDoesNotMatchRegularExpression(
            '/assign_to_content_project[\s\S]{0,400}->form\(/',
            $keyword,
        );
        self::assertDoesNotMatchRegularExpression(
            '/assign_to_content_project[\s\S]{0,400}->modalHeading\(/',
            $article,
        );
        self::assertDoesNotMatchRegularExpression(
            '/assign_to_content_project[\s\S]{0,400}->modalHeading\(/',
            $keyword,
        );

        self::assertStringNotContainsString('assignContentProjectFormFields', $article);
        self::assertStringNotContainsString('assignKeywordContentProjectFormSchema', $keyword);
    }

    public function test_seo_audit_and_article_editor_use_canonical_open_event(): void
    {
        $audit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/articles-optimal.blade.php'
        );
        $editorActions = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'
        );

        self::assertStringContainsString('AssignToContentProjectContract::OPEN_EVENT', $audit);
        self::assertStringContainsString('assignOpenEvent', $audit);
        self::assertStringContainsString('openAssignDrawer({{ (int) $row[\'id\'] }})', $audit);
        self::assertStringContainsString('data-assign-content-project-trigger', $audit);
        self::assertStringContainsString('x-content::assign-to-content-project-trigger', $editorActions);
        self::assertStringContainsString('source="article_editor"', $editorActions);
        self::assertStringContainsString(':article-ids="[(int) $record->id]"', $editorActions);
    }

    public function test_drawer_loads_articles_without_global_site_scope(): void
    {
        $drawer = (string) file_get_contents(
            (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName()
        );
        self::assertStringContainsString('getRecordRouteBindingEloquentQuery()', $drawer);
        self::assertStringNotContainsString(
            "return ArticleResource::getEloquentQuery()\n            ->whereIn('id', \$ids)",
            $drawer,
        );
    }

    public function test_vocabulary_items_payload_normalizes_batch(): void
    {
        $payload = AssignToContentProjectContract::vocabularyItemsPayload(
            'vocabulary_planning',
            ['alpha', ['keyword' => 'beta', 'title' => 'Beta Title']],
            9,
            42,
        );

        self::assertSame(AssignToContentProjectContract::MODE_VOCABULARY_ITEMS, $payload['mode']);
        self::assertSame('vocabulary_planning', $payload['source']);
        self::assertSame([9], $payload['site_ids']);
        self::assertSame([42], $payload['article_ids']);
        self::assertCount(2, $payload['items']);
        self::assertSame('alpha', $payload['items'][0]['keyword']);
        self::assertSame('beta', $payload['items'][1]['keyword']);
        self::assertSame('Beta Title', $payload['items'][1]['title']);
    }

    public function test_vocabulary_sidebar_assigns_inline_without_canonical_drawer(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleVocabularySidebar.jsx'
        );
        self::assertStringContainsString('assignVocabularyItemsToContentProject', $source);
        self::assertStringContainsString('wp-article-vocabulary-project-select', $source);
        self::assertStringNotContainsString('openAssignToContentProject', $source);
        self::assertStringNotContainsString('openAssignToContentProjectDrawer', $source);
        self::assertStringNotContainsString('MODE_VOCABULARY_ITEMS', $source);
    }

    public function test_keyword_detail_opens_assign_via_window_event_not_mount_action(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/keywordDetailPanel.js'
        );
        self::assertStringContainsString("assign-content-project:open", $source);
        self::assertStringContainsString("source: 'keyword_detail'", $source);
        self::assertStringContainsString('assign-content-project:shell-open', $source);
        self::assertStringContainsString('assign-content-project:shell-close', $source);
        self::assertStringNotContainsString("mountAction', 'assignToContentProject'", $source);
        self::assertStringNotContainsString("mountAction', 'assignArticleToContentProject'", $source);
    }

    public function test_keyword_row_click_does_not_cover_item_actions_with_overlay(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/keywordDetailPanel.js'
        );
        self::assertStringContainsString('keyword-item__actions', $source);
        self::assertStringContainsString('keywordRowClickBound', $source);
        self::assertStringContainsString("querySelectorAll(':scope > .keyword-row-click-layer')", $source);
        self::assertStringNotContainsString("layer.className = 'keyword-row-click-layer'", $source);
    }
}
