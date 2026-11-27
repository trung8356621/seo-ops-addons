<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Phase 1 Idea Manager contracts — SEO Audit untouched; sync/import/conflict UX present.
 */
final class IdeaManagerPhase1ContractTest extends TestCase
{
    public function test_idea_manager_page_slug_and_view(): void
    {
        $ref = new ReflectionClass(ContentProjectIdeaManager::class);
        $slug = $ref->getProperty('slug');
        $slug->setAccessible(true);
        // Static property via default — read from source for stability.
        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString("slug = 'content-projects/idea-manager'", $src);
        self::assertStringContainsString('content-project-idea-manager', $src);
        self::assertStringContainsString('saveIdeaWorkspace', $src);
        self::assertStringContainsString('loadIdeaWorkspace', $src);
        self::assertStringContainsString('IdeaWorkspacePersistenceService', $src);
    }

    public function test_seo_audit_blade_and_key_unchanged(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringContainsString('cpAuditNotesRoot', $blade);
        self::assertStringNotContainsString('seoOps:idea-manager:draft:v1:site:', $blade);
        self::assertStringNotContainsString('ideaManagerRoot', $blade);
    }

    public function test_idea_manager_blade_has_local_first_sync_and_import_contracts(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        self::assertStringContainsString('seoOps:idea-manager:draft:v1:site:', $blade);
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringContainsString('editGeneration', $blade);
        self::assertStringContainsString('DEBOUNCE_MS = 3000', $blade);
        self::assertStringContainsString('MAX_SYNC_MS = 30000', $blade);
        self::assertStringContainsString('inflightGeneration', $blade);
        self::assertStringContainsString('editGeneration > genAtSend', $blade);
        self::assertStringContainsString('previewAuditImport', $blade);
        self::assertStringContainsString('confirmKeepMine', $blade);
        self::assertStringContainsString('confirmLoadServer', $blade);
        self::assertStringContainsString('dismissConflict', $blade);
        self::assertStringContainsString('idea_manager_published_note', $blade);
        self::assertStringContainsString('data-im-distribution-confirm', $blade);
        self::assertStringContainsString('disabled', $blade);
        self::assertStringContainsString('Never overwrite newer working set', $blade);
        // Import is confirm-only — init only bootstraps; previewAuditImport is user-triggered.
        self::assertMatchesRegularExpression('/init\(\)\s*\{\s*this\.bootstrapFromServer\(cfg\.server\);\s*\}/', $blade);
        self::assertStringContainsString('confirmAuditImport()', $blade);
        // Blade must close Wire gotoClusterPage expressions (HTTP 500 root cause).
        self::assertStringContainsString('wire:click="gotoClusterPage({{ (int) $this->clusterPage + 1 }})"', $blade);
        self::assertDoesNotMatchRegularExpression(
            '/gotoClusterPage\(\{\{\s*\(int\)\s*\$this->clusterPage\s*\+\s*1\s*\}\)"/',
            $blade,
        );
        self::assertStringContainsString('siteLabel()', $blade);
        self::assertStringContainsString('this.labels.conflictBody', $blade);
        // UX redesign + legacy discovery (read-only Audit key).
        self::assertStringContainsString('content-project-ops-styles', $blade);
        self::assertStringContainsString('im-stepbar', $blade);
        self::assertStringContainsString('im-card', $blade);
        self::assertStringContainsString('im-cluster-row', $blade);
        self::assertStringContainsString('data-im-legacy-banner', $blade);
        self::assertStringContainsString('scanLegacyAudit', $blade);
        self::assertStringContainsString('legacyImportableCount', $blade);
        self::assertStringContainsString('idea_manager_legacy_banner', $blade);
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        self::assertStringContainsString('Phát hiện :count ý tưởng từ SEO Audit', $vi);
        // No duplicate page H1 title in body toolbar.
        self::assertStringNotContainsString('<h1 class="text-xl font-semibold', $blade);
        // Audit key remains read-only (getItem only on Audit prefix path in scan/preview).
        self::assertStringContainsString("AUDIT_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:'", $blade);
        self::assertStringNotContainsString('localStorage.setItem(this.auditKey', $blade);
        self::assertStringNotContainsString('localStorage.removeItem(this.auditKey', $blade);
    }

    public function test_idea_manager_resolves_default_site_without_query_param(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('applyOptionalSiteQueryParam', $src);
        self::assertStringContainsString('setGlobalSiteId', $src);
        self::assertStringContainsString('accessibleSitesQuery()->orderBy(\'domain\')->first()', $src);
        // site_id query is optional — never abort when missing.
        self::assertStringNotContainsString('abort_unless(request()->filled(\'site_id\')', $src);
    }

    public function test_persistence_service_uses_lock_for_update_revision_recheck(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaWorkspacePersistenceService::class))->getFileName()
        );
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('STATUS_CONFLICT', $src);
        self::assertStringContainsString('STATUS_NOOP', $src);
        self::assertStringContainsString('canAccessSite', $src);
        self::assertStringContainsString('MAX_NOTE_ITEMS', $src);
    }

    public function test_lang_keys_exist_en_and_vi(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        foreach ([
            'idea_manager_nav_label',
            'idea_manager_import_audit',
            'idea_manager_keep_mine_confirm',
            'idea_manager_load_server_confirm',
            'idea_manager_published_blocked',
        ] as $key) {
            self::assertStringContainsString("'{$key}'", $en);
            self::assertStringContainsString("'{$key}'", $vi);
        }
    }
}
