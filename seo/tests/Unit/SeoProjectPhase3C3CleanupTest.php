<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Console\RepairContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunConsolidationService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemMergeService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskRepairService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskUniqueWriter;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskCanonicalResolver;
use ReflectionClass;
use Tests\TestCase;

final class SeoProjectPhase3C3CleanupTest extends TestCase
{
    public function test_repair_and_diagnose_commands_registered(): void
    {
        $repair = new RepairContentProjectCommand;
        $diagnose = new DiagnoseContentProjectCommand;

        $this->assertSame('content-project:repair', $repair->getName());
        $this->assertSame('content-project:diagnose', $diagnose->getName());
        $this->assertStringContainsString('dry-run', (string) $repair->getDefinition()->getOption('dry-run')->getName());
        $this->assertTrue($diagnose->getDefinition()->hasOption('strict'));
        $this->assertTrue($diagnose->getDefinition()->hasOption('json'));
    }

    public function test_canonical_resolver_class_a_active_plus_trashed(): void
    {
        $resolver = new SeoProjectTaskCanonicalResolver;

        $active = new SeoProjectTask;
        $active->id = 10;
        $active->status = SeoProjectTask::STATUS_PENDING;
        $active->article_id = null;
        $active->archived_at = null;
        $active->deleted_at = null;

        $trashed = new SeoProjectTask;
        $trashed->id = 11;
        $trashed->status = SeoProjectTask::STATUS_PENDING;
        $trashed->article_id = null;
        $trashed->archived_at = null;
        $trashed->deleted_at = now();

        $out = $resolver->resolve([$active, $trashed]);

        $this->assertSame(10, $out['canonical_task_id']);
        $this->assertSame([11], array_values($out['duplicate_task_ids']));
        $this->assertSame('A', $out['classification']);
        $this->assertFalse($out['manual_review_required']);
    }

    public function test_canonical_resolver_class_b_sole_article(): void
    {
        $resolver = new SeoProjectTaskCanonicalResolver;

        $withArticle = new SeoProjectTask;
        $withArticle->id = 20;
        $withArticle->status = SeoProjectTask::STATUS_PENDING;
        $withArticle->article_id = 99;
        $withArticle->archived_at = null;
        $withArticle->deleted_at = null;

        $peer = new SeoProjectTask;
        $peer->id = 21;
        $peer->status = SeoProjectTask::STATUS_COMPLETED;
        $peer->article_id = null;
        $peer->archived_at = null;
        $peer->deleted_at = null;

        $out = $resolver->resolve([$peer, $withArticle]);

        $this->assertSame(20, $out['canonical_task_id']);
        $this->assertSame('B', $out['classification']);
        $this->assertContains(21, $out['duplicate_task_ids']);
    }

    public function test_canonical_resolver_class_c_completed_wins(): void
    {
        $resolver = new SeoProjectTaskCanonicalResolver;

        $completed = new SeoProjectTask;
        $completed->id = 30;
        $completed->status = SeoProjectTask::STATUS_COMPLETED;
        $completed->article_id = null;
        $completed->archived_at = null;
        $completed->deleted_at = null;

        $pending = new SeoProjectTask;
        $pending->id = 31;
        $pending->status = SeoProjectTask::STATUS_PENDING;
        $pending->article_id = null;
        $pending->archived_at = null;
        $pending->deleted_at = null;

        $out = $resolver->resolve([$pending, $completed]);

        $this->assertSame(30, $out['canonical_task_id']);
        $this->assertSame('C', $out['classification']);
        $this->assertFalse($out['manual_review_required']);
    }

    public function test_canonical_resolver_class_e_multiple_articles_manual(): void
    {
        $resolver = new SeoProjectTaskCanonicalResolver;

        $a = new SeoProjectTask;
        $a->id = 40;
        $a->status = SeoProjectTask::STATUS_COMPLETED;
        $a->article_id = 1;
        $a->archived_at = null;
        $a->deleted_at = null;

        $b = new SeoProjectTask;
        $b->id = 41;
        $b->status = SeoProjectTask::STATUS_COMPLETED;
        $b->article_id = 2;
        $b->archived_at = null;
        $b->deleted_at = null;

        $out = $resolver->resolve([$a, $b]);

        $this->assertNull($out['canonical_task_id']);
        $this->assertTrue($out['manual_review_required']);
        $this->assertSame('E', $out['classification']);
    }

    public function test_source_key_generator_unicode_stable(): void
    {
        $gen = new ProjectTaskSourceKeyGenerator;

        // Idempotent cùng input.
        $same = 'Hello Café World';
        $this->assertSame(
            $gen->generate(1, SeoProjectTask::TYPE_NEW_KEYWORD, SeoProjectTask::POST_TYPE_ARTICLE, $same),
            $gen->generate(1, SeoProjectTask::TYPE_NEW_KEYWORD, SeoProjectTask::POST_TYPE_ARTICLE, $same),
        );

        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl required for NFC/NFD parity');
        }

        $nfc = \Normalizer::normalize('Café', \Normalizer::FORM_C);
        $nfd = \Normalizer::normalize('Café', \Normalizer::FORM_D);
        $this->assertNotFalse($nfc);
        $this->assertNotFalse($nfd);
        $this->assertNotSame($nfc, $nfd);

        $a = $gen->generate(1, SeoProjectTask::TYPE_NEW_KEYWORD, SeoProjectTask::POST_TYPE_ARTICLE, (string) $nfd);
        $b = $gen->generate(1, SeoProjectTask::TYPE_NEW_KEYWORD, SeoProjectTask::POST_TYPE_ARTICLE, (string) $nfc);
        $this->assertSame($a, $b);
        $this->assertNotSame('', $a);
    }

    public function test_json_mirror_no_longer_writes_items(): void
    {
        $this->assertTrue(method_exists(SeoProjectRunItemService::class, 'mirrorJsonSafely'));

        $source = file_get_contents(
            (new ReflectionClass(SeoProjectRunItemService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('function mirrorJsonSafely(SeoProjectRun $run): void', $source);
        // Body no-op: không còn update items trong mirrorJsonSafely.
        $this->assertDoesNotMatchRegularExpression(
            '/function mirrorJsonSafely\(SeoProjectRun \$run\): void\s*\{[^}]*update\s*\(/s',
            $source,
        );

        $workflow = file_get_contents(
            (new ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName() ?: '',
        );
        $this->assertIsString($workflow);
        $this->assertStringContainsString("'items' => null", $workflow);
    }

    public function test_consolidation_marks_not_hard_deletes(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(SeoProjectRunConsolidationService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('consolidated_into_run_id', $source);
        $this->assertStringContainsString('relinkRun', $source);
        $this->assertStringNotContainsString("->whereIn('id', \$removedIds)->delete()", $source);
    }

    public function test_run_item_merge_service_has_task_and_run_relink(): void
    {
        $this->assertTrue(method_exists(SeoProjectRunItemMergeService::class, 'relinkTask'));
        $this->assertTrue(method_exists(SeoProjectRunItemMergeService::class, 'relinkRun'));
        $this->assertTrue(method_exists(SeoProjectTaskRepairService::class, 'repair'));
        $this->assertTrue(class_exists(SeoProjectTaskUniqueWriter::class));
        $this->assertTrue(method_exists(SeoProjectTaskUniqueWriter::class, 'createStrict'));
        $this->assertTrue(method_exists(SeoProjectTaskUniqueWriter::class, 'createOrReturnExisting'));
    }

    public function test_unique_error_code_exists(): void
    {
        $case = ContentProjectErrorCode::tryFrom('CONTENT_PROJECT_TASK_SOURCE_KEY_CONFLICT');
        $this->assertNotNull($case);
        $this->assertSame(ContentProjectErrorCode::TaskSourceKeyConflict, $case);
        $this->assertSame(SeoProjectRunItemStatus::Success->value, 'success');
    }

    public function test_unique_migration_validates_before_index(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find(
            '2026_07_19_201000_add_unique_project_source_key_to_seo_project_tasks_table.php',
        );
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('seo_project_tasks_project_id_source_key_unique', $source);
        $this->assertStringContainsString('Cannot add unique', $source);
        $this->assertStringContainsString('whereNull(\'source_key\')', $source);
        $this->assertStringContainsString('\\RuntimeException', $source);
        $this->assertStringNotContainsString('use RuntimeException;', $source);
        $this->assertStringNotContainsString('forceDelete', $source);
        $this->assertStringNotContainsString('->delete()', $source);
    }

    public function test_repair_service_defaults_documented_in_command(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(RepairContentProjectCommand::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('--apply', $source);
        $this->assertStringContainsString('DRY-RUN', $source);
    }
}
