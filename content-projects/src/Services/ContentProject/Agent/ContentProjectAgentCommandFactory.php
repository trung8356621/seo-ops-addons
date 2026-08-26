<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\DetachClusterFromTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MoveTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ReviewTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\UpdateTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Build Application Command từ capability + validated input.
 */
final class ContentProjectAgentCommandFactory
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function build(string $capability, array $input, int $resolvedSiteId): ContentProjectCommand
    {
        if ($capability === 'content_project.rerun_items') {
            $capability = 'content_project.rerun';
        }

        return match ($capability) {
            'content_project.create' => $this->buildCreate($input, $resolvedSiteId),
            'content_project.update' => new UpdateContentProjectCommand(
                $this->projectRef($input),
                is_array($input['attributes'] ?? null)
                    ? $input['attributes']
                    : array_filter([
                        'name' => $input['project_name'] ?? $input['name'] ?? null,
                        'description' => $input['description'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            ),
            'content_project.add_items' => new AddContentProjectItemsCommand(
                $this->projectRef($input),
                is_array($input['items'] ?? null) ? $input['items'] : [],
            ),
            'content_project.fill_seo_audit_suggestions' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand(
                $this->projectRef($input),
                is_array($input['filters'] ?? null) ? $input['filters'] : [],
                $input['limit'] ?? 20,
            ),
            'content_project.generate_new_content_suggestions' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand(
                $this->projectRef($input),
                (int) ($input['quantity'] ?? $input['limit'] ?? 20),
                array_merge(
                    is_array($input['options'] ?? null) ? $input['options'] : [],
                    array_filter([
                        'notes' => $input['notes'] ?? null,
                        'content_type' => $input['content_type'] ?? null,
                        'focus' => $input['focus'] ?? null,
                        'direction' => $input['direction'] ?? null,
                        'post_type' => $input['post_type'] ?? $input['content_type'] ?? null,
                        'taxonomy' => $input['taxonomy'] ?? null,
                        'quantity' => $input['quantity'] ?? $input['limit'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                ),
                (bool) ($input['dry_run'] ?? $input['preview'] ?? false),
            ),
            'content_project.restore_new_content_suggestions' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand(
                $this->projectRef($input),
                is_array($input['fingerprints'] ?? null) ? $input['fingerprints'] : [],
            ),
            'content_project.skip_seo_audit_articles' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand(
                $this->projectRef($input),
                is_array($input['article_ids'] ?? null) ? $input['article_ids'] : [],
            ),
            'content_project.split_draft' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand(
                $this->projectRef($input),
                (string) ($input['selection_mode'] ?? (
                    isset($input['item_ids']) || isset($input['item_refs'])
                        ? 'selected'
                        : (isset($input['all']) && $input['all'] ? 'all' : 'first_n')
                )),
                isset($input['quantity']) ? (int) $input['quantity'] : (isset($input['limit']) ? (int) $input['limit'] : null),
                is_array($input['item_refs'] ?? null)
                    ? $input['item_refs']
                    : (is_array($input['item_ids'] ?? null) ? $input['item_ids'] : []),
                isset($input['target_month']) ? (string) $input['target_month'] : (isset($input['month']) ? (string) $input['month'] : null),
                isset($input['project_name']) ? (string) $input['project_name'] : (isset($input['name']) ? (string) $input['name'] : null),
                (bool) ($input['dry_run'] ?? $input['preview'] ?? false),
            ),
            'content_project.update_item' => new UpdateContentProjectItemCommand(
                $this->itemRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'content_project.generate' => new GenerateProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['mode'] ?? 'full'),
            ),
            'content_project.rerun' => new RerunProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['mode'] ?? 'full'),
                is_array($input['settings'] ?? null) ? $input['settings'] : [],
            ),
            'content_project.resume_failed_step' => new ResumeProjectItemFromFailedStepCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['mode'] ?? 'full'),
            ),
            'content_project.acknowledge_generation_error' => new AcknowledgeProjectItemGenerationErrorCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                isset($input['note']) ? (string) $input['note'] : null,
            ),
            'content_project.rerun_step' => new RerunProjectItemStepCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                ContentProjectRerunFromStep::fromMixed($input['rerun_from_step'] ?? $input['from_step'] ?? null),
                (bool) ($input['include_downstream'] ?? false),
                isset($input['source_article_id']) ? (int) $input['source_article_id'] : null,
                (string) ($input['mode'] ?? 'full'),
            ),
            'content_project.start_review' => new StartReviewCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.approve' => new ApproveProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.schedule' => new ScheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                Carbon::parse((string) ($input['scheduled_at'] ?? now()->addHour()->toIso8601String())),
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.auto_schedule' => new AutoScheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                is_array($input['options'] ?? null) ? $input['options'] : [],
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.unschedule' => new UnscheduleProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.move_schedule' => new MoveProjectItemScheduleCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                Carbon::parse((string) ($input['scheduled_at'] ?? now()->addHour()->toIso8601String())),
            ),
            'content_project.publish_now' => new PublishProjectItemsNowCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.retry_publish' => new RetryProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.skip_publish' => new SkipProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
            ),
            'content_project.cancel_publish' => new CancelProjectItemPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.recover_stuck_publishing' => new RecoverStuckPublishingCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (string) ($input['target'] ?? 'scheduled'),
                isset($input['reschedule_at'])
                    ? Carbon::parse((string) $input['reschedule_at'])
                    : null,
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.send_to_publishing_queue' => new SendToPublishingQueueCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.return_to_content_project' => new ReturnToContentProjectCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                (bool) ($input['dry_run'] ?? false),
            ),
            'content_project.archive' => new ArchiveContentProjectCommand(
                $this->projectRef($input),
                isset($input['note']) ? (string) $input['note'] : null,
                (bool) ($input['confirm_waiting_publish'] ?? false),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
                (bool) ($input['confirm_hidden_stale_runs'] ?? false),
            ),
            'content_project.archive_items' => new ArchiveProjectItemsCommand(
                $this->projectRef($input),
                $this->itemRefs($input),
                isset($input['note']) ? (string) $input['note'] : null,
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.restore' => new RestoreContentProjectCommand(
                $this->projectRef($input),
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'content_project.stop_execution' => new StopProjectExecutionCommand(
                $this->projectRef($input),
                isset($input['execution_ref']) ? (string) $input['execution_ref'] : null,
                isset($input['reason']) ? (string) $input['reason'] : null,
            ),
            'content_project.resume_execution' => new ResumeProjectExecutionCommand(
                $this->projectRef($input),
                isset($input['execution_ref']) ? (string) $input['execution_ref'] : null,
            ),

            // Keyword Intelligence — additive.
            'keyword_intelligence.create_workspace' => new CreateKeywordWorkspaceCommand(
                array_merge(
                    is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
                    ['site_id' => $resolvedSiteId],
                ),
            ),
            'keyword_intelligence.import_keywords' => new ImportKeywordsCommand(
                $this->workspaceRef($input),
                is_array($input['keywords'] ?? null) ? $input['keywords'] : [],
                (bool) ($input['preview'] ?? false),
                (bool) ($input['keep_duplicates'] ?? false),
                (string) ($input['source'] ?? 'manual'),
            ),
            'keyword_intelligence.analyze_workspace' => new AnalyzeKeywordWorkspaceCommand(
                $this->workspaceRef($input),
                isset($input['clustering_strategy']) ? (string) $input['clustering_strategy'] : null,
            ),
            'keyword_intelligence.approve_keywords' => new ApproveKeywordsCommand(
                $this->workspaceRef($input),
                $this->keywordRefs($input),
                (bool) ($input['approve'] ?? true),
            ),
            'keyword_intelligence.approve_clusters' => new ApproveKeywordClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                (bool) ($input['approve'] ?? true),
            ),
            'keyword_intelligence.build_topical_map' => new BuildTopicalMapCommand(
                $this->workspaceRef($input),
                isset($input['max_depth']) ? (int) $input['max_depth'] : null,
                isset($input['mode']) ? (string) $input['mode'] : null,
                (bool) ($input['include_reviewed_clusters'] ?? false),
                is_array($input['approved_cluster_refs'] ?? null) ? $input['approved_cluster_refs'] : null,
                (bool) ($input['preserve_manual_topics'] ?? true),
            ),
            'keyword_intelligence.create_topic' => new CreateTopicCommand(
                $this->workspaceRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
                isset($input['parent_topic_ref']) ? (string) $input['parent_topic_ref'] : null,
            ),
            'keyword_intelligence.update_topic' => new UpdateTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'keyword_intelligence.move_topic' => new MoveTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                isset($input['new_parent_topic_ref']) ? (string) $input['new_parent_topic_ref'] : null,
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'keyword_intelligence.attach_cluster' => new AttachClusterToTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                (string) ($input['cluster_ref'] ?? ''),
                (string) ($input['relationship'] ?? 'primary'),
            ),
            'keyword_intelligence.detach_cluster' => new DetachClusterFromTopicCommand(
                $this->workspaceRef($input),
                (string) ($input['topic_ref'] ?? ''),
                (string) ($input['cluster_ref'] ?? ''),
            ),
            'keyword_intelligence.review_topical_map' => new ReviewTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
            ),
            // Agent NEVER gets allowBlockingOverride — hard-coded false.
            'keyword_intelligence.approve_topical_map' => new ApproveTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                false,
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'keyword_intelligence.save_map_version' => new SaveTopicalMapVersionCommand(
                $this->workspaceRef($input),
                isset($input['mode']) ? (string) $input['mode'] : null,
            ),
            'keyword_intelligence.preview_convert' => new PreviewContentProjectFromClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'keyword_intelligence.preview_content_project' => new PreviewContentProjectFromTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                (string) ($input['policy'] ?? 'new_only'),
                is_array($input['cluster_refs'] ?? null) ? $input['cluster_refs'] : null,
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'keyword_intelligence.convert_to_content_project' => new CreateContentProjectFromKeywordClustersCommand(
                $this->workspaceRef($input),
                $this->clusterRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            // Creates from approved map only — converter rejects draft.
            'keyword_intelligence.create_content_project' => new CreateContentProjectFromTopicalMapCommand(
                $this->workspaceRef($input),
                (string) ($input['map_version_ref'] ?? ''),
                (string) ($input['policy'] ?? 'new_only'),
                is_array($input['cluster_refs'] ?? null) ? $input['cluster_refs'] : null,
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                (bool) ($input['dry_run'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
                isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null,
            ),
            'keyword_intelligence.archive_workspace' => new ArchiveKeywordWorkspaceCommand(
                $this->workspaceRef($input),
            ),

            // SERP Intelligence — additive.
            'serp_intelligence.create_queries' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand(
                $this->workspaceRef($input),
                is_array($input['queries'] ?? null) ? $input['queries'] : [],
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.collect' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand(
                $this->workspaceRef($input),
                $this->serpQueryRefs($input),
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.import_snapshot' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand(
                $this->workspaceRef($input),
                $this->serpQueryRef($input),
                (string) ($input['payload'] ?? ''),
                (string) ($input['format'] ?? 'json'),
                (bool) ($input['preview'] ?? false),
            ),
            'serp_intelligence.analyze_snapshot' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
            ),
            'serp_intelligence.fetch_page_evidence' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
                $this->serpResultRefs($input),
            ),
            'serp_intelligence.validate_cluster' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand(
                $this->workspaceRef($input),
                $this->clusterRef($input),
            ),
            'serp_intelligence.approve_evidence' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
            ),
            'serp_intelligence.apply_intent' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpIntentSuggestionCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['preview'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'serp_intelligence.apply_content_action' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['preview'] ?? false),
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
            ),
            'serp_intelligence.review_gap' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand(
                $this->workspaceRef($input),
                $this->serpGapRef($input),
                (string) ($input['action'] ?? 'review'),
            ),
            'serp_intelligence.add_feature_keywords' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand(
                $this->workspaceRef($input),
                $this->serpSnapshotRef($input),
                $this->serpFeatureRefs($input),
            ),
            'serp_intelligence.preview_cluster_split' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand(
                $this->workspaceRef($input),
                $this->serpEvidenceRef($input),
                (bool) ($input['dry_run'] ?? true),
            ),

            // GSC Intelligence — additive Phase 5.
            'gsc_intelligence.create_property' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand(
                $resolvedSiteId,
                is_array($input['attributes'] ?? null) ? $input['attributes'] : $input,
            ),
            'gsc_intelligence.update_property' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand(
                $this->gscPropertyRef($input),
                is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            ),
            'gsc_intelligence.pause_property' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.resume_property' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.archive_property' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand(
                $this->gscPropertyRef($input),
            ),
            'gsc_intelligence.sync_performance' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
                is_array($input['options'] ?? null) ? $input['options'] : [],
            ),
            'gsc_intelligence.cancel_sync' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand(
                $this->gscPropertyRef($input),
                $this->gscSyncRunRef($input),
            ),
            'gsc_intelligence.import_performance' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand(
                $this->gscPropertyRef($input),
                (string) ($input['payload'] ?? ''),
                (bool) ($input['preview'] ?? false),
            ),
            'gsc_intelligence.repair_date_range' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.map_query' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscQueryCommand(
                $this->gscPropertyRef($input),
                (string) ($input['normalized_query'] ?? ''),
                isset($input['keyword_ref']) ? (string) $input['keyword_ref'] : null,
                isset($input['cluster_ref']) ? (string) $input['cluster_ref'] : null,
                isset($input['topic_ref']) ? (string) $input['topic_ref'] : null,
            ),
            'gsc_intelligence.unmap_query' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand(
                $this->gscPropertyRef($input),
                $this->gscQueryMappingRef($input),
            ),
            'gsc_intelligence.map_page' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscPageCommand(
                $this->gscPropertyRef($input),
                (string) ($input['normalized_page'] ?? ''),
                isset($input['article_ref']) ? (string) $input['article_ref'] : null,
            ),
            'gsc_intelligence.unmap_page' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand(
                $this->gscPropertyRef($input),
                $this->gscPageMappingRef($input),
            ),
            'gsc_intelligence.rebuild_aggregates' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.detect_opportunities' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                isset($input['date_from']) ? (string) $input['date_from'] : null,
                isset($input['date_to']) ? (string) $input['date_to'] : null,
            ),
            'gsc_intelligence.approve_opportunity' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.reject_opportunity' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.ignore_opportunity' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
            ),
            'gsc_intelligence.resolve_opportunity' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRef($input),
                isset($input['resolution_code']) ? (string) $input['resolution_code'] : null,
            ),
            'gsc_intelligence.preview_add_queries' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand(
                $this->gscPropertyRef($input),
                $this->workspaceRef($input),
                $this->gscQueryMappingRefs($input),
            ),
            'gsc_intelligence.add_queries_to_workspace' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand(
                $this->gscPropertyRef($input),
                $this->workspaceRef($input),
                $this->gscQueryMappingRefs($input),
                (bool) ($input['keep_duplicates'] ?? false),
            ),
            'gsc_intelligence.preview_create_content_project' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
            ),
            'gsc_intelligence.create_content_project' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand(
                $this->gscPropertyRef($input),
                $this->gscOpportunityRefs($input),
                is_array($input['project_attributes'] ?? null) ? $input['project_attributes'] : [],
                isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null,
                isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null,
            ),
            'article.index_health.inspect_gsc' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexWithGscCommand(
                (int) ($input['article_id'] ?? 0),
            ),
            'article.index_health.inspect_due_gsc' => new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexesWithGscCommand(
                (int) ($input['site_id'] ?? $resolvedSiteId ?? 0),
                is_array($input['article_ids'] ?? null)
                    ? array_values(array_map('intval', $input['article_ids']))
                    : [],
                (bool) ($input['due_only'] ?? ! isset($input['article_ids'])),
                isset($input['limit']) ? (int) $input['limit'] : null,
            ),
            'site.discover' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteCommand($resolvedSiteId),
            'site.sync' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand(
                $resolvedSiteId,
                (string) ($input['mode'] ?? 'delta'),
                (bool) ($input['force_snapshot'] ?? false),
            ),
            'site.sync_keywords' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteKeywordsCommand($resolvedSiteId),
            'site.sync_links' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteLinksCommand($resolvedSiteId),
            'site.discover_contacts' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteContactsCommand($resolvedSiteId),
            'site.refresh_snapshot' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\RefreshSiteSnapshotCommand($resolvedSiteId),
            'site.resume_sync' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
            ),
            'site.retry_sync_step' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\RetrySiteSyncStepCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
                (string) ($input['step_key'] ?? ''),
            ),
            'site.cancel_sync' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand(
                $resolvedSiteId,
                (int) ($input['run_id'] ?? 0),
            ),
            'site.reconcile' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand(
                $resolvedSiteId,
                (string) ($input['mode'] ?? 'standard'),
            ),
            'site.requeue_inbound_event' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand(
                $resolvedSiteId,
                (int) ($input['event_id'] ?? 0),
            ),
            'site.preview_bootstrap' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand($resolvedSiteId),
            'site.bootstrap' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand(
                $resolvedSiteId,
                (bool) ($input['force'] ?? false),
            ),
            'site.backfill_v2' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\BackfillSiteSyncV2Command(
                $resolvedSiteId,
                (bool) ($input['dry_run'] ?? true),
                is_array($input['only'] ?? null) ? $input['only'] : ['all'],
                (int) ($input['batch'] ?? 200),
                isset($input['resume_id']) ? (int) $input['resume_id'] : null,
                (bool) ($input['force'] ?? false),
            ),
            'site.validate_handshake' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\ValidateSiteSyncHandshakeCommand($resolvedSiteId),
            'site.generate_diagnostic' => new \Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand($resolvedSiteId),
            default => throw new InvalidArgumentException('Unsupported agent capability: '.$capability),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildCreate(array $input, int $resolvedSiteId): CreateContentProjectCommand
    {
        $attributes = is_array($input['attributes'] ?? null) ? $input['attributes'] : [];
        $attributes['site_id'] = $resolvedSiteId;
        unset($attributes['site_ref']);

        $tasksData = is_array($input['tasksData'] ?? null)
            ? $input['tasksData']
            : (is_array($input['tasks_data'] ?? null) ? $input['tasks_data'] : []);

        return new CreateContentProjectCommand($attributes, $tasksData);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function projectRef(array $input): string
    {
        $ref = trim((string) ($input['project_ref'] ?? ''));
        ContentProjectPublicRef::resolveProjectIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function itemRef(array $input): string
    {
        $ref = trim((string) ($input['item_ref'] ?? ''));
        ContentProjectPublicRef::resolveItemIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function itemRefs(array $input): array
    {
        $raw = $input['item_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            ContentProjectPublicRef::resolveItemIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function workspaceRef(array $input): string
    {
        $ref = trim((string) ($input['workspace_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function keywordRefs(array $input): array
    {
        $raw = $input['keyword_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveKeywordIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function clusterRefs(array $input): array
    {
        $raw = $input['cluster_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function clusterRef(array $input): string
    {
        $ref = trim((string) ($input['cluster_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveClusterIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpQueryRef(array $input): string
    {
        $ref = trim((string) ($input['query_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpQueryRefs(array $input): array
    {
        $raw = $input['query_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpSnapshotRef(array $input): string
    {
        $ref = trim((string) ($input['snapshot_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpSnapshotIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpEvidenceRef(array $input): string
    {
        $ref = trim((string) ($input['evidence_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpClusterEvidenceIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function serpGapRef(array $input): string
    {
        $ref = trim((string) ($input['gap_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveSerpContentGapIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpResultRefs(array $input): array
    {
        $raw = $input['result_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpResultIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function serpFeatureRefs(array $input): array
    {
        $raw = $input['feature_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveSerpFeatureIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscPropertyRef(array $input): string
    {
        $ref = trim((string) ($input['property_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscPropertyIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscSyncRunRef(array $input): string
    {
        $ref = trim((string) ($input['sync_run_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscSyncRunIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscQueryMappingRef(array $input): string
    {
        $ref = trim((string) ($input['mapping_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function gscQueryMappingRefs(array $input): array
    {
        $raw = $input['query_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscPageMappingRef(array $input): string
    {
        $ref = trim((string) ($input['mapping_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscPageMappingIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function gscOpportunityRef(array $input): string
    {
        $ref = trim((string) ($input['opportunity_ref'] ?? ''));
        KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($ref);

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function gscOpportunityRefs(array $input): array
    {
        $raw = $input['opportunity_refs'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($ref);
            $refs[] = $ref;
        }

        return $refs;
    }
}
