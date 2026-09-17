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
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Carbon\Carbon;

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
                (int) ($input['site_id'] ?? $resolvedSiteId),
                is_array($input['filters'] ?? null) ? $input['filters'] : [],
                $input['limit'] ?? 20,
            ),
            'content_project.generate_new_content_suggestions' => new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand(
                $this->projectRef($input),
                (int) ($input['site_id'] ?? $resolvedSiteId),
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
                (bool) ($input['dry_run'] ?? $input['preview'] ?? false),
                is_array($input['assignee_ids'] ?? null)
                    ? $input['assignee_ids']
                    : (is_array($input['writer_ids'] ?? null) ? $input['writer_ids'] : []),
                isset($input['target_month'])
                    ? (string) $input['target_month']
                    : (isset($input['month']) ? (string) $input['month'] : null),
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

            // SERP Intelligence — workspace_ref passed through without KI workspace resolve.
            'serp_intelligence.create_queries' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand(
                trim((string) ($input['workspace_ref'] ?? '')),
                is_array($input['queries'] ?? null) ? $input['queries'] : [],
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.collect' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand(
                trim((string) ($input['workspace_ref'] ?? '')),
                $this->serpQueryRefs($input),
                isset($input['provider_key']) ? (string) $input['provider_key'] : null,
            ),
            'serp_intelligence.import_snapshot' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand(
                trim((string) ($input['workspace_ref'] ?? '')),
                $this->serpQueryRef($input),
                (string) ($input['payload'] ?? ''),
                (string) ($input['format'] ?? 'json'),
                (bool) ($input['preview'] ?? false),
            ),
            'serp_intelligence.analyze_snapshot' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand(
                trim((string) ($input['workspace_ref'] ?? '')),
                $this->serpSnapshotRef($input),
            ),
            'serp_intelligence.fetch_page_evidence' => new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand(
                trim((string) ($input['workspace_ref'] ?? '')),
                $this->serpSnapshotRef($input),
                $this->serpResultRefs($input),
            ),
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
