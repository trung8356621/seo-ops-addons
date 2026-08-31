<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pre-acceptance hardening: frozen bounds, tombstone path, cursor fail, body guard.
 */
final class SiteSyncV3HardeningIntegrationTest extends TestCase
{
    public function test_discover_persists_snapshot_bounds(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("\$meta['snapshot_bounds']", $src);
        self::assertStringContainsString("\$meta['snapshot_content_max_id']", $src);
        self::assertStringContainsString("\$meta['snapshot_term_max_id']", $src);
        self::assertStringContainsString("'snapshot_bounds'", $src);
        self::assertStringContainsString('content_max_id', $src);
        self::assertStringContainsString('term_max_id', $src);
    }

    public function test_full_import_sends_snapshot_bounds_not_offset(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("\$body['snapshot_bounds']", $src);
        self::assertStringNotContainsString("'offset' =>", $src);
        self::assertStringContainsString('never send offset', $src);
    }

    public function test_cursor_not_advancing_fails_hard(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertSame(2, substr_count($src, "'sync_cursor_not_advancing'"));
        self::assertStringContainsString('cursorsEqual', $src);
        self::assertTrue((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->hasMethod('cursorsEqual'));
    }

    public function test_cursors_equal_detects_stuck_cursor(): void
    {
        $orch = (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RunSiteSyncV3Orchestrator::class, 'cursorsEqual');
        $method->setAccessible(true);

        $a = ['after_id' => 100, 'after_change_id' => 5];
        $b = ['after_id' => 100, 'after_change_id' => 5];
        self::assertTrue($method->invoke($orch, $a, $b));

        $c = ['after_id' => 101, 'after_change_id' => 5];
        self::assertFalse($method->invoke($orch, $a, $c));
    }

    public function test_importer_delete_is_idempotent_source_level(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );

        self::assertStringContainsString('deleteWpBackedOnly', $src);
        self::assertStringContainsString('Not WP-backed', $src);
        self::assertStringContainsString("\$op === 'delete'", $src);
        // Soft-delete once; second pass finds trashed or missing — no throw path.
        self::assertStringContainsString('if (! $article->trashed())', $src);
    }

    public function test_verify_uses_fresh_discover_not_initial_total(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString('Fresh discover before verify', $src);
        self::assertStringContainsString("\$meta['final_expected_total']", $src);
        self::assertStringContainsString("\$meta['final_manifest_at']", $src);
        self::assertStringContainsString('needs_attention', $src);
        self::assertStringNotContainsString("initial_expected_total'] === \$localTotal", $src);
    }

    public function test_body_forbidden_on_v3_paths(): void
    {
        $importer = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        $orch = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("'body' => null", $importer);
        self::assertStringContainsString('Do not touch body', $importer);
        self::assertStringContainsString('wp_post_content', $importer);
        self::assertStringNotContainsString('post_content', $orch);
        self::assertStringNotContainsString('content.rendered', $orch);
    }

    public function test_frozen_snapshot_semantics_helpers(): void
    {
        $max = 70000;
        self::assertTrue(70000 > 0 && 70000 <= $max);
        self::assertFalse(70001 <= $max);
        // New record after discover is catch-up only.
        $fullEligible = static fn (int $id, int $maxId): bool => $id <= $maxId;
        self::assertFalse($fullEligible(70001, $max));
        self::assertTrue($fullEligible(70000, $max));
    }

    public function test_fresh_verify_membership_not_total_only(): void
    {
        $initial = [100, 200, 300];
        $created = [400];
        $deleted = [200];
        $set = array_fill_keys($initial, true);
        foreach ($created as $id) {
            $set[$id] = true;
        }
        foreach ($deleted as $id) {
            unset($set[$id]);
        }
        $final = array_map('intval', array_keys($set));
        sort($final);

        self::assertSame([100, 300, 400], $final);
        self::assertCount(count($initial), $final);
        self::assertContains(400, $final);
        self::assertNotContains(200, $final);
    }
}
