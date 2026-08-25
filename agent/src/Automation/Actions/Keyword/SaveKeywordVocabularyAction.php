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
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SaveKeywordVocabularyAction implements BusinessAction
{
    public function __construct(
        private readonly WorkflowKeywordResearchService $keywordResearch,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'keyword.vocabulary.save',
            name: 'Save keyword vocabulary',
            description: 'Persist vocabulary / topic research onto article keywords (local).',
            module: 'keyword',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'keyword_groups' => ['type' => 'array', 'required' => true],
                'focus_phrase' => ['type' => 'string', 'required' => false],
                'prompt_result_id' => ['type' => 'integer', 'required' => false],
                'project_id' => ['type' => 'integer', 'required' => false],
                'project_task_id' => ['type' => 'integer', 'required' => false],
                'workflow_node_id' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer'],
                'children_count' => ['type' => 'integer'],
                'ki_feedback' => ['type' => 'object'],
            ],
            emittedEvents: ['keyword.vocabulary_saved'],
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
        $provenance = array_filter([
            'prompt_result_id' => isset($input['prompt_result_id']) ? (int) $input['prompt_result_id'] : null,
            'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
            'project_task_id' => isset($input['project_task_id']) ? (int) $input['project_task_id'] : null,
            'workflow_node_id' => isset($input['workflow_node_id']) ? trim((string) $input['workflow_node_id']) : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== 0);

        try {
            // Persist vocabulary + topic cluster first; Related Topics KI feedback runs after commit.
            $sync = DB::connection('omi_seo_ai')->transaction(function () use ($article, $groups, $focus) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'seo_article_keywords'],
                    ['meta_value' => json_encode($groups, JSON_UNESCAPED_UNICODE)],
                );

                return $this->keywordResearch->syncTopicCluster(
                    $article,
                    $groups,
                    $focus,
                    ingestRelatedTopics: false,
                );
            });
        } catch (\InvalidArgumentException $exception) {
            return ActionResult::failure('vocabulary_invalid', $exception->getMessage());
        } catch (Throwable $exception) {
            return ActionResult::failure('vocabulary_save_failed', $exception->getMessage());
        }

        $relatedTopics = is_array($sync['related_topics'] ?? null) ? $sync['related_topics'] : [];
        $kiFeedback = [
            'discovered' => 0,
            'ingested' => 0,
            'classified' => 0,
            'filtered' => 0,
            'duplicates' => 0,
            'source_preserved' => 0,
            'groups' => [],
            'errors' => [],
        ];

        if ($relatedTopics !== []) {
            try {
                $kiFeedback = $this->keywordResearch->ingestRelatedTopicsSafe($article, $relatedTopics, $provenance);
            } catch (Throwable $exception) {
                // Vocabulary already saved — secondary KI enrichment must not destroy workflow success.
                RuntimeLogger::error('vocabulary.ki_feedback_failed', [
                    'article_id' => $articleId,
                    'message' => $exception->getMessage(),
                ]);
                $kiFeedback['errors'][] = mb_substr($exception->getMessage(), 0, 160);
                $kiFeedback['discovered'] = count($relatedTopics);
                $kiFeedback['filtered'] = count($relatedTopics);
            }
        }

        $suggestCount = (int) (($kiFeedback['ingested'] ?? 0) + ($kiFeedback['duplicates'] ?? 0));

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'parent_id' => (int) ($sync['parent_id'] ?? 0),
                'parent_phrase' => (string) ($sync['parent_phrase'] ?? ''),
                'children_count' => (int) ($sync['children_count'] ?? 0),
                'suggest_count' => $suggestCount,
                'tags_count' => (int) ($sync['tags_count'] ?? 0),
                'ki_feedback' => $kiFeedback,
            ],
            events: [
                ActionSupport::articleEvent('keyword.vocabulary_saved', $context, $articleId, [
                    'parent_id' => (int) ($sync['parent_id'] ?? 0),
                    'ki_ingested' => (int) ($kiFeedback['ingested'] ?? 0),
                ]),
            ],
            changed: ['keyword', 'article_meta'],
        );
    }
}
