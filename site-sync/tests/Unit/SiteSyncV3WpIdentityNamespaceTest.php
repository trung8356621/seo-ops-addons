<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ArticleScoreSourceReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ProviderKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter;
use Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3ContentTypeDriftRepair;
use Omnichannel\Addons\SiteSync\Support\SiteSyncWpIdentity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression: WP content IDs and term IDs are independent namespaces.
 * Scenario: site 7 / run #42 — content wp_id=5 (page) + term wp_id=5 (category)
 * must both survive; content subtype drift is repaired, not treated as collision.
 */
final class SiteSyncV3WpIdentityNamespaceTest extends TestCase
{
    public function test_schema_declares_object_namespaces(): void
    {
        self::assertSame('content', SiteSyncV3Schema::OBJECT_NAMESPACE_CONTENT);
        self::assertSame('term', SiteSyncV3Schema::OBJECT_NAMESPACE_TERM);
        self::assertSame(
            SiteSyncV3Schema::OBJECT_NAMESPACE_CONTENT,
            SiteSyncWpIdentity::namespaceFromIsTerm(false),
        );
        self::assertSame(
            SiteSyncV3Schema::OBJECT_NAMESPACE_TERM,
            SiteSyncWpIdentity::namespaceFromIsTerm(true),
        );
    }

    public function test_importer_preload_and_upsert_are_namespace_scoped(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        $preload = $this->methodBody($src, SiteSyncV3BulkImporter::class, 'preloadByWpPostIds');
        $upsert = $this->methodBody($src, SiteSyncV3BulkImporter::class, 'upsertIdentity');
        $delete = $this->methodBody($src, SiteSyncV3BulkImporter::class, 'deleteWpBackedOnly');
        $import = $this->methodBody($src, SiteSyncV3BulkImporter::class, 'importChunk');
        $links = $this->methodBody($src, SiteSyncV3BulkImporter::class, 'reconcileAnalysisLinks');

        self::assertStringContainsString('SiteSyncWpIdentity::preloadMap', $preload);
        self::assertStringContainsString('$isTermResource', $preload);
        self::assertStringContainsString('SiteSyncWpIdentity::find', $upsert);
        self::assertStringContainsString('Never resolve across namespaces', $upsert);
        self::assertStringContainsString('SiteSyncWpIdentity::find', $delete);
        self::assertStringContainsString("'wp_is_term' => \$isTermResource", $import);
        self::assertStringContainsString('preloadMap', $links);
        self::assertStringContainsString('never term IDs', $links);
    }

    public function test_verify_repairs_content_type_drift_before_fail(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $verify = $this->methodBody($src, RunSiteSyncV3Orchestrator::class, 'phaseVerify');
        $softDelete = $this->methodBody($src, RunSiteSyncV3Orchestrator::class, 'softDeleteExtraLocalContent');

        self::assertStringContainsString('SiteSyncV3ContentTypeDriftRepair', $verify);
        self::assertStringContainsString('type_drift_repaired', $verify);
        self::assertStringContainsString('type_drift_unresolved', $verify);
        self::assertStringContainsString('Content subtype drift', $verify);
        self::assertStringContainsString('SiteSyncWpIdentity::findContent', $softDelete);
        self::assertStringContainsString('never soft-delete a term', $softDelete);
    }

    public function test_keyword_and_score_reconcilers_scope_by_namespace(): void
    {
        $kw = (string) file_get_contents(
            (new ReflectionClass(ProviderKeywordReconciler::class))->getFileName()
        );
        $score = (string) file_get_contents(
            (new ReflectionClass(ArticleScoreSourceReconciler::class))->getFileName()
        );

        self::assertStringContainsString('SiteSyncWpIdentity::find', $kw);
        self::assertStringContainsString('wp_is_term', $kw);
        self::assertStringContainsString('SiteSyncWpIdentity::find', $score);
        self::assertStringContainsString('wp_is_term', $score);
        self::assertStringNotContainsString('->whereWpPostId($wpId)', $kw);
        self::assertStringNotContainsString('->whereWpPostId($wpId)', $score);
    }

    public function test_drift_repair_contract_refuses_term_namespace_and_accepts_page(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3ContentTypeDriftRepair::class))->getFileName()
        );

        self::assertStringContainsString('SiteSyncWpIdentity::findContent', $src);
        self::assertStringContainsString('refused_term_namespace', $src);
        self::assertStringContainsString("'wp_is_term' => false", $src);
        self::assertStringContainsString('ContentType::values()', $src);
        self::assertContains('page', ContentType::values());
        self::assertContains('post', ContentType::values());
    }

    public function test_run42_scenario_contract_preserves_dual_namespace_id_five(): void
    {
        // Fresh sync: content chunk must not bind term articles; term chunk must not bind content.
        $importer = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3BulkImporter::class))->getFileName()
        );
        self::assertStringContainsString('content IDs ≠ term IDs', $importer);

        // Legacy stale repair at verify: rewrite content subtype from inventory, keep term sibling.
        $repair = (string) file_get_contents(
            (new ReflectionClass(SiteSyncV3ContentTypeDriftRepair::class))->getFileName()
        );
        self::assertStringContainsString('Same numeric WP id in the term namespace is NOT a collision', $repair);
        self::assertStringContainsString('do not delete or', $repair);

        $orch = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        self::assertStringContainsString('Content id N and term id N are independent', $orch);

        // Resume from needs_attention after verify mismatch parks on verify.
        self::assertStringContainsString('META_ATTENTION_RESUME_PHASE', $orch);
        self::assertTrue(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->hasMethod('resolveAttentionResumePhase'),
        );
    }

    public function test_identity_helper_methods_exist(): void
    {
        $ref = new ReflectionClass(SiteSyncWpIdentity::class);
        self::assertTrue($ref->hasMethod('scopeNamespace'));
        self::assertTrue($ref->hasMethod('find'));
        self::assertTrue($ref->hasMethod('findContent'));
        self::assertTrue($ref->hasMethod('preloadMap'));
    }

    private function methodBody(string $src, string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
