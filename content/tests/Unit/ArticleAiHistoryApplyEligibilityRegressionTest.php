<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryLegacyClassifier;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryListService;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression for Article #3337 / PromptResult #4853 Apply false-success:
 * - History blanks `result` for payload size; classification must use loaded output.
 * - Valid markdown article without TASK markers is apply-eligible.
 * - Outline-marker payload remains rejected.
 * - Pending-only apply label must not claim body was saved.
 */
final class ArticleAiHistoryApplyEligibilityRegressionTest extends TestCase
{
    /**
     * Shape of PromptResult #4853 (VPS evidence): completed content hook, no TASK markers,
     * markdown article with meta/SEO lines + body — not an outline artifact.
     */
    private function pr4853LikeOutput(): string
    {
        return <<<'MD'
Meta description: Shein là gì? Khám phá toàn bộ thông tin về gã khổng lồ thời trang nhanh.

SEO title: Shein là gì? Các bước mua hàng tại Shein đơn giản nhất

Shein không còn là cái tên xa lạ với giới trẻ yêu thời trang trên toàn thế giới.
Chỉ trong vài năm ngắn ngủi, thương hiệu này đã vươn lên trở thành gã khổng lồ
trong ngành **thời trang nhanh** (fast fashion).

## Lịch sử hình thành

Shein bắt đầu như một cửa hàng online nhỏ và mở rộng nhờ mô hình sản xuất nhanh.

## Cách mua hàng

1. Tạo tài khoản
2. Chọn sản phẩm và kiểm tra đánh giá
3. Thanh toán quốc tế an toàn

Kết luận: mua sắm thông minh giúp tận hưởng ưu điểm của Shein mà vẫn kiểm soát rủi ro.
MD;
    }

    private function classifier(): ArticleAiHistoryLegacyClassifier
    {
        $outlineResolver = new ArticleOutlineResolver(
            new WorkflowParserService(new SeoPromptSettingsService, new SeoOverviewSettingsService),
        );

        return new ArticleAiHistoryLegacyClassifier($outlineResolver, new ArtifactReusePolicy);
    }

    public function test_pr4853_like_output_is_valid_article_content_without_outline_markers(): void
    {
        $output = $this->pr4853LikeOutput();
        $policy = new ArtifactReusePolicy;

        self::assertFalse($policy->looksLikeOutlineMarkerPayload($output));
        self::assertTrue($policy->isValidArticleContentPayload($output));

        $typed = $this->classifier()->classify([
            'artifact_type' => WorkflowArtifactType::ArticleContent->value,
            'hook_key' => 'article.content.generate',
            'status' => 'completed',
            'output' => $output,
        ], $output);

        self::assertTrue($typed['can_apply']);
        self::assertSame(WorkflowArtifactType::ArticleContent->value, $typed['artifact_type']);
        self::assertSame('typed_artifact_content', $typed['reason']);

        $legacy = $this->classifier()->classify([
            'hook_key' => 'article.content.generate',
            'status' => 'completed',
            'output' => $output,
        ], $output);

        self::assertTrue($legacy['can_apply']);
        self::assertSame('legacy_content_hook_or_role', $legacy['reason']);
    }

    public function test_empty_history_result_without_loaded_output_is_not_applyable(): void
    {
        $result = $this->classifier()->classify([
            'hook_key' => 'article.content.generate',
            'status' => 'completed',
            'output' => '',
        ], '');

        self::assertFalse($result['can_apply']);
        self::assertSame('legacy_content_payload_invalid', $result['reason']);
    }

    public function test_list_resolve_classify_output_prefers_loaded_prompt_result_text(): void
    {
        $method = new ReflectionMethod(ArticleAiHistoryListService::class, 'resolveClassifyOutput');
        $method->setAccessible(true);

        $service = (new ReflectionClass(ArticleAiHistoryListService::class))
            ->newInstanceWithoutConstructor();

        $resolved = $method->invoke(
            $service,
            ['result' => '', 'result_id' => 4853],
            [4853 => $this->pr4853LikeOutput()],
        );

        self::assertStringContainsString('Shein là gì?', $resolved);
        self::assertStringNotContainsString('[START_TASK_', $resolved);
    }

    public function test_applied_label_distinguishes_pending_draft_from_committed_body(): void
    {
        $method = new ReflectionMethod(ArticleAiHistoryListService::class, 'buildAppliedLabel');
        $method->setAccessible(true);
        $service = (new ReflectionClass(ArticleAiHistoryListService::class))
            ->newInstanceWithoutConstructor();

        self::assertNull($method->invoke($service, 0, 0, 0));
        self::assertSame(
            'Đã đưa vào bản nháp editor 1 lần (chưa lưu body)',
            $method->invoke($service, 1, 0, 1),
        );
        self::assertSame(
            'Đã lưu vào bài 2 lần',
            $method->invoke($service, 2, 2, 0),
        );
        self::assertSame(
            'Đã lưu vào bài 1 lần · còn bản nháp chưa lưu',
            $method->invoke($service, 2, 1, 1),
        );
    }

    public function test_apply_block_reason_does_not_blame_outline_marker_for_empty_payload(): void
    {
        $method = new ReflectionMethod(ArticleAiHistoryListService::class, 'applyBlockReason');
        $method->setAccessible(true);
        $service = (new ReflectionClass(ArticleAiHistoryListService::class))
            ->newInstanceWithoutConstructor();

        $message = $method->invoke($service, [
            'hook_key' => 'article.content.generate',
        ], [
            'can_apply' => false,
            'reason' => 'legacy_content_payload_invalid',
            'normalized_payload' => '',
            'artifact_type' => null,
            'classification' => 'unknown',
        ]);

        self::assertSame('Output bài viết trống trong lịch sử — không thể áp dụng.', $message);

        $markerMessage = $method->invoke($service, [
            'hook_key' => 'article.content.generate',
        ], [
            'can_apply' => false,
            'reason' => 'legacy_content_contains_outline_marker',
            'normalized_payload' => '[START_TASK_1_OUTLINE]## X',
            'artifact_type' => null,
            'classification' => 'unknown',
        ]);

        self::assertStringContainsString('marker dàn ý', (string) $markerMessage);
    }

    public function test_apply_service_rejects_invalid_payload_before_draft_row(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        $createPos = strpos($src, 'SeoArticleAiHistoryApply::query()->create');
        $validatePos = strpos($src, 'artifact_payload_invalid');
        self::assertNotFalse($createPos);
        self::assertNotFalse($validatePos);
        self::assertLessThan($createPos, $validatePos);
    }

    public function test_pending_content_bootstrap_clears_editor_document(): void
    {
        $edit = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle::class
            ))->getFileName(),
        );
        $jsx = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('ai_history_pending_content', $edit);
        self::assertStringContainsString("editorDocBoot['document'] = null", $edit);
        self::assertStringContainsString('pendingContentApply', $edit);
        self::assertStringContainsString('initialEditorDocument = null', $jsx);
        self::assertStringContainsString('aiHistoryPendingApply', $jsx);
    }
}
