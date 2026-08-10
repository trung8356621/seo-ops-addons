<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AcknowledgeProjectItemGenerationErrorHandler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AcknowledgeProjectItemGenerationErrorTest extends TestCase
{
    public function test_command_and_handler_wiring_source(): void
    {
        $cmd = new AcknowledgeProjectItemGenerationErrorCommand(7, [428]);
        self::assertSame('content_project.acknowledge_generation_error', $cmd->name());

        $handlerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(AcknowledgeProjectItemGenerationErrorHandler::class))->getFileName(),
        );
        self::assertStringContainsString('SeoProjectRunItemStatus::Success', $handlerSrc);
        self::assertStringContainsString('acknowledged_error', $handlerSrc);
        self::assertStringContainsString('STATUS_COMPLETED', $handlerSrc);
        self::assertStringContainsString('ITEMS_GENERATION_ERROR_ACKNOWLEDGED', $handlerSrc);

        $bus = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src'
            .DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'ContentProject'
            .DIRECTORY_SEPARATOR.'Application'
            .DIRECTORY_SEPARATOR.'ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('AcknowledgeProjectItemGenerationErrorCommand::class', $bus);
        self::assertStringContainsString('AcknowledgeProjectItemGenerationErrorHandler::class', $bus);

        $caps = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src'
            .DIRECTORY_SEPARATOR.'Services'
            .DIRECTORY_SEPARATOR.'ContentProject'
            .DIRECTORY_SEPARATOR.'Application'
            .DIRECTORY_SEPARATOR.'Capabilities'
            .DIRECTORY_SEPARATOR.'ContentProjectCapabilityRegistry.php',
        );
        self::assertStringContainsString('content_project.acknowledge_generation_error', $caps);

        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src'
            .DIRECTORY_SEPARATOR.'Filament'
            .DIRECTORY_SEPARATOR.'Resources'
            .DIRECTORY_SEPARATOR.'SeoProjectResource'
            .DIRECTORY_SEPARATOR.'Pages'
            .DIRECTORY_SEPARATOR.'ViewSeoProject.php',
        );
        self::assertStringContainsString('function acknowledgeGenerationError', $view);
        self::assertStringContainsString('AcknowledgeProjectItemGenerationErrorCommand', $view);

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources')
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'components'
            .DIRECTORY_SEPARATOR.'content-project-item-actions-menu.blade.php',
        );
        self::assertStringContainsString('acknowledgeGenerationError({{ $tid }})', $blade);
        self::assertStringContainsString('prefer_acknowledge_error', $blade);
    }

    public function test_presenter_prefers_acknowledge_when_published_with_failed_generation(): void
    {
        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'failed'],
            'generation_status' => 'failed',
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/seo/articles/9624/edit',
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'message' => 'Action schedule not allowed in lifecycle=generating',
            'has_unpublished_changes' => true,
        ]);

        self::assertTrue($flags['acknowledge_error']);
        self::assertTrue($flags['prefer_acknowledge_error']);
        self::assertTrue($flags['create_or_rerun']);
        self::assertFalse($flags['run_again']);
    }

    public function test_presenter_hides_acknowledge_for_failed_item_without_article(): void
    {
        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'failed'],
            'generation_status' => 'failed',
            'can_generate' => true,
            'can_regen' => true,
            'article_edit_url' => null,
            'is_generation_stale' => false,
            'is_genuinely_running' => false,
            'message' => 'Không tìm thấy đầy đủ dàn ý và hướng dẫn viết để tạo lại bài.',
        ]);

        self::assertFalse($flags['acknowledge_error']);
        self::assertFalse($flags['prefer_acknowledge_error']);
        self::assertTrue($flags['create_or_rerun']);
        self::assertFalse($flags['run_again']);
    }

    public function test_badge_labels_fallback_english_without_translator(): void
    {
        $failed = ContentProjectStatusBadgePresenter::generation('completed', 'failed');
        self::assertSame('failed', $failed['key']);
        self::assertSame('Failed', $failed['label']);

        $ok = ContentProjectStatusBadgePresenter::generation('completed', 'success');
        self::assertSame('Generated', $ok['label']);

        $life = ContentProjectStatusBadgePresenter::lifecycle('published');
        self::assertSame('Published', $life['label']);

        $badgeBlade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-status-badge.blade.php'),
        );
        self::assertStringContainsString("'success', 'generated', 'approved'", $badgeBlade);
        self::assertStringContainsString("'pending', 'draft', 'none'", $badgeBlade);
        self::assertStringContainsString("'skipped', 'cancelled', 'archived'", $badgeBlade);
        self::assertStringContainsString("'skipped', 'cancelled', 'archived' => 'background:#e2e8f0;", $badgeBlade);
    }
}
