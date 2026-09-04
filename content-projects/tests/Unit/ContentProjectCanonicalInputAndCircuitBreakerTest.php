<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskCanonicalInputBuilder;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchFailureSignature;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use PHPUnit\Framework\TestCase;

final class ContentProjectCanonicalInputAndCircuitBreakerTest extends TestCase
{
    public function test_rewrite_input_prefers_focus_keyword_then_title(): void
    {
        self::assertSame(
            'ABC',
            ContentProjectTaskCanonicalInputBuilder::forRewrite([
                'focus_keyword' => 'ABC',
                'post_title' => 'Old Title',
            ]),
        );

        self::assertSame(
            'Old Title',
            ContentProjectTaskCanonicalInputBuilder::forRewrite([
                'post_title' => 'Old Title',
            ]),
        );
    }

    public function test_create_input_omits_empty_labels(): void
    {
        $input = ContentProjectTaskCanonicalInputBuilder::forCreate([
            'post_title' => 'Túi Balo Quà Tặng',
            'focus_keyword' => 'túi balo quà tặng',
            'secondary_description' => 'Giới thiệu mẫu',
            'gallery_description' => '',
        ]);

        self::assertStringContainsString('Ý tưởng: Túi Balo Quà Tặng', $input);
        self::assertStringContainsString('Từ khóa chính: túi balo quà tặng', $input);
        self::assertStringContainsString('Mô tả: Giới thiệu mẫu', $input);
        self::assertStringNotContainsString('Mô tả sản phẩm:', $input);
    }

    public function test_failure_signature_strips_ids_and_groups_outline_empty(): void
    {
        $a = new ArticleExecutionResult(
            runId: 1,
            taskId: 10,
            runItemId: 100,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: empty output. DeepSeek correlation 550e8400-e29b-41d4-a716-446655440000',
            errorCode: 'external_workflow_failed',
        );
        $b = new ArticleExecutionResult(
            runId: 1,
            taskId: 11,
            runItemId: 101,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: empty output. DeepSeek',
            errorCode: 'external_workflow_failed',
        );

        self::assertSame(
            ContentProjectBatchFailureSignature::fromResult($a),
            ContentProjectBatchFailureSignature::fromResult($b),
        );
        self::assertStringContainsString('outline', ContentProjectBatchFailureSignature::fromResult($a));
        self::assertStringContainsString('empty_response', ContentProjectBatchFailureSignature::fromResult($a));
        self::assertStringContainsString('deepseek', ContentProjectBatchFailureSignature::fromResult($a));
        self::assertStringNotContainsString('550e8400', ContentProjectBatchFailureSignature::fromResult($a));
    }

    public function test_different_failure_signatures_do_not_match(): void
    {
        $a = new ArticleExecutionResult(
            runId: 1,
            taskId: 1,
            runItemId: 1,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline generation failed: empty output',
        );
        $b = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 2,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Article writer timeout',
        );
        $c = new ArticleExecutionResult(
            runId: 1,
            taskId: 3,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Failed,
            message: 'Outline marker mismatched_markers',
        );

        self::assertNotSame(
            ContentProjectBatchFailureSignature::fromResult($a),
            ContentProjectBatchFailureSignature::fromResult($b),
        );
        self::assertNotSame(
            ContentProjectBatchFailureSignature::fromResult($a),
            ContentProjectBatchFailureSignature::fromResult($c),
        );
    }

    public function test_engine_wires_circuit_breaker_helpers(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/RunEngine/ContentProjectRunEngine.php',
        );
        self::assertStringContainsString('recordConsecutiveFailureAndMaybeTrip', $src);
        self::assertStringContainsString('ContentProjectBatchFailureSignature', $src);
        self::assertStringContainsString('tryResumeAfterCircuitBreaker', $src);
        self::assertStringContainsString('isCircuitBreakerStopped', $src);
        self::assertStringNotContainsString('abandonPendingArticles($locked);', substr(
            $src,
            (int) strpos($src, 'recordConsecutiveFailureAndMaybeTrip'),
            2500,
        ));
    }

    public function test_rewrite_canonicalizer_exists_and_stops_before_ai(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/ContentProjectRewriteKeywordCanonicalizer.php',
        );
        self::assertStringContainsString('syncSeoMetaForArticle', $src);
        self::assertStringContainsString('syncSingleArticleFromWordPress', $src);
        self::assertStringContainsString('Không thể đồng bộ từ khóa SEO sang WordPress', $src);

        $workflow = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('ContentProjectRewriteKeywordCanonicalizer', $workflow);
        $canonPos = strpos($workflow, 'ContentProjectRewriteKeywordCanonicalizer');
        $aiPos = strpos($workflow, 'runPublishWorkflowForContext');
        self::assertNotFalse($canonPos);
        self::assertNotFalse($aiPos);
        self::assertLessThan($aiPos, $canonPos);
    }
}
