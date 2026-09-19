<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArticleRowStatusResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRowStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use PHPUnit\Framework\TestCase;

final class ContentProjectShortArticleWarningContractTest extends TestCase
{
    public function test_short_usable_article_stays_completed_with_warning(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'success',
            'persist_status' => 'applied',
            'warning_code' => ArticleGenerationLengthValidator::WARNING_BELOW_TARGET,
            'warning_message' => ArticleGenerationLengthValidator::warningMessage(479, 501),
            'actual_words' => 479,
            'target_words' => 501,
            'workflow_steps' => [],
        ]);

        self::assertSame(ContentProjectArticleRowStatus::CODE_COMPLETED, $status->code);
        self::assertSame('Hoàn tất ⚠', $status->label);
        self::assertSame('Article is shorter than target: 479/501 words.', $status->tooltip);
        self::assertNotSame(ContentProjectArticleRowStatus::CODE_FAILED, $status->code);
    }

    public function test_runtime_success_item_keeps_warning_metadata(): void
    {
        $runtime = (new ContentProjectArticleRuntimeStatusResolver)->resolve([
            'run_item' => [
                'status' => 'success',
                'attempt' => 1,
                'warning_code' => ArticleGenerationLengthValidator::WARNING_BELOW_TARGET,
                'warning_message' => ArticleGenerationLengthValidator::warningMessage(479, 501),
                'actual_words' => 479,
                'target_words' => 501,
            ],
            'task_status' => 'completed',
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_COMPLETED, $runtime->state);
        self::assertSame('Hoàn tất ⚠', $runtime->label);
        self::assertSame('Article is shorter than target: 479/501 words.', $runtime->warning);
        self::assertFalse($runtime->isActive);
    }

    public function test_target_length_success_has_no_warning_badge(): void
    {
        $status = (new ContentProjectArticleRowStatusResolver)->resolve([
            'status' => 'success',
            'persist_status' => 'applied',
            'workflow_steps' => [],
        ]);
        self::assertSame('Hoàn tất', $status->label);
        self::assertNull($status->tooltip);
    }

    public function test_workflow_run_service_persists_warning_on_success_snapshot(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('lengthWarningFromWorkflowResult', $src);
        self::assertStringContainsString("'warning_code'", $src);
        self::assertStringContainsString('WARNING_BELOW_TARGET', $src);
        self::assertStringContainsString('wasAlreadyFailed', $src);
    }
}
