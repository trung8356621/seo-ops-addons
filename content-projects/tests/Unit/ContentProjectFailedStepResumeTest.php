<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectFailedStepResumeTest extends TestCase
{
    public function test_resume_command_registered_on_bus(): void
    {
        $src = $this->source(ContentProjectCommandBusRegistrar::class);
        self::assertStringContainsString('ResumeProjectItemFromFailedStepCommand::class', $src);
        self::assertStringContainsString('ResumeProjectItemFromFailedStepHandler::class', $src);
    }

    public function test_ui_primary_retry_dispatches_smart_create_or_rerun(): void
    {
        $view = $this->source(ViewSeoProject::class);
        self::assertStringContainsString('function createOrRerunOne', $view);
        self::assertStringContainsString('ContentProjectItemGenerationLaunchPlanner', $view);
        self::assertStringContainsString('function resumeFromFailedStep', $view);
        self::assertStringContainsString('ResumeProjectItemFromFailedStepCommand', $view);

        $bladePath = LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php');
        self::assertFileExists($bladePath);
        $blade = (string) file_get_contents($bladePath);
        self::assertStringContainsString('createOrRerunOne({{ $tid }})', $blade);
        self::assertStringContainsString('item_action_run_generation', $blade);
        self::assertStringContainsString('resumeFromFailedStep({{ $tid }})', $blade);
        self::assertStringNotContainsString('Chạy lại từ đầu', $blade);
    }

    public function test_agent_factory_exposes_resume_and_step_rerun(): void
    {
        $src = $this->source(ContentProjectAgentCommandFactory::class);
        self::assertStringContainsString("'content_project.resume_failed_step'", $src);
        self::assertStringContainsString("'content_project.rerun_step'", $src);
        self::assertStringContainsString('ResumeProjectItemFromFailedStepCommand', $src);
    }

    public function test_resume_resolver_maps_content_failure_from_steps(): void
    {
        $resolver = new ContentProjectFailedStepResumeResolver(new ArtifactReusePolicy);
        $method = (new ReflectionClass($resolver))->getMethod('classifyStepArray');
        $method->setAccessible(true);

        self::assertSame(
            ContentProjectRerunFromStep::Article->value,
            $method->invoke($resolver, [
                'status' => 'failed',
                'hook_key' => 'article.content.generate',
                'title' => 'Viết bài theo dàn ý',
            ]),
        );
        // Title alone still maps content-write (embedded «dàn ý» = input, not outline step).
        self::assertSame(
            ContentProjectRerunFromStep::Article->value,
            $method->invoke($resolver, [
                'status' => 'failed',
                'title' => 'Viết bài theo dàn ý',
            ]),
        );
        self::assertSame(
            ContentProjectRerunFromStep::Outline->value,
            $method->invoke($resolver, [
                'status' => 'failed',
                'hook_key' => 'article.outline.generate',
                'title' => 'Dàn ý',
            ]),
        );
    }

    public function test_outline_error_message_resumes_outline_not_article_create_action(): void
    {
        $resolver = new ContentProjectFailedStepResumeResolver(new ArtifactReusePolicy);
        $errorMethod = (new ReflectionClass($resolver))->getMethod('classifyErrorMessage');
        $errorMethod->setAccessible(true);

        $error = 'Khối Prompt — Dàn ý bài viết: article.outline.generate@0.1.0 — '
            .'TEXT_OUTSIDE_DECLARED_SECTIONS — Text outside declared sections.';
        self::assertSame(
            ContentProjectRerunFromStep::Outline->value,
            $errorMethod->invoke($resolver, $error),
        );

        self::assertSame(
            ContentProjectRerunFromStep::Outline->value,
            $errorMethod->invoke($resolver, 'Không tìm thấy outline để tạo lại bài.'),
        );

        $resolveKey = (new ReflectionClass($resolver))->getMethod('resolveFailedStepKey');
        $resolveKey->setAccessible(true);

        $item = new \Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
        $item->forceFill([
            'action' => 'article.create',
            'status' => 'failed',
            'error_message' => $error,
            'output_snapshot' => [],
        ]);

        self::assertSame(
            ContentProjectRerunFromStep::Outline->value,
            $resolveKey->invoke($resolver, $item),
            'Pipeline action article.create must not steal resume target from outline error.',
        );
    }

    public function test_content_node_seed_prefers_context_artifact_over_empty_meta(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class))->getFileName(),
        );
        self::assertStringContainsString('seedOutlineStateForContentRerun', $src);
        self::assertStringContainsString('article_writing_raw_input', $src);
        self::assertStringContainsString('resolveForArticle', $src);

        $createSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService::class))->getFileName(),
        );
        self::assertStringContainsString('articleOutlinePersist->persist', $createSrc);
        self::assertStringContainsString('direct_publish_outline_markdown', $createSrc);
    }

    public function test_generation_badge_latest_failed_wins_over_completed_status(): void
    {
        $badge = ContentProjectStatusBadgePresenter::generation('completed', 'failed');
        self::assertSame('failed', $badge['key']);
        self::assertSame('Failed', $badge['label']);

        $ok = ContentProjectStatusBadgePresenter::generation('completed', 'success');
        self::assertSame('success', $ok['key']);
        self::assertSame('Generated', $ok['label']);

        // Bare exec success without completed generation status ≠ Generated
        $pending = ContentProjectStatusBadgePresenter::generation('pending', 'success');
        self::assertSame('pending', $pending['key']);
    }

    public function test_resume_command_name(): void
    {
        $cmd = new ResumeProjectItemFromFailedStepCommand(1, [427]);
        self::assertSame('content_project.resume_failed_step', $cmd->name());
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }
}
