<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaGroupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class IdeaManagerBulkDnaAndDomainSyncTest extends TestCase
{
    public function test_bulk_dna_target_updates_entire_active_group_with_validation_and_empty_guard(): void
    {
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaGroupService::class))->getFileName(),
        );
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        // Backend validates 1..100, group ownership, empty group, and updates only target_dna_count in active group.
        self::assertStringContainsString('function bulkUpdateTargetDna(int $groupId, int $targetDnaCount, ?int $actorId = null): array', $svcSrc);
        self::assertStringContainsString('MIN_TARGET_DNA_COUNT', $svcSrc);
        self::assertStringContainsString('MAX_TARGET_DNA_COUNT', $svcSrc);
        self::assertStringContainsString("'code' => 'invalid_target_dna'", $svcSrc);
        self::assertStringContainsString("'code' => 'empty_group'", $svcSrc);
        self::assertStringContainsString('ensureTargetCoversSpecified($targetDnaCount, $dna)', $svcSrc);
        self::assertStringContainsString('if ($itemGroupId !== $groupId) {', $svcSrc);
        self::assertStringContainsString('$items[$idx][\'target_dna_count\'] = $nextTarget;', $svcSrc);

        // Livewire endpoint enforces workflow access and refreshes snapshot.
        self::assertStringContainsString('function bulkSetGroupTargetDna(int $groupId, int $targetDnaCount): array', $pageSrc);
        self::assertStringContainsString('abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);', $pageSrc);

        // Blade places Bulk DNA bar right above .im-group-bar and below toolbar hint, disabled when empty.
        $hintPos = strpos($blade, 'idea_manager_ai_fill_hint');
        $bulkPos = strpos($blade, 'data-im-bulk-dna-bar="1"');
        $groupBarPos = strpos($blade, 'data-im-group-bar="1"');
        self::assertNotFalse($hintPos);
        self::assertNotFalse($bulkPos);
        self::assertNotFalse($groupBarPos);
        self::assertGreaterThan($hintPos, $bulkPos);
        self::assertGreaterThan($bulkPos, $groupBarPos);

        self::assertStringContainsString(':disabled="groupBusy || groupCount(activeGroupId) === 0"', $blade);
        self::assertStringContainsString('applyBulkGroupDna()', $blade);
        self::assertStringContainsString('this.$wire.bulkSetGroupTargetDna(gid, targetVal)', $blade);

        // Existing per-row inline DNA target editing remains intact.
        self::assertStringContainsString('x-on:change="setTarget(item.cluster_ref, $event.target.value)"', $blade);
        self::assertSame(1, AuditNoteDnaNormalizer::MIN_TARGET_DNA_COUNT);
        self::assertSame(100, AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT);
    }

    public function test_bidirectional_domain_sync_uses_site_id_avoids_loops_and_preserves_groups(): void
    {
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        // A -> B inside setSourceSite with guard against redundant dispatch.
        self::assertStringContainsString('if ((int) ($this->targetSiteId ?? 0) !== $siteId)', $pageSrc);
        self::assertStringContainsString('$this->targetSiteId = $siteId;', $pageSrc);
        self::assertStringContainsString("\$this->dispatch('idea-manager-target-changed', siteId: \$siteId, domain: \$domain);", $pageSrc);

        // B -> A inside selectIdeaManagerTargetSite with guard against redundant dispatch.
        self::assertStringContainsString('if ((int) ($this->sourceFilterSiteId ?? 0) !== $siteId)', $pageSrc);
        self::assertStringContainsString('$this->sourceFilterSiteId = $siteId;', $pageSrc);
        self::assertStringContainsString("\$this->dispatch('idea-manager-source-filter-changed', siteId: \$siteId, domain: \$domain);", $pageSrc);

        // Left <select> syncs its selected value without re-calling setSourceSite; destination recalculates preview.
        self::assertStringContainsString('data-im-source-site-select="1"', $blade);
        self::assertStringContainsString('x-on:idea-manager-source-filter-changed.window=', $blade);
        self::assertStringContainsString('if (this.targetSiteId > 0 && this.items.length > 0) {', $blade);
        self::assertStringContainsString('this.schedulePreview();', $blade);

        // Neither onSourceFilterChanged nor onTargetChanged touches activeGroupId, selected, or distributeWithAi.
        $onTargetPos = strpos($blade, 'onTargetChanged(detail)');
        $onActiveMonthPos = strpos($blade, 'onActiveMonthChanged(detail)');
        self::assertNotFalse($onTargetPos);
        self::assertNotFalse($onActiveMonthPos);
        $onTargetBody = substr($blade, $onTargetPos, $onActiveMonthPos - $onTargetPos);
        self::assertStringNotContainsString('activeGroupId', $onTargetBody);
        self::assertStringNotContainsString('clearSelection', $onTargetBody);
        self::assertStringNotContainsString('distributeWithAi', $onTargetBody);
    }
}
