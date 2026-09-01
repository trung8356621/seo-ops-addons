<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Console\AuditPlanningDraftsCommand;
use Omnichannel\Addons\ContentProjects\Console\MergeLegacyPlanningDraftsCommand;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\CreateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\LegacyPlanningDraftMergeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 1 — Shared Planning Draft merge architecture contracts.
 */
final class LegacyPlanningDraftMergeContractTest extends TestCase
{
    public function test_merge_command_and_service_exist(): void
    {
        self::assertTrue(class_exists(LegacyPlanningDraftMergeService::class));
        self::assertTrue(class_exists(MergeLegacyPlanningDraftsCommand::class));
        self::assertTrue(class_exists(AuditPlanningDraftsCommand::class));
    }

    public function test_merge_command_requires_force_to_mutate(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MergeLegacyPlanningDraftsCommand::class))->getFileName(),
        );
        self::assertStringContainsString('{--dry-run', $src);
        self::assertStringContainsString('{--force', $src);
        self::assertStringContainsString('$dryRun = ! $force', $src);

        $cmd = new MergeLegacyPlanningDraftsCommand;
        self::assertTrue($cmd->getDefinition()->hasOption('force'));
        self::assertTrue($cmd->getDefinition()->hasOption('dry-run'));
    }

    public function test_resolver_canonical_is_null_site_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftResolver::class))->getFileName(),
        );
        self::assertStringContainsString("->whereNull('site_id')", $src);
        self::assertStringContainsString('listLegacyPerSiteDrafts', $src);
        self::assertStringContainsString('whereNotNull(\'site_id\')', $src);
    }

    public function test_create_handler_does_not_reuse_legacy_per_site_draft(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CreateContentProjectHandler::class))->getFileName(),
        );
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringNotContainsString('findPlanningDraftForSite($siteId)', $src);
    }

    public function test_intake_ensure_shared_does_not_promote_legacy(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        self::assertStringContainsString('Never promote legacy', $src);
        self::assertStringContainsString('lockForUpdate', $src);
    }

    public function test_dedupe_key_is_site_scoped_for_articles(): void
    {
        $service = (new ReflectionClass(LegacyPlanningDraftMergeService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LegacyPlanningDraftMergeService::class, 'dedupeKey');
        $method->setAccessible(true);

        $a = new SeoProjectTask;
        $a->setRawAttributes([
            'site_id' => 1,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'article_id' => 10,
            'keyword' => 'same phrase',
            'source_content' => 'Title A',
        ], true);

        $b = new SeoProjectTask;
        $b->setRawAttributes([
            'site_id' => 2,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'article_id' => 10,
            'keyword' => 'same phrase',
            'source_content' => 'Title A',
        ], true);

        $keyA = $method->invoke($service, $a);
        $keyB = $method->invoke($service, $b);
        self::assertIsString($keyA);
        self::assertIsString($keyB);
        self::assertNotSame($keyA, $keyB);
        self::assertStringContainsString('|1|', (string) $keyA);
        self::assertStringContainsString('|2|', (string) $keyB);
    }

    public function test_keyword_items_are_never_content_deduped(): void
    {
        $service = (new ReflectionClass(LegacyPlanningDraftMergeService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LegacyPlanningDraftMergeService::class, 'dedupeKey');
        $method->setAccessible(true);

        $a = new SeoProjectTask;
        $a->setRawAttributes([
            'site_id' => 3,
            'type' => SeoProjectTask::TYPE_CREATE,
            'article_id' => null,
            'keyword' => 'khóa kéo YKK',
            'title' => 'Khóa kéo YKK cho túi balo...',
            'source_content' => 'khóa kéo YKK',
        ], true);

        $b = new SeoProjectTask;
        $b->setRawAttributes([
            'site_id' => 3,
            'type' => SeoProjectTask::TYPE_CREATE,
            'article_id' => 0,
            'keyword' => 'khóa kéo YKK',
            'title' => 'Các loại khóa kéo YKK phổ biến...',
            'source_content' => 'khóa kéo YKK',
        ], true);

        self::assertNull($method->invoke($service, $a));
        self::assertNull($method->invoke($service, $b));
    }

    public function test_same_article_identity_is_true_duplicate(): void
    {
        $service = (new ReflectionClass(LegacyPlanningDraftMergeService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LegacyPlanningDraftMergeService::class, 'dedupeKey');
        $method->setAccessible(true);

        $a = new SeoProjectTask;
        $a->setRawAttributes([
            'site_id' => 6,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'article_id' => 99,
            'keyword' => 'one',
            'title' => 'Title one',
        ], true);

        $b = new SeoProjectTask;
        $b->setRawAttributes([
            'site_id' => 6,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'article_id' => 99,
            'keyword' => 'two',
            'title' => 'Title two different',
        ], true);

        self::assertSame($method->invoke($service, $a), $method->invoke($service, $b));
    }

    public function test_audit_command_uses_inventory_not_mutate(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditPlanningDraftsCommand::class))->getFileName(),
        );
        self::assertStringContainsString('inventory()', $src);
        self::assertStringNotContainsString('->merge(', $src);
        self::assertStringContainsString('expected_merged_item_count', $src);
        self::assertStringContainsString('duplicate_conflict_count', $src);
    }

    public function test_merge_service_archives_not_hard_deletes(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(LegacyPlanningDraftMergeService::class))->getFileName(),
        );
        self::assertStringContainsString('archived_at', $src);
        self::assertStringContainsString('archiveLegacyDraft', $src);
        self::assertStringNotContainsString('->delete()', $src);
        self::assertStringContainsString('dedupeKey', $src);
        self::assertStringContainsString('forceFill([', $src);
    }
}
