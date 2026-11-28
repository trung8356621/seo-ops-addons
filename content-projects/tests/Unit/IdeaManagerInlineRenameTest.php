<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class IdeaManagerInlineRenameTest extends TestCase
{
    public function test_blade_supports_inline_double_click_rename_with_enter_escape_blur_and_error_retention(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        // Double-click title -> inline input with auto-focus + select-all.
        self::assertStringContainsString('data-im-idea-title="1"', $blade);
        self::assertStringContainsString('x-on:dblclick.stop="startRename(item)"', $blade);
        self::assertStringContainsString('data-im-rename-input="1"', $blade);
        self::assertStringContainsString('input.focus();', $blade);
        self::assertStringContainsString('input.select();', $blade);

        // Enter saves, Escape cancels, blur saves if changed.
        self::assertStringContainsString('x-on:keydown.enter.prevent.stop="commitRename(item)"', $blade);
        self::assertStringContainsString('x-on:keydown.escape.prevent.stop="cancelRename(item)"', $blade);
        self::assertStringContainsString('x-on:blur="commitRename(item)"', $blade);

        // Unchanged name skips request; empty name rejected; backend error retains editingValue.
        self::assertStringContainsString('if (trimmed === orig)', $blade);
        self::assertStringContainsString('this.cancelRename(item);', $blade);
        self::assertStringContainsString('if (trimmed === \'\')', $blade);
        self::assertStringContainsString('this.$wire.renameIdeaItem(owner, String(item.cluster_ref), trimmed)', $blade);
        self::assertStringContainsString('savingRenameKey === itemKey(item)', $blade);
        self::assertStringContainsString('renameErrorByKey[itemKey(item)]', $blade);

        // Existing row controls (checkbox, target DNA, DNA expand, delete) remain intact.
        self::assertStringContainsString('x-on:change="toggleSelect(itemKey(item))"', $blade);
        self::assertStringContainsString('x-on:change="setTarget(item.cluster_ref, $event.target.value)"', $blade);
        self::assertStringContainsString('x-on:click="toggleExpand(item.cluster_ref)"', $blade);
        self::assertStringContainsString('x-on:click="removeItem(item.cluster_ref)"', $blade);
    }

    public function test_backend_rename_validates_authorizes_dedups_and_preserves_other_fields(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaWorkspacePersistenceService::class))->getFileName(),
        );

        // Livewire endpoint enforces workflow + site access.
        self::assertStringContainsString('function renameIdeaItem(int $siteId, string $clusterRef, string $newName): array', $pageSrc);
        self::assertStringContainsString('abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);', $pageSrc);
        self::assertStringContainsString('abort_unless($siteId > 0 && SeoAccessControl::canAccessSite($siteId), 403);', $pageSrc);

        // Service validates non-empty, max length, and duplicate conflict without merging/deleting.
        self::assertStringContainsString('function renameItem(', $svcSrc);
        self::assertStringContainsString('$this->assertCanAccess($siteId);', $svcSrc);
        self::assertStringContainsString("'code' => 'empty_name'", $svcSrc);
        self::assertStringContainsString("'code' => 'name_too_long'", $svcSrc);
        self::assertStringContainsString('AuditNoteDnaNormalizer::MAX_SEED_TEXT_LENGTH', $svcSrc);
        self::assertStringContainsString("'code' => 'duplicate_name'", $svcSrc);
        self::assertStringContainsString('->lockForUpdate()', $svcSrc);

        // Only cluster_name_snapshot (and seed_text when applicable) is updated; never touches tasks/articles/runs.
        self::assertStringContainsString('$items[$targetIdx][\'cluster_name_snapshot\'] = $trimmed;', $svcSrc);
        self::assertStringNotContainsString('SeoProjectTask', $svcSrc);
        self::assertStringNotContainsString('SeoArticle', $svcSrc);
        self::assertStringNotContainsString('SeoContentProjectPlannerRun', $svcSrc);

        self::assertSame(300, AuditNoteDnaNormalizer::MAX_SEED_TEXT_LENGTH);
    }
}
