<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFalseSuccessRepairService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ProjectRoot;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * False-success repair helper — pure/source checks (no AI, no live DB required).
 */
final class ContentProjectFalseSuccessRepairServiceTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_repair_message_and_command_contract(): void
    {
        self::assertSame(
            'Legacy repair: item was marked generated but no article content exists.',
            ContentProjectFalseSuccessRepairService::REPAIR_MESSAGE,
        );

        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Console/RepairContentProjectFalseSuccessCommand.php',
        );
        self::assertStringContainsString('seo:content-project:repair-project-state', $cmd);
        self::assertStringContainsString('fix-empty-success', $cmd);
        self::assertStringContainsString('dry-run', $cmd);
        self::assertStringContainsString('--apply', $cmd);
        self::assertStringNotContainsString('dispatchNextArticle', $cmd);
        self::assertStringNotContainsString('AiRouter', $cmd);

        $svc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectFalseSuccessRepairService.php',
        );
        self::assertStringContainsString('syncMirrorAndCounters', $svc);
        self::assertStringContainsString('STATUS_FAILED', $svc);
        self::assertStringNotContainsString('dispatchNextArticle', $svc);
    }

    public function test_detects_completed_without_body_and_spares_valid_body(): void
    {
        $ref = new \ReflectionClass(ContentProjectFalseSuccessRepairService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $isFalse = new ReflectionMethod($service, 'isFalseSuccess');
        $isFalse->setAccessible(true);
        $hasBody = new ReflectionMethod($service, 'articleHasBody');
        $hasBody->setAccessible(true);

        $empty = new SeoArticle;
        $empty->forceFill(['id' => 1, 'body' => '']);
        $filled = new SeoArticle;
        $filled->forceFill(['id' => 2, 'body' => '<p>ok</p>']);

        self::assertFalse($hasBody->invoke($service, $empty));
        self::assertTrue($hasBody->invoke($service, $filled));

        $completedEmpty = new SeoProjectTask;
        $completedEmpty->forceFill(['id' => 3341, 'status' => SeoProjectTask::STATUS_COMPLETED]);

        self::assertTrue($isFalse->invoke($service, $completedEmpty, false, [
            'status' => 'success',
        ]));
        self::assertFalse($isFalse->invoke($service, $completedEmpty, true, [
            'status' => 'success',
        ]));

        $pending = new SeoProjectTask;
        $pending->forceFill(['id' => 10, 'status' => SeoProjectTask::STATUS_PENDING]);
        self::assertFalse($isFalse->invoke($service, $pending, false, ['status' => 'pending']));
        self::assertTrue($isFalse->invoke($service, $pending, false, ['status' => 'success']));
    }

    public function test_claim_does_not_reuse_empty_create_article(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php',
        );

        self::assertStringContainsString('articleHasReusableGeneratedContent', $src);
        self::assertMatchesRegularExpression(
            '/ArticleCreate.*?articleHasReusableGeneratedContent/s',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/Success->value.*?! \$this->articleHasReusableGeneratedContent/s',
            $src,
        );
    }

    public function test_workflow_refuses_completed_state_for_empty_reused_article(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );

        self::assertStringContainsString('articleHasGeneratedBody', $src);
        self::assertMatchesRegularExpression(
            '/already_processed.*?! \$this->articleHasGeneratedBody/s',
            $src,
        );
        self::assertStringContainsString('Existing draft article has no generated content; retry generation.', $src);
    }

    public function test_provider_registers_command(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/SeoContentAiServiceProvider.php',
        );
        self::assertStringContainsString('RepairContentProjectFalseSuccessCommand::class', $src);
    }
}
