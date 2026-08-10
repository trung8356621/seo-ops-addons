<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Article;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use App\Models\User;

/**
 * Advance article review workflow (submit_review → approve → archive) — key `article.approve`
 * giữ nguyên cho automation/BC, nhưng nội bộ chạy qua {@see ArticleReviewService} (article-level
 * state machine) thay vì SeoProjectApprovalService::approveLinkedProject (project-level, cũ).
 */
final class ApproveArticleAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleReviewService $reviewService,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.approve',
            name: 'Approve article (linked project)',
            description: 'Content manager marks staff editing complete → approves linked SeoProject.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'actor_user_id' => ['type' => 'integer', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
                'project_name' => ['type' => 'string'],
                'already_approved' => ['type' => 'boolean'],
            ],
            idempotent: true,
            lockScope: 'article',
            emittedEvents: ['article.approved'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        $article = ActionSupport::findArticle($articleId);
        if ($article === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        $actorId = (int) ($input['actor_user_id'] ?? $context->actorId ?? 0);
        $user = $actorId > 0 ? User::query()->find($actorId) : null;
        if (! $user instanceof User) {
            return ActionResult::failure('actor_required', 'article.approve requires an authenticated actor.');
        }

        $status = $this->reviewService->resolveStatus($article);
        $available = $this->reviewService->availableActions($article, $user);
        $next = $available[0] ?? null;

        if ($next === null) {
            return ActionResult::success(
                output: [
                    'article_id' => $articleId,
                    'project_id' => 0,
                    'project_name' => '',
                    'already_approved' => true,
                    'review_status' => $status->value,
                ],
            );
        }

        $nextAction = ArticleReviewActionType::tryFrom((string) $next['type']);
        if (! $nextAction instanceof ArticleReviewActionType) {
            return ActionResult::failure('approval_rejected', 'No valid review action for current status.');
        }

        try {
            $review = $this->reviewService->performAction($article, $user, $nextAction);
        } catch (ArticleReviewException $exception) {
            return ActionResult::failure('approval_rejected', $exception->getMessage());
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'project_id' => 0,
                'project_name' => '',
                'already_approved' => false,
                'review_status' => (string) $review->to_status,
            ],
            events: [
                ActionSupport::articleEvent('article.approved', $context, $articleId, [
                    'review_status' => (string) $review->to_status,
                ]),
            ],
            changed: ['review_status'],
        );
    }
}
