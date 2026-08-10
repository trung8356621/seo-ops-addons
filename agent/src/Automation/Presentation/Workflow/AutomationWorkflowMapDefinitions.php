<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Presentation\Workflow;

/**
 * Declarative, evidence-backed workflow maps (presentation only).
 * Does not participate in execution. Edges must cite real code/registry sources.
 *
 * @phpstan-type WorkflowNode array{
 *   id: string,
 *   canonical: string,
 *   type: string,
 *   label_key: string,
 *   evidence: string,
 *   run_mode?: string,
 *   optional?: bool,
 *   component_match?: list<string>
 * }
 * @phpstan-type WorkflowEdge array{
 *   from: string,
 *   to: string,
 *   type: string,
 *   evidence: string
 * }
 * @phpstan-type WorkflowDef array{
 *   id: string,
 *   category: string,
 *   name_key: string,
 *   description_key: string,
 *   definition_sources: list<string>,
 *   nodes: list<WorkflowNode>,
 *   edges: list<WorkflowEdge>
 * }
 */
final class AutomationWorkflowMapDefinitions
{
    /**
     * Round-1 high-level workflows only — relations proven in code.
     *
     * @return list<WorkflowDef>
     */
    public static function all(): array
    {
        return [
            self::generateArticle(),
            self::review(),
            self::publishing(),
            self::archiveRestore(),
            self::automationRuntime(),
        ];
    }

    /**
     * @return WorkflowDef
     */
    private static function generateArticle(): array
    {
        return [
            'id' => 'wf:generate-article',
            'category' => 'content_creation',
            'name_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.name',
            'description_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.description',
            'definition_sources' => [
                'ContentProjectCapabilityRegistry (content_project.generate / rerun)',
                'GenerateProjectItemsHandler / RerunProjectItemsHandler',
                'SeoProjectWorkflowRunService + ContentProjectRunEngine',
                'ArticlePipelineDefinition::steps()',
                'BusinessHookEmitter::runStarted / runCompleted / runFailed',
            ],
            'nodes' => [
                [
                    'id' => 'ga.trigger',
                    'canonical' => 'content_project.generate',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.trigger',
                    'evidence' => 'ContentProjectCapabilityRegistry → GenerateProjectItemsCommand / GenerateProjectItemsHandler',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.generate', 'content_project.generate'],
                ],
                [
                    'id' => 'ga.rerun',
                    'canonical' => 'content_project.rerun',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.rerun',
                    'evidence' => 'ContentProjectCapabilityRegistry → RerunProjectItemsHandler (manual alternate entry)',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.rerun', 'content_project.rerun'],
                ],
                [
                    'id' => 'ga.run_started',
                    'canonical' => 'content_project.run.started',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.run_started',
                    'evidence' => 'SeoProjectWorkflowRunService::startRun → BusinessHookEmitter::runStarted',
                    'run_mode' => 'event',
                    'component_match' => ['event:content_project.run.started', 'content_project.run.started'],
                ],
                [
                    'id' => 'ga.pipeline',
                    'canonical' => 'pipeline:article',
                    'type' => 'pipeline',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.pipeline',
                    'evidence' => 'ArticlePipelineDefinition (outline → content → optional image → optional seo_audit)',
                    'run_mode' => 'pipeline',
                    'component_match' => ['pipeline:article', 'pipeline.article'],
                ],
                [
                    'id' => 'ga.outline',
                    'canonical' => 'article.outline.generate',
                    'type' => 'pipeline_step',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.outline',
                    'evidence' => 'ArticlePipelineDefinition::steps()[0]',
                    'run_mode' => 'pipeline',
                ],
                [
                    'id' => 'ga.content',
                    'canonical' => 'article.content.generate',
                    'type' => 'pipeline_step',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.content',
                    'evidence' => 'ArticlePipelineDefinition::steps()[1]',
                    'run_mode' => 'pipeline',
                ],
                [
                    'id' => 'ga.image',
                    'canonical' => 'article.image.generate',
                    'type' => 'pipeline_step',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.image',
                    'evidence' => 'ArticlePipelineDefinition::steps()[2] required=false',
                    'run_mode' => 'pipeline',
                    'optional' => true,
                ],
                [
                    'id' => 'ga.seo_audit',
                    'canonical' => 'article.seo_audit.run',
                    'type' => 'pipeline_step',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.seo_audit',
                    'evidence' => 'ArticlePipelineDefinition::steps()[3] required=false',
                    'run_mode' => 'pipeline',
                    'optional' => true,
                ],
                [
                    'id' => 'ga.run_completed',
                    'canonical' => 'content_project.run.completed',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.run_completed',
                    'evidence' => 'BusinessHookEmitter::runCompleted from workflow complete paths',
                    'run_mode' => 'event',
                    'component_match' => ['event:content_project.run.completed', 'content_project.run.completed'],
                ],
                [
                    'id' => 'ga.run_failed',
                    'canonical' => 'content_project.run.failed',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.run_failed',
                    'evidence' => 'BusinessHookEmitter::runFailed',
                    'run_mode' => 'event',
                    'component_match' => ['event:content_project.run.failed', 'content_project.run.failed'],
                ],
                [
                    'id' => 'ga.stop',
                    'canonical' => 'content_project.stop_execution',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.stop',
                    'evidence' => 'ContentProjectCapabilityRegistry → stop_execution (manual interrupt)',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.stop_execution', 'content_project.stop_execution'],
                ],
                [
                    'id' => 'ga.resume',
                    'canonical' => 'content_project.resume_execution',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.generate_article.nodes.resume',
                    'evidence' => 'ContentProjectCapabilityRegistry → resume_execution (manual)',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.resume_execution', 'content_project.resume_execution'],
                ],
            ],
            'edges' => [
                ['from' => 'ga.trigger', 'to' => 'ga.run_started', 'type' => 'next', 'evidence' => 'GenerateProjectItemsHandler → startRun'],
                ['from' => 'ga.rerun', 'to' => 'ga.run_started', 'type' => 'manual', 'evidence' => 'RerunProjectItemsHandler → startRun'],
                ['from' => 'ga.run_started', 'to' => 'ga.pipeline', 'type' => 'queued', 'evidence' => 'GenerateProjectItemsHandler → ContentProjectRunEngine::start → RunContentProjectArticleJob'],
                ['from' => 'ga.pipeline', 'to' => 'ga.outline', 'type' => 'next', 'evidence' => 'ArticlePipelineDefinition order'],
                ['from' => 'ga.outline', 'to' => 'ga.content', 'type' => 'next', 'evidence' => 'ArticlePipelineDefinition order'],
                ['from' => 'ga.content', 'to' => 'ga.image', 'type' => 'optional', 'evidence' => 'ArticlePipelineDefinition required=false'],
                ['from' => 'ga.content', 'to' => 'ga.seo_audit', 'type' => 'optional', 'evidence' => 'ArticlePipelineDefinition required=false'],
                ['from' => 'ga.content', 'to' => 'ga.run_completed', 'type' => 'success', 'evidence' => 'workflow complete → runCompleted'],
                ['from' => 'ga.pipeline', 'to' => 'ga.run_failed', 'type' => 'failure', 'evidence' => 'runFailed emission paths'],
                ['from' => 'ga.stop', 'to' => 'ga.run_failed', 'type' => 'manual', 'evidence' => 'stop_execution capability'],
                ['from' => 'ga.resume', 'to' => 'ga.pipeline', 'type' => 'manual', 'evidence' => 'resume_execution capability'],
            ],
        ];
    }

    /**
     * @return WorkflowDef
     */
    private static function review(): array
    {
        return [
            'id' => 'wf:review',
            'category' => 'review',
            'name_key' => 'seo-content-ai::filament.automation.flows.workflows.review.name',
            'description_key' => 'seo-content-ai::filament.automation.flows.workflows.review.description',
            'definition_sources' => [
                'ContentProjectCapabilityRegistry (start_review, approve)',
                'StartReviewHandler / ApproveProjectItemsHandler',
            ],
            'nodes' => [
                [
                    'id' => 'rv.start',
                    'canonical' => 'content_project.start_review',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.review.nodes.start',
                    'evidence' => 'StartReviewHandler — tasks → STATUS_REVIEWING',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.start_review', 'content_project.start_review'],
                ],
                [
                    'id' => 'rv.approve',
                    'canonical' => 'content_project.approve',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.review.nodes.approve',
                    'evidence' => 'ApproveProjectItemsHandler — SeoArticle.review_status=approved + project STATUS_APPROVED',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.approve', 'content_project.approve'],
                ],
                [
                    'id' => 'rv.article_approved',
                    'canonical' => 'article.approved',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.review.nodes.article_approved',
                    'evidence' => 'BusinessEventName::ArticleApproved registered; CP approve does NOT emit it (ApproveArticleAction only). Shown as related registered event.',
                    'run_mode' => 'event',
                    'optional' => true,
                    'component_match' => ['event:article.approved', 'article.approved'],
                ],
            ],
            'edges' => [
                ['from' => 'rv.start', 'to' => 'rv.approve', 'type' => 'manual', 'evidence' => 'Human review then ApproveProjectItemsHandler'],
                ['from' => 'rv.approve', 'to' => 'rv.article_approved', 'type' => 'optional', 'evidence' => 'No bridge from CP approve to article.approved — related registry only'],
            ],
        ];
    }

    /**
     * @return WorkflowDef
     */
    private static function publishing(): array
    {
        return [
            'id' => 'wf:publishing',
            'category' => 'publishing',
            'name_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.name',
            'description_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.description',
            'definition_sources' => [
                'ContentProjectCapabilityRegistry (schedule / publish_now / retry / skip / cancel)',
                'ContentProjectPublishingQueueService + ContentProjectPublishingQueueRunner',
                'ProcessScheduledProjectItemPublishHandler',
                'AutomationDefaultRulesSeeder (article.publish_requested → wordpress.article.sync)',
                'SyncArticleToWordPressHookAction',
            ],
            'nodes' => [
                [
                    'id' => 'pb.schedule',
                    'canonical' => 'content_project.schedule',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.schedule',
                    'evidence' => 'ScheduleProjectItemsHandler → ContentProjectPublishingQueueService::schedule',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.schedule', 'content_project.schedule'],
                ],
                [
                    'id' => 'pb.auto_schedule',
                    'canonical' => 'content_project.auto_schedule',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.auto_schedule',
                    'evidence' => 'ContentProjectCapabilityRegistry → auto_schedule',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.auto_schedule', 'content_project.auto_schedule'],
                ],
                [
                    'id' => 'pb.publish_now',
                    'canonical' => 'content_project.publish_now',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.publish_now',
                    'evidence' => 'PublishProjectItemsNowHandler → schedule(now) + queueRunner->dispatchDue()',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.publish_now', 'content_project.publish_now'],
                ],
                [
                    'id' => 'pb.unschedule',
                    'canonical' => 'content_project.unschedule',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.unschedule',
                    'evidence' => 'ContentProjectCapabilityRegistry → unschedule',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.unschedule', 'content_project.unschedule'],
                ],
                [
                    'id' => 'pb.process',
                    'canonical' => 'ProcessScheduledProjectItemPublishCommand',
                    'type' => 'command',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.process',
                    'evidence' => 'ContentProjectPublishingQueueRunner / ScheduledArticlePublishRunner → ProcessScheduledProjectItemPublishHandler',
                    'run_mode' => 'queued',
                ],
                [
                    'id' => 'pb.publish_requested',
                    'canonical' => 'article.publish_requested',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.publish_requested',
                    'evidence' => 'ProcessScheduledProjectItemPublishHandler emits ArticlePublishRequested (actorUserId null path)',
                    'run_mode' => 'event',
                    'component_match' => ['event:article.publish_requested', 'article.publish_requested', 'rule:'],
                ],
                [
                    'id' => 'pb.wp_action',
                    'canonical' => 'wordpress.article.sync',
                    'type' => 'action',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.wp_action',
                    'evidence' => 'Default rule dispatch-publish-request → SyncArticleToWordPressHookAction (queued)',
                    'run_mode' => 'queued',
                ],
                [
                    'id' => 'pb.sync_started',
                    'canonical' => 'wordpress.sync_started',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.sync_started',
                    'evidence' => 'SyncArticleToWordPressHookAction::emitOutcomeSafely',
                    'run_mode' => 'event',
                    'component_match' => ['event:wordpress.sync_started', 'wordpress.sync_started'],
                ],
                [
                    'id' => 'pb.synced',
                    'canonical' => 'wordpress.synced',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.synced',
                    'evidence' => 'SyncArticleToWordPressHookAction success path',
                    'run_mode' => 'event',
                    'component_match' => ['event:wordpress.synced', 'wordpress.synced'],
                ],
                [
                    'id' => 'pb.sync_failed',
                    'canonical' => 'wordpress.sync_failed',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.sync_failed',
                    'evidence' => 'SyncArticleToWordPressHookAction failure path',
                    'run_mode' => 'event',
                    'component_match' => ['event:wordpress.sync_failed', 'wordpress.sync_failed'],
                ],
                [
                    'id' => 'pb.sync_requested',
                    'canonical' => 'wordpress.sync_requested',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.sync_requested',
                    'evidence' => 'Registered in WordPressAutomationModuleProvider — no production emitter found (related, optional)',
                    'run_mode' => 'event',
                    'optional' => true,
                    'component_match' => ['event:wordpress.sync_requested', 'wordpress.sync_requested'],
                ],
                [
                    'id' => 'pb.retry',
                    'canonical' => 'content_project.retry_publish',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.retry',
                    'evidence' => 'RetryProjectItemPublishingHandler → Retrying + dispatchDue',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.retry_publish', 'content_project.retry_publish'],
                ],
                [
                    'id' => 'pb.skip',
                    'canonical' => 'content_project.skip_publish',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.skip',
                    'evidence' => 'ContentProjectCapabilityRegistry → skip_publish',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.skip_publish', 'content_project.skip_publish'],
                ],
                [
                    'id' => 'pb.cancel',
                    'canonical' => 'content_project.cancel_publish',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.publishing.nodes.cancel',
                    'evidence' => 'ContentProjectCapabilityRegistry → cancel_publish',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.cancel_publish', 'content_project.cancel_publish'],
                ],
            ],
            'edges' => [
                ['from' => 'pb.schedule', 'to' => 'pb.process', 'type' => 'queued', 'evidence' => 'ScheduledArticlePublishRunner / PublishingQueueRunner::dispatchDue'],
                ['from' => 'pb.auto_schedule', 'to' => 'pb.process', 'type' => 'queued', 'evidence' => 'auto_schedule → queue'],
                ['from' => 'pb.publish_now', 'to' => 'pb.process', 'type' => 'queued', 'evidence' => 'publishNow + immediate dispatchDue'],
                ['from' => 'pb.process', 'to' => 'pb.publish_requested', 'type' => 'next', 'evidence' => 'ProcessScheduledProjectItemPublishHandler emit'],
                ['from' => 'pb.publish_requested', 'to' => 'pb.wp_action', 'type' => 'queued', 'evidence' => 'rule article.publish_requested → wordpress.article.sync'],
                ['from' => 'pb.wp_action', 'to' => 'pb.sync_started', 'type' => 'next', 'evidence' => 'SyncArticleToWordPressHookAction'],
                ['from' => 'pb.sync_started', 'to' => 'pb.synced', 'type' => 'success', 'evidence' => 'emitOutcomeSafely success'],
                ['from' => 'pb.sync_started', 'to' => 'pb.sync_failed', 'type' => 'failure', 'evidence' => 'emitOutcomeSafely failure'],
                ['from' => 'pb.sync_failed', 'to' => 'pb.retry', 'type' => 'retry', 'evidence' => 'retry_publish capability'],
                ['from' => 'pb.retry', 'to' => 'pb.process', 'type' => 'queued', 'evidence' => 'RetryProjectItemPublishingHandler → dispatchDue'],
                ['from' => 'pb.skip', 'to' => 'pb.process', 'type' => 'manual', 'evidence' => 'skip_publish alternate exit'],
                ['from' => 'pb.cancel', 'to' => 'pb.process', 'type' => 'manual', 'evidence' => 'cancel_publish alternate exit'],
                ['from' => 'pb.unschedule', 'to' => 'pb.schedule', 'type' => 'manual', 'evidence' => 'unschedule before due'],
            ],
        ];
    }

    /**
     * @return WorkflowDef
     */
    private static function archiveRestore(): array
    {
        return [
            'id' => 'wf:archive-restore',
            'category' => 'archive',
            'name_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.name',
            'description_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.description',
            'definition_sources' => [
                'ContentProjectCapabilityRegistry (archive, restore)',
                'ArchiveContentProjectHandler / RestoreContentProjectHandler',
                'ArchiveContentProjectService',
            ],
            'nodes' => [
                [
                    'id' => 'ar.archive',
                    'canonical' => 'content_project.archive',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.nodes.archive',
                    'evidence' => 'ArchiveContentProjectHandler → ArchiveContentProjectService::archive',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.archive', 'content_project.archive'],
                ],
                [
                    'id' => 'ar.task_archived',
                    'canonical' => 'content_project.task.archived',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.nodes.task_archived',
                    'evidence' => 'BusinessEventName::ContentProjectTaskArchived registered (related lifecycle)',
                    'run_mode' => 'event',
                    'optional' => true,
                    'component_match' => ['event:content_project.task.archived', 'content_project.task.archived'],
                ],
                [
                    'id' => 'ar.restore',
                    'canonical' => 'content_project.restore',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.nodes.restore',
                    'evidence' => 'RestoreContentProjectHandler → archiveService->restore (no biz restore event found)',
                    'run_mode' => 'command_bus',
                    'component_match' => ['capability:content_project.restore', 'content_project.restore'],
                ],
                [
                    'id' => 'ar.create',
                    'canonical' => 'content_project.create',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.nodes.create',
                    'evidence' => 'ContentProjectCapabilityRegistry → create (workspace lifecycle related)',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.create', 'content_project.create'],
                ],
                [
                    'id' => 'ar.sync_items',
                    'canonical' => 'content_project.sync_items',
                    'type' => 'capability',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.archive_restore.nodes.sync_items',
                    'evidence' => 'ContentProjectCapabilityRegistry → sync_items (workspace item sync)',
                    'run_mode' => 'command_bus',
                    'optional' => true,
                    'component_match' => ['capability:content_project.sync_items', 'content_project.sync_items'],
                ],
            ],
            'edges' => [
                ['from' => 'ar.create', 'to' => 'ar.sync_items', 'type' => 'optional', 'evidence' => 'workspace setup capabilities'],
                ['from' => 'ar.archive', 'to' => 'ar.task_archived', 'type' => 'optional', 'evidence' => 'related registered event'],
                ['from' => 'ar.archive', 'to' => 'ar.restore', 'type' => 'manual', 'evidence' => 'RestoreContentProjectHandler after archive'],
            ],
        ];
    }

    /**
     * @return WorkflowDef
     */
    private static function automationRuntime(): array
    {
        return [
            'id' => 'wf:automation-runtime',
            'category' => 'automation_runtime',
            'name_key' => 'seo-content-ai::filament.automation.flows.workflows.automation_runtime.name',
            'description_key' => 'seo-content-ai::filament.automation.flows.workflows.automation_runtime.description',
            'definition_sources' => [
                'CoreAutomationModuleProvider',
                'AutomationSchedulerService',
                'ManualAutomationDispatcher',
                'Core AutomationModuleProvider runtime dispatch (event → matched rule job)',
            ],
            'nodes' => [
                [
                    'id' => 'rt.schedule',
                    'canonical' => 'automation.schedule.triggered',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.automation_runtime.nodes.schedule',
                    'evidence' => 'AutomationSchedulerService emits automation.schedule.triggered',
                    'run_mode' => 'event',
                    'component_match' => ['event:automation.schedule.triggered', 'automation.schedule.triggered'],
                ],
                [
                    'id' => 'rt.manual',
                    'canonical' => 'automation.manual_action_requested',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.automation_runtime.nodes.manual',
                    'evidence' => 'ManualAutomationDispatcher emits automation.manual_action_requested',
                    'run_mode' => 'event',
                    'component_match' => ['event:automation.manual_action_requested', 'automation.manual_action_requested'],
                ],
                [
                    'id' => 'rt.notification',
                    'canonical' => 'notification.requested',
                    'type' => 'event',
                    'label_key' => 'seo-content-ai::filament.automation.flows.workflows.automation_runtime.nodes.notification',
                    'evidence' => 'CoreAutomationModuleProvider + seeded notification rule',
                    'run_mode' => 'event',
                    'optional' => true,
                    'component_match' => ['event:notification.requested', 'notification.requested'],
                ],
            ],
            'edges' => [
                ['from' => 'rt.schedule', 'to' => 'rt.notification', 'type' => 'optional', 'evidence' => 'rules may request notification after schedule trigger'],
                ['from' => 'rt.manual', 'to' => 'rt.notification', 'type' => 'optional', 'evidence' => 'manual dispatch may chain notification.requested'],
            ],
        ];
    }
}
