<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Article\ApproveArticleAction;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Http\Controllers\ArticleReviewActionController;
use Omnichannel\Addons\Content\Http\Requests\ArticleReviewActionRequest;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Feature-style/structural coverage cho việc "cutover" các entry-point Approve cũ
 * (ArticleResource, EditArticle Livewire, ApproveArticleAction automation) sang
 * ArticleReviewService làm nguồn sự thật duy nhất. Dùng reflection/source assertions thay vì
 * DB thật — nhất quán với pattern SeoProjectArchiveServiceTest.
 */
final class ArticleReviewWorkflowTest extends TestCase
{
    public function test_review_action_controller_depends_on_article_review_service(): void
    {
        $ctor = (new ReflectionClass(ArticleReviewActionController::class))->getConstructor();

        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        self::assertSame(ArticleReviewService::class, $params[0]->getType()?->getName());

        $ref = new ReflectionClass(ArticleReviewActionController::class);
        self::assertTrue($ref->hasMethod('show'));
        self::assertTrue($ref->hasMethod('store'));
    }

    public function test_review_action_request_only_allows_workflow_actions_and_caps_note_length(): void
    {
        $request = new ArticleReviewActionRequest;
        $rules = $request->rules();

        self::assertArrayHasKey('action', $rules);
        self::assertArrayHasKey('note', $rules);
        self::assertContains('max:5000', $rules['note']);

        self::assertTrue(method_exists($request, 'actionType'));
        self::assertTrue(method_exists($request, 'note'));

        $inRule = (string) $rules['action'][2];
        self::assertStringContainsString('"reopen"', $inRule);
        self::assertStringContainsString('"unapprove"', $inRule);
    }

    public function test_edit_article_page_exposes_review_bootstrap_and_livewire_fallback(): void
    {
        $ref = new ReflectionClass(EditArticle::class);

        self::assertTrue($ref->hasMethod('getArticleReviewBootstrap'));
        self::assertTrue($ref->hasMethod('performArticleReviewAction'));

        $method = $ref->getMethod('performArticleReviewAction');
        $params = $method->getParameters();
        self::assertSame('action', $params[0]->getName());
        self::assertSame('string', $params[0]->getType()?->getName());
        self::assertSame('note', $params[1]->getName());
        self::assertTrue($params[1]->allowsNull());
    }

    public function test_edit_article_legacy_toggle_delegates_to_article_resource_review_action(): void
    {
        $method = (new ReflectionClass(EditArticle::class))->getMethod('approveArticle');
        $filename = (string) $method->getFileName();
        $source = $this->readMethodSource($filename, $method->getStartLine(), $method->getEndLine());

        self::assertStringContainsString('ArticleResource::runApproveArticleAction', $source);
        self::assertStringNotContainsString('SeoProjectApprovalService', $source);
    }

    public function test_article_resource_approve_entry_point_uses_article_review_service(): void
    {
        $ref = new ReflectionClass(ArticleResource::class);
        self::assertTrue($ref->hasMethod('runApproveArticleAction'));
        self::assertTrue($ref->hasMethod('makeApproveArticleTableAction'));

        $method = $ref->getMethod('runApproveArticleAction');
        $source = $this->readMethodSource((string) $method->getFileName(), $method->getStartLine(), $method->getEndLine());

        self::assertStringContainsString('ArticleReviewService', $source);
        self::assertStringContainsString('availableActions', $source);
        self::assertStringNotContainsString('SeoProjectApprovalService::approveLinkedProject', $source);
    }

    public function test_approve_article_automation_action_delegates_to_article_review_service(): void
    {
        $ctor = (new ReflectionClass(ApproveArticleAction::class))->getConstructor();
        self::assertNotNull($ctor);
        self::assertSame(ArticleReviewService::class, $ctor->getParameters()[0]->getType()?->getName());

        $execute = (new ReflectionClass(ApproveArticleAction::class))->getMethod('execute');
        $source = $this->readMethodSource((string) $execute->getFileName(), $execute->getStartLine(), $execute->getEndLine());

        self::assertStringContainsString('resolveStatus', $source);
        self::assertStringContainsString('performAction', $source);
        self::assertStringNotContainsString('SeoProjectApprovalService', $source);
    }

    public function test_approve_article_action_definition_key_is_unchanged_for_backward_compatibility(): void
    {
        $definition = ApproveArticleAction::definition();

        self::assertSame('article.approve', $definition->key);
    }

    private function readMethodSource(string $filename, int $startLine, int $endLine): string
    {
        $lines = file($filename);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
