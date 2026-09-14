<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectItemFromFailedStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SplitResumeSharedProgressContractTest extends TestCase
{
    public function test_resume_handler_sets_partial_split_flag_and_progress(): void
    {
        $src = $this->source(ResumeProjectItemFromFailedStepHandler::class);
        self::assertStringContainsString("resumeSettings['resume_partial_split'] = true", $src);
        self::assertStringContainsString('resume_split_progress', $src);
        self::assertStringContainsString('resume_hint', $src);
    }

    public function test_workflow_injects_sectioned_state_only_for_resume_flag(): void
    {
        $src = $this->source(SeoProjectWorkflowRunService::class);
        self::assertStringContainsString('bindPartialSplitResumeState', $src);
        self::assertStringContainsString("empty(\$runSettings['resume_partial_split'])", $src);
        self::assertStringContainsString("variables['_sectioned_free_state']", $src);
        self::assertStringContainsString("variables['_sectioned_free_rerun_section_id']", $src);
        self::assertStringContainsString('resumeBagFromFailedParent', $src);
        self::assertStringContainsString(
            'Explicit «Chạy lại từ Viết bài» must NOT set resume_partial_split',
            $src,
        );
    }

    public function test_resolver_exposes_split_progress_and_public_orchestrator_lookup(): void
    {
        $src = $this->source(ContentProjectFailedStepResumeResolver::class);
        self::assertStringContainsString("'split_progress' => \$splitProgress", $src);
        self::assertStringContainsString('function resolveFailedOrchestratorResult', $src);
        self::assertStringContainsString('SplitExecutionProgressResolver', $src);

        $ref = new ReflectionClass(ContentProjectFailedStepResumeResolver::class);
        self::assertTrue($ref->getMethod('resolveFailedOrchestratorResult')->isPublic());
    }

    public function test_rerun_writing_confirm_warns_partial_not_reused(): void
    {
        $vi = LegacyAddonPath::resolve('lang/vi/filament.php');
        self::assertFileExists($vi);
        $src = (string) file_get_contents($vi);
        self::assertStringContainsString('item_action_rerun_writing_confirm', $src);
        self::assertStringContainsString('không được tái sử dụng', $src);
        self::assertStringContainsString('item_action_resume_failed_step_hint', $src);
        self::assertStringContainsString('Giữ lại các bước SPLIT', $src);
    }

    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }
}
