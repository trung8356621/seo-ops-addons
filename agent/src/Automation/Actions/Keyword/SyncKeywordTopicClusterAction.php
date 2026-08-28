<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Keyword;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use Illuminate\Support\Facades\DB;

final class SyncKeywordTopicClusterAction implements BusinessAction
{
    public function __construct(
        private readonly WorkflowKeywordResearchService $keywordResearch,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'keyword.topic_cluster.sync',
            name: 'Sync vocabulary keywords',
            description: 'Persist flat vocabulary keywords for an article (local).',
            module: 'keyword',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'keyword_groups' => ['type' => 'array', 'required' => true],
                'focus_phrase' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'focus_keyword_id' => ['type' => 'integer'],
                'vocabulary_count' => ['type' => 'integer'],
            ],
            emittedEvents: ['keyword.topic_cluster_synced'],
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

        /** @var array<string, mixed> $groups */
        $groups = is_array($input['keyword_groups'] ?? null) ? $input['keyword_groups'] : [];
        $focus = isset($input['focus_phrase']) ? trim((string) $input['focus_phrase']) : null;

        try {
            $sync = DB::connection('omi_seo_ai')->transaction(
                fn () => $this->keywordResearch->syncVocabularyKeywords($article, $groups, $focus),
            );
        } catch (\InvalidArgumentException $exception) {
            return ActionResult::failure('topic_cluster_invalid', $exception->getMessage());
        } catch (\Throwable $exception) {
            return ActionResult::failure('topic_cluster_sync_failed', $exception->getMessage());
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'focus_keyword_id' => (int) ($sync['focus_keyword_id'] ?? 0),
                'focus_phrase' => (string) ($sync['focus_phrase'] ?? ''),
                'vocabulary_count' => (int) ($sync['vocabulary_count'] ?? 0),
                'suggest_count' => (int) ($sync['suggest_count'] ?? 0),
                'tags_count' => (int) ($sync['tags_count'] ?? 0),
            ],
            events: [
                ActionSupport::articleEvent('keyword.topic_cluster_synced', $context, $articleId, [
                    'focus_keyword_id' => (int) ($sync['focus_keyword_id'] ?? 0),
                    'vocabulary_count' => (int) ($sync['vocabulary_count'] ?? 0),
                ]),
            ],
            changed: ['keyword'],
        );
    }
}
