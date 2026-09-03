<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeSelectedKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AttachClusterToTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CancelKeywordAnalysisCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CancelTopicalMapBuildCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\DeleteEmptyTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\DetachClusterFromTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ExcludeKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MergeKeywordClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MoveClusterPrimaryTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MoveKeywordsToClusterCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\MoveTopicCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromClustersCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ReviewTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SaveTopicalMapVersionCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SetTopicRelationshipCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\SplitKeywordClusterCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\UpdateKeywordClassificationCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\UpdateTopicCommand;

/**
 * Agent capability registry — MCP/API Agent chỉ là adapter ngoài registry này.
 */
final class ContentProjectCapabilityRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->cap(
                'content_project.create',
                'Create a Content Project inside the explicitly supplied site context. This action does not create, discover, or switch sites.',
                CreateContentProjectCommand::class,
                'content_project.create',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'attributes' => [
                        'type' => 'object',
                        'required' => true,
                        'description' => 'Bounded project fields only (name, month, member_ref, ...). Site comes from site_ref context — not an open-ended payload and not a site switcher.',
                    ],
                    'tasksData' => ['type' => 'array', 'required' => false],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'description' => 'Create a Content Project inside the explicitly supplied site context. This action does not create, discover, or switch sites. Global workflow capability — not a per-site feature flag.',
                    'category' => 'content_project',
                    'capability_kind' => CapabilityKind::SYSTEM_ACTION,
                    'action_domain' => 'content_project',
                    'required_context' => ['site_ref'],
                    'side_effect_level' => 'write',
                    'input_summary' => [
                        'site_ref (required context)',
                        'attributes.name',
                        'attributes.month',
                        'attributes.member_ref',
                        'tasksData (optional)',
                    ],
                    'output_summary' => ['project_ref', 'operation_id'],
                ],
            ),
            $this->cap(
                'content_project.update',
                'Update Content Project metadata for an explicitly supplied project_ref within the current site context. Does not sync or switch sites.',
                UpdateContentProjectCommand::class,
                'content_project.update',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => [
                        'type' => 'object',
                        'required' => true,
                        'description' => 'Bounded project metadata fields only. Not a free-form payload.',
                    ],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'description' => 'Update Content Project metadata for an explicitly supplied project_ref within the current site context. Does not sync or switch sites.',
                    'category' => 'content_project',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                    'input_summary' => ['site_ref (context)', 'project_ref', 'attributes (bounded)'],
                ],
            ),
            $this->cap(
                'content_project.sync_items',
                'Sync full tasks_data payload for a project',
                SyncContentProjectItemsCommand::class,
                'content_project.sync_items',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'tasks_data' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                ],
            ),
            $this->cap(
                'content_project.add_items',
                'Add items to a Content Project',
                AddContentProjectItemsCommand::class,
                'content_project.add_items',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'items' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.fill_seo_audit_suggestions',
                'Fill a Draft Content Project with existing-content SEO Audit suggestions using quantity and optional filter snapshot. Does not generate articles or publish.',
                FillSeoAuditSuggestionsCommand::class,
                'content_project.fill_seo_audit_suggestions',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'limit' => ['type' => 'integer', 'required' => false],
                    'filters' => ['type' => 'object', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Fill Draft with SEO Audit candidates. Agent-safe — no Filament dependency.',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.generate_new_content_suggestions',
                'Generate planning suggestions for new articles with AI and add create items to a Draft Content Project. Does not generate articles, upsert Keyword Intelligence, or publish.',
                GenerateNewContentSuggestionsCommand::class,
                'content_project.generate_new_content_suggestions',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'site_id' => ['type' => 'integer', 'required' => false],
                    'quantity' => ['type' => 'integer', 'required' => false],
                    'content_type' => ['type' => 'string', 'required' => false],
                    'notes' => ['type' => 'string', 'required' => false],
                    'options' => ['type' => 'object', 'required' => false],
                    'focus' => ['type' => 'string', 'required' => false],
                    'direction' => ['type' => 'string', 'required' => false],
                    'post_type' => ['type' => 'string', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Generate planning suggestions and add them to a Draft project. Not article generation.',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.restore_new_content_suggestions',
                'Restore project-scoped rejected AI new-content suggestion fingerprints so they may be eligible again.',
                RestoreNewContentSuggestionsCommand::class,
                'content_project.restore_new_content_suggestions',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'fingerprints' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.skip_seo_audit_articles',
                'Globally skip articles from SEO Audit suggestions (article_meta.skip_seo_audit). Not project rejection.',
                SkipSeoAuditArticlesCommand::class,
                'content_project.skip_seo_audit_articles',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'article_ids' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'description' => 'Sets global skip_seo_audit on articles. Distinct from Draft remove/reject.',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.split_draft',
                'Move reviewed Draft items into current-month execution Content Project(s): fair-distribute across selected writers, then chunk at 30 items/project. Does not generate articles or publish.',
                SplitDraftContentProjectCommand::class,
                'content_project.split_draft',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'selection_mode' => ['type' => 'string', 'enum' => ['first_n', 'selected', 'all'], 'required' => false],
                    'quantity' => ['type' => 'integer', 'required' => false],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'assignee_ids' => ['type' => 'array', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Fair-split reviewed Draft items across selected writers for the current month; chunk each writer at 30 items/project. Requires real writer ids.',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.update_item',
                'Update a single Content Project item',
                UpdateContentProjectItemCommand::class,
                'content_project.update_item',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'item_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'content_project.generate',
                'Run generation for the explicitly selected Content Project. Requires a project identifier. This action may invoke AI generation and must never select a project implicitly. Does not sync sites or create projects.',
                GenerateProjectItemsCommand::class,
                'content_project.generate',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'test'], 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Run generation for the explicitly selected Content Project. Requires a project identifier. This action may invoke AI generation and must never select a project implicitly. Does not sync sites or create projects.',
                    'required_context' => ['site_ref', 'project_ref'],
                    'side_effect_level' => 'write',
                    'input_summary' => ['site_ref (context)', 'project_ref (required)', 'item_refs (optional)'],
                ],
            ),
            $this->cap(
                'content_project.rerun',
                'Rerun AI workflow for selected items',
                RerunProjectItemsCommand::class,
                'content_project.rerun',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'test'], 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.resume_failed_step',
                'Resume item from first retryable failed step (reuse upstream artifacts)',
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand::class,
                'content_project.resume_failed_step',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'mode' => ['type' => 'string', 'enum' => ['full', 'test'], 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Canonical Failed-row Retry: resume from failed step, reuse valid upstream artifacts. Not a full graph rerun.',
                    'required_context' => ['site_ref', 'project_ref', 'item_refs'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.acknowledge_generation_error',
                'Clear stale generation Failed overlay without regenerating (keep content)',
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand::class,
                'content_project.acknowledge_generation_error',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'note' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::Published->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Soft-clear latest run-item error when content already OK. Does not call AI.',
                    'required_context' => ['site_ref', 'project_ref', 'item_refs'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.start_review',
                'Move items into review',
                StartReviewCommand::class,
                'content_project.start_review',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.approve',
                'Approve items for scheduling/publishing',
                ApproveProjectItemsCommand::class,
                'content_project.approve',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.send_to_publishing_queue',
                'Send completed items to Publishing Queue (Unscheduled)',
                SendToPublishingQueueCommand::class,
                'content_project.send_to_publishing_queue',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.return_to_content_project',
                'Return unpublished items from Publishing Queue to Content Project',
                ReturnToContentProjectCommand::class,
                'content_project.return_to_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.schedule',
                'Schedule items for Publishing Queue',
                ScheduleProjectItemsCommand::class,
                'content_project.schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'scheduled_at' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Review->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.auto_schedule',
                'Auto-schedule / Quick Mode many items in Publishing Queue',
                AutoScheduleProjectItemsCommand::class,
                'content_project.auto_schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => false],
                    'options' => [
                        'type' => 'object',
                        'required' => false,
                        'properties' => [
                            'mode' => [
                                'type' => 'string',
                                'enum' => ['monthly_even', 'interval', 'per_day', 'random_windows', 'project_month', 'quick', 'in_day'],
                                'default' => 'monthly_even',
                            ],
                            'min_spacing_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440, 'default' => 5],
                            'allow_reschedule' => ['type' => 'boolean', 'default' => false],
                            'day_start' => ['type' => 'string', 'default' => '09:00'],
                            'day_end' => ['type' => 'string', 'default' => '17:00'],
                            'start_at' => ['type' => 'string', 'format' => 'date-time'],
                            'interval_minutes' => ['type' => 'integer', 'minimum' => 5],
                            'per_day' => ['type' => 'integer', 'minimum' => 1],
                            'days' => ['type' => 'integer', 'minimum' => 1],
                            'windows' => ['type' => 'array'],
                        ],
                    ],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.unschedule',
                'Remove scheduled publish time from items',
                UnscheduleProjectItemsCommand::class,
                'content_project.unschedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.move_schedule',
                'Move scheduled publish time for items',
                MoveProjectItemScheduleCommand::class,
                'content_project.move_schedule',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'scheduled_at' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.publish_now',
                'Queue immediate publish for items',
                PublishProjectItemsNowCommand::class,
                'content_project.publish_now',
                riskLevel: 'publish',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Failed->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.retry_publish',
                'Retry failed publish for an item',
                RetryProjectItemPublishingCommand::class,
                'content_project.retry_publish',
                riskLevel: 'publish',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.skip_publish',
                'Skip publish attempt for an item',
                SkipProjectItemPublishingCommand::class,
                'content_project.skip_publish',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Failed->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.cancel_publish',
                'Cancel queued publish for an item',
                CancelProjectItemPublishingCommand::class,
                'content_project.cancel_publish',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.recover_stuck_publishing',
                'Recover stuck Publishing items (no WordPress, no normal cancel transition)',
                RecoverStuckPublishingCommand::class,
                'content_project.recover_stuck_publishing',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'target' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                ],
                confirmation: false,
            ),
            $this->cap(
                'content_project.send_to_publishing_queue',
                'Hand off content-complete items from Content Project to the Publishing Queue module (no WordPress call, no auto schedule).',
                SendToPublishingQueueCommand::class,
                'content_project.send_to_publishing_queue',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Module handoff — content must already be complete. Does not schedule or publish, and never calls WordPress.',
                    'required_context' => ['site_ref', 'project_ref', 'item_refs'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.return_to_content_project',
                'Return items from the Publishing Queue back into the Content Project working set (blocked once Published).',
                ReturnToContentProjectCommand::class,
                'content_project.return_to_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Approved->value,
                ],
                confirmation: false,
                presentation: [
                    'description' => 'Module handoff back to Content Project. Blocked once Published. Never calls WordPress.',
                    'required_context' => ['site_ref', 'project_ref', 'item_refs'],
                    'side_effect_level' => 'write',
                ],
            ),
            $this->cap(
                'content_project.archive',
                'Archive project and destroy AI Workspace',
                ArchiveContentProjectCommand::class,
                'content_project.archive',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'note' => ['type' => 'string', 'required' => false],
                    'confirm_waiting_publish' => ['type' => 'boolean', 'required' => false],
                    'confirm_hidden_stale_runs' => ['type' => 'boolean', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'content_project.archive_items',
                'Archive selected project items (keeps WordPress posts)',
                ArchiveProjectItemsCommand::class,
                'content_project.archive_items',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'item_refs' => ['type' => 'array', 'required' => true],
                    'note' => ['type' => 'string', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [
                    ContentProjectLifecyclePhase::Draft->value,
                    ContentProjectLifecyclePhase::Review->value,
                    ContentProjectLifecyclePhase::Approved->value,
                    ContentProjectLifecyclePhase::WaitingPublish->value,
                    ContentProjectLifecyclePhase::Published->value,
                    ContentProjectLifecyclePhase::Failed->value,
                ],
                confirmation: true,
            ),
            $this->cap(
                'content_project.restore',
                'Restore archived project business flags (new workspace on generate)',
                RestoreContentProjectCommand::class,
                'content_project.restore',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: [ContentProjectLifecyclePhase::Archived->value],
                confirmation: true,
            ),
            $this->cap(
                'content_project.stop_execution',
                'Stop active project execution',
                StopProjectExecutionCommand::class,
                'content_project.stop_execution',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'execution_ref' => ['type' => 'string', 'required' => false],
                    'reason' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
                presentation: [
                    'agent_exposed' => true,
                    'mcp_exposed' => false,
                ],
            ),
            $this->cap(
                'content_project.resume_execution',
                'Resume paused/stopped project execution',
                ResumeProjectExecutionCommand::class,
                'content_project.resume_execution',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'project_ref' => ['type' => 'string', 'required' => true],
                    'execution_ref' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'agent_exposed' => true,
                    'mcp_exposed' => false,
                ],
            ),

            // Keyword Intelligence — additive, không phase-gate theo ContentProjectLifecyclePhase.
            $this->cap(
                'keyword_intelligence.create_workspace',
                'Create a Keyword Intelligence workspace',
                CreateKeywordWorkspaceCommand::class,
                'keyword_intelligence.create_workspace',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.import_keywords',
                'Import keywords into a workspace',
                ImportKeywordsCommand::class,
                'keyword_intelligence.import_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keywords' => ['type' => 'array', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'keep_duplicates' => ['type' => 'boolean', 'required' => false],
                    'source' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.analyze_workspace',
                'Run analysis pipeline for a workspace',
                AnalyzeKeywordWorkspaceCommand::class,
                'keyword_intelligence.analyze_workspace',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'clustering_strategy' => ['type' => 'string', 'required' => false],
                    'options' => ['type' => 'object', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.analyze_keywords',
                'Analyze selected keywords in a workspace',
                AnalyzeSelectedKeywordsCommand::class,
                'keyword_intelligence.analyze_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.cancel_analysis',
                'Cancel a running keyword analysis operation',
                CancelKeywordAnalysisCommand::class,
                'keyword_intelligence.cancel_analysis',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'operation_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.approve_keywords',
                'Approve or reject keywords',
                ApproveKeywordsCommand::class,
                'keyword_intelligence.approve_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'approve' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.exclude_keywords',
                'Exclude or restore excluded keywords',
                ExcludeKeywordsCommand::class,
                'keyword_intelligence.exclude_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'exclude' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.update_keyword',
                'Manually update keyword intent/funnel/business value',
                UpdateKeywordClassificationCommand::class,
                'keyword_intelligence.update_keyword',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'search_intent' => ['type' => 'string', 'required' => false],
                    'funnel_stage' => ['type' => 'string', 'required' => false],
                    'business_value' => ['type' => 'number', 'required' => false],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.approve_clusters',
                'Approve or reject keyword clusters',
                ApproveKeywordClustersCommand::class,
                'keyword_intelligence.approve_clusters',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'approve' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.merge_clusters',
                'Merge keyword clusters (preview/confirm when approved)',
                MergeKeywordClustersCommand::class,
                'keyword_intelligence.merge_clusters',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'source_cluster_refs' => ['type' => 'array', 'required' => true],
                    'target_cluster_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.split_cluster',
                'Split a keyword cluster into groups',
                SplitKeywordClusterCommand::class,
                'keyword_intelligence.split_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'source_cluster_ref' => ['type' => 'string', 'required' => true],
                    'groups' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: true,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.move_keywords',
                'Move keywords into a destination cluster',
                MoveKeywordsToClusterCommand::class,
                'keyword_intelligence.move_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'keyword_refs' => ['type' => 'array', 'required' => true],
                    'destination_cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
                presentation: [
                    'internal' => true,
                    'agent_exposed' => false,
                    'mcp_exposed' => false,
                    'visibility' => 'internal',
                ],
            ),
            $this->cap(
                'keyword_intelligence.build_topical_map',
                'Build the topical map from approved clusters',
                BuildTopicalMapCommand::class,
                'keyword_intelligence.build_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'max_depth' => ['type' => 'integer', 'required' => false],
                    'mode' => ['type' => 'string', 'required' => false],
                    'include_reviewed_clusters' => ['type' => 'boolean', 'required' => false],
                    'approved_cluster_refs' => ['type' => 'array', 'required' => false],
                    'preserve_manual_topics' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.create_topic',
                'Create a topic in the topical map',
                CreateTopicCommand::class,
                'keyword_intelligence.create_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                    'parent_topic_ref' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.update_topic',
                'Update a topic',
                UpdateTopicCommand::class,
                'keyword_intelligence.update_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'attributes' => ['type' => 'object', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.move_topic',
                'Move a topic under a new parent',
                MoveTopicCommand::class,
                'keyword_intelligence.move_topic',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'new_parent_topic_ref' => ['type' => 'string', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.attach_cluster',
                'Attach a cluster to a topic',
                AttachClusterToTopicCommand::class,
                'keyword_intelligence.attach_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                    'relationship' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.detach_cluster',
                'Detach a cluster from a topic',
                DetachClusterFromTopicCommand::class,
                'keyword_intelligence.detach_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'topic_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.review_topical_map',
                'Mark a topical map version as reviewed',
                ReviewTopicalMapCommand::class,
                'keyword_intelligence.review_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.approve_topical_map',
                'Approve a topical map version (gates blocking conflicts)',
                ApproveTopicalMapCommand::class,
                'keyword_intelligence.approve_topical_map',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.save_map_version',
                'Save a snapshot of the current topical map as a new draft version',
                SaveTopicalMapVersionCommand::class,
                'keyword_intelligence.save_map_version',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'mode' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.preview_convert',
                'Preview converting clusters into a Content Project',
                PreviewContentProjectFromClustersCommand::class,
                'keyword_intelligence.preview_convert',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.preview_content_project',
                'Preview converting an approved topical map into a Content Project',
                PreviewContentProjectFromTopicalMapCommand::class,
                'keyword_intelligence.preview_content_project',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'policy' => ['type' => 'string', 'required' => false],
                    'cluster_refs' => ['type' => 'array', 'required' => false],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'keyword_intelligence.convert_to_content_project',
                'Convert approved clusters into a Content Project',
                CreateContentProjectFromKeywordClustersCommand::class,
                'keyword_intelligence.convert_to_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_refs' => ['type' => 'array', 'required' => true],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.create_content_project',
                'Create a Content Project from an approved topical map',
                CreateContentProjectFromTopicalMapCommand::class,
                'keyword_intelligence.create_content_project',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'map_version_ref' => ['type' => 'string', 'required' => true],
                    'policy' => ['type' => 'string', 'required' => false],
                    'cluster_refs' => ['type' => 'array', 'required' => false],
                    'project_attributes' => ['type' => 'object', 'required' => false],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                    'idempotency_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'keyword_intelligence.archive_workspace',
                'Archive a Keyword Intelligence workspace (read-only afterwards)',
                ArchiveKeywordWorkspaceCommand::class,
                'keyword_intelligence.archive_workspace',
                riskLevel: 'destructive',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: true,
            ),

            // SERP Intelligence — additive.
            $this->cap(
                'serp_intelligence.create_queries',
                'Create SERP queries in a keyword workspace',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand::class,
                'serp_intelligence.create_queries',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'queries' => ['type' => 'array', 'required' => true],
                    'provider_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.collect',
                'Collect SERP snapshots for queries',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand::class,
                'serp_intelligence.collect',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'query_refs' => ['type' => 'array', 'required' => true],
                    'provider_key' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.import_snapshot',
                'Import a SERP snapshot from manual payload',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand::class,
                'serp_intelligence.import_snapshot',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'query_ref' => ['type' => 'string', 'required' => true],
                    'payload' => ['type' => 'string', 'required' => true],
                    'format' => ['type' => 'string', 'required' => false],
                    'preview' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.analyze_snapshot',
                'Analyze a completed SERP snapshot',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand::class,
                'serp_intelligence.analyze_snapshot',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.fetch_page_evidence',
                'Fetch page evidence for SERP results (allowlisted URLs only)',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand::class,
                'serp_intelligence.fetch_page_evidence',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                    'result_refs' => ['type' => 'array', 'required' => false],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.validate_cluster',
                'Validate a keyword cluster using SERP overlap',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ValidateClusterWithSerpCommand::class,
                'serp_intelligence.validate_cluster',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'cluster_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.approve_evidence',
                'Approve SERP cluster evidence',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApproveSerpClusterEvidenceCommand::class,
                'serp_intelligence.approve_evidence',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.apply_intent',
                'Apply SERP intent suggestion to cluster (confirmation when manual override)',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpIntentSuggestionCommand::class,
                'serp_intelligence.apply_intent',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'serp_intelligence.apply_content_action',
                'Apply SERP content action suggestion to cluster',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ApplySerpContentActionSuggestionCommand::class,
                'serp_intelligence.apply_content_action',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'preview' => ['type' => 'boolean', 'required' => false],
                    'confirmation_token' => ['type' => 'string', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),
            $this->cap(
                'serp_intelligence.review_gap',
                'Review a SERP content gap',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand::class,
                'serp_intelligence.review_gap',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'gap_ref' => ['type' => 'string', 'required' => true],
                    'action' => ['type' => 'string', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.add_feature_keywords',
                'Promote SERP feature keywords into workspace keyword candidates',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand::class,
                'serp_intelligence.add_feature_keywords',
                riskLevel: 'write',
                idempotencySupport: true,
                dryRunSupport: false,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'snapshot_ref' => ['type' => 'string', 'required' => true],
                    'feature_refs' => ['type' => 'array', 'required' => true],
                ],
                phases: null,
                confirmation: false,
            ),
            $this->cap(
                'serp_intelligence.preview_cluster_split',
                'Preview splitting a cluster from SERP evidence',
                \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\PreviewSplitClusterFromSerpEvidenceCommand::class,
                'serp_intelligence.preview_cluster_split',
                riskLevel: 'write',
                idempotencySupport: false,
                dryRunSupport: true,
                inputSchema: [
                    'workspace_ref' => ['type' => 'string', 'required' => true],
                    'evidence_ref' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'required' => false],
                ],
                phases: null,
                confirmation: true,
            ),

            // GSC Intelligence — additive Phase 5.
            $this->cap('gsc_intelligence.create_property', 'Create a GSC property for site', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand::class, 'gsc_intelligence.create_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['attributes' => ['type' => 'object', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.update_property', 'Update GSC property metadata', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand::class, 'gsc_intelligence.update_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'attributes' => ['type' => 'object', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.pause_property', 'Pause GSC property sync', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand::class, 'gsc_intelligence.pause_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.resume_property', 'Resume GSC property sync', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand::class, 'gsc_intelligence.resume_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.archive_property', 'Archive GSC property', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand::class, 'gsc_intelligence.archive_property', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.sync_performance', 'Sync GSC performance data', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand::class, 'gsc_intelligence.sync_performance', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false], 'provider_key' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.cancel_sync', 'Cancel in-flight GSC sync run', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand::class, 'gsc_intelligence.cancel_sync', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'sync_run_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.import_performance', 'Import GSC performance CSV', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand::class, 'gsc_intelligence.import_performance', riskLevel: 'write', idempotencySupport: true, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'payload' => ['type' => 'string', 'required' => true], 'preview' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.repair_date_range', 'Compute GSC repair date ranges', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand::class, 'gsc_intelligence.repair_date_range', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.map_query', 'Manually map GSC query to keyword workspace entity', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscQueryCommand::class, 'gsc_intelligence.map_query', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'normalized_query' => ['type' => 'string', 'required' => true], 'keyword_ref' => ['type' => 'string', 'required' => false], 'cluster_ref' => ['type' => 'string', 'required' => false], 'topic_ref' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.unmap_query', 'Unmap GSC query mapping', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand::class, 'gsc_intelligence.unmap_query', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'mapping_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.map_page', 'Manually map GSC page to article', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscPageCommand::class, 'gsc_intelligence.map_page', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'normalized_page' => ['type' => 'string', 'required' => true], 'article_ref' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.unmap_page', 'Unmap GSC page mapping', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand::class, 'gsc_intelligence.unmap_page', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'mapping_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.rebuild_aggregates', 'Rebuild GSC performance aggregates', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand::class, 'gsc_intelligence.rebuild_aggregates', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.detect_opportunities', 'Detect GSC content opportunities', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand::class, 'gsc_intelligence.detect_opportunities', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'date_from' => ['type' => 'string', 'required' => false], 'date_to' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.approve_opportunity', 'Approve GSC opportunity', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand::class, 'gsc_intelligence.approve_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.reject_opportunity', 'Reject GSC opportunity', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand::class, 'gsc_intelligence.reject_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.ignore_opportunity', 'Ignore GSC opportunity', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand::class, 'gsc_intelligence.ignore_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.resolve_opportunity', 'Resolve GSC opportunity', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand::class, 'gsc_intelligence.resolve_opportunity', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_ref' => ['type' => 'string', 'required' => true], 'resolution_code' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.preview_add_queries', 'Preview adding GSC queries to keyword workspace', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand::class, 'gsc_intelligence.preview_add_queries', riskLevel: 'write', idempotencySupport: false, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'workspace_ref' => ['type' => 'string', 'required' => true], 'query_refs' => ['type' => 'array', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.add_queries_to_workspace', 'Add GSC queries to keyword workspace', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand::class, 'gsc_intelligence.add_queries_to_workspace', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'workspace_ref' => ['type' => 'string', 'required' => true], 'query_refs' => ['type' => 'array', 'required' => false], 'keep_duplicates' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.preview_create_content_project', 'Preview content project from GSC opportunities', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand::class, 'gsc_intelligence.preview_create_content_project', riskLevel: 'write', idempotencySupport: false, dryRunSupport: true, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_refs' => ['type' => 'array', 'required' => true], 'project_attributes' => ['type' => 'object', 'required' => false]], phases: null, confirmation: false),
            $this->cap('gsc_intelligence.create_content_project', 'Create content project from approved GSC opportunities', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand::class, 'gsc_intelligence.create_content_project', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['property_ref' => ['type' => 'string', 'required' => true], 'opportunity_refs' => ['type' => 'array', 'required' => true], 'project_attributes' => ['type' => 'object', 'required' => false], 'confirmation_token' => ['type' => 'string', 'required' => false]], phases: null, confirmation: true),
            $this->cap('article.index_health.inspect_gsc', 'Inspect one article URL with GSC URL Inspection → Index Health', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexWithGscCommand::class, 'article.index_health.inspect_gsc', riskLevel: 'write', idempotencySupport: false, dryRunSupport: false, inputSchema: ['article_id' => ['type' => 'integer', 'required' => true]], phases: null, confirmation: false),
            $this->cap('article.index_health.inspect_due_gsc', 'Queue bounded due Index Health batch via GSC URL Inspection', \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexesWithGscCommand::class, 'article.index_health.inspect_due_gsc', riskLevel: 'write', idempotencySupport: false, dryRunSupport: false, inputSchema: ['site_id' => ['type' => 'integer', 'required' => true], 'article_ids' => ['type' => 'array', 'required' => false], 'limit' => ['type' => 'integer', 'required' => false], 'due_only' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false),

            // Site Sync V2
            $this->cap('site.discover', 'Discover SEO provider and site feature availability for the explicitly supplied site_ref. Returns site_feature flags — not MCP system actions.', \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteCommand::class, 'site.discover', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: false, presentation: [
                'label' => 'site.discover',
                'description' => 'Discover SEO provider and site feature availability for the explicitly supplied site_ref. Output keys (seo_score, focus_keyword, …) are site_feature flags — not callable MCP tools.',
                'category' => 'site_sync',
                'capability_kind' => CapabilityKind::SYSTEM_ACTION,
                'action_domain' => 'site_sync',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'none',
                'read_only' => true,
                'scopes' => ['site:read'],
                'input_summary' => ['site_ref (required context)'],
                'output_summary' => ['site profile', 'SEO provider', 'plugin version', 'site_feature capabilities', 'fallback status'],
                'examples' => ['Phân tích website này đang dùng plugin SEO gì và có những khả năng nào.'],
            ]),
            $this->cap('site.sync', 'Synchronize the explicitly supplied WordPress site. Do not use this capability to create or update Content Projects.', \Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand::class, 'site.sync', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['mode' => ['type' => 'string', 'required' => false], 'force_snapshot' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: false, presentation: [
                'label' => 'site.sync',
                'description' => 'Synchronize the explicitly supplied WordPress site. Requires site_ref. Do not use this to create/update Content Projects. Never infer site from UI route or capability catalog.',
                'category' => 'site_sync',
                'capability_kind' => CapabilityKind::SYSTEM_ACTION,
                'action_domain' => 'site_sync',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'write',
                'read_only' => false,
                'scopes' => ['site:sync'],
                'confirmation_modes' => ['force_full'],
                'confirmation_note' => 'Có khi dùng `force_full`',
                'input_summary' => ['site_ref (required context)', 'mode: incremental | bootstrap | force_full'],
                'output_summary' => ['operation_id', 'run_id', 'trạng thái', 'tóm tắt kết quả'],
                'examples' => ['Đồng bộ website với site_ref đã cung cấp.'],
            ]),
            $this->cap('site.sync_keywords', 'Sync provider keywords for the explicitly supplied site_ref (+ workspace fallback). Not a Content Project action.', \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteKeywordsCommand::class, 'site.sync_keywords', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: false, presentation: [
                'label' => 'site.sync_keywords',
                'description' => 'Sync provider keywords for the explicitly supplied site_ref (+ workspace fallback). Not a Content Project action.',
                'category' => 'site_sync',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'write',
                'scopes' => ['site:sync'],
                'input_summary' => ['site_ref (required context)', 'scope (optional)'],
                'output_summary' => ['operation_id', 'keyword sync summary'],
                'examples' => ['Đồng bộ keyword website với site_ref đã cung cấp.'],
            ]),
            $this->cap('site.sync_links', 'Sync URL catalog and validate changed links for the explicitly supplied site_ref.', \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteLinksCommand::class, 'site.sync_links', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: false, presentation: [
                'label' => 'site.sync_links',
                'description' => 'Sync URL catalog and validate changed links for the explicitly supplied site_ref.',
                'category' => 'site_sync',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'write',
                'scopes' => ['site:sync'],
                'input_summary' => ['site_ref (required context)'],
                'output_summary' => ['operation_id', 'link catalog summary'],
                'examples' => ['Đồng bộ link website với site_ref đã cung cấp.'],
            ]),
            $this->cap('site.discover_contacts', 'Suggest contacts/profile for the explicitly supplied site_ref (does not overwrite manual contacts).', \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteContactsCommand::class, 'site.discover_contacts', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: false, presentation: [
                'label' => 'site.discover_contacts',
                'description' => 'Suggest contacts/profile for the explicitly supplied site_ref (does not overwrite manual contacts).',
                'category' => 'site_sync',
                'required_context' => ['site_ref'],
                'side_effect_level' => 'none',
                'read_only' => true,
                'scopes' => ['site:read'],
                'input_summary' => ['site_ref (required context)'],
                'output_summary' => ['suggested contacts', 'profile hints'],
                'examples' => ['Tìm contact / profile gợi ý của website với site_ref đã cung cấp.'],
            ]),
            $this->cap('site.refresh_snapshot', 'Force full snapshot sync', \Omnichannel\Addons\SiteSync\Services\Application\Commands\RefreshSiteSnapshotCommand::class, 'site.refresh_snapshot', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: true, presentation: [
                'label' => 'site.refresh_snapshot',
                'description' => 'Làm mới snapshot toàn site (force full).',
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['site_id'],
                'output_summary' => ['operation_id', 'run_id', 'trạng thái'],
                'examples' => ['Refresh snapshot website này.'],
            ]),
            $this->cap('site.resume_sync', 'Resume a site sync run', \Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand::class, 'site.resume_sync', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['run_id' => ['type' => 'integer', 'required' => true]], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'input_summary' => ['run_id'],
                'output_summary' => ['operation_id', 'run status'],
            ]),
            $this->cap('site.retry_sync_step', 'Retry a failed site sync step', \Omnichannel\Addons\SiteSync\Services\Application\Commands\RetrySiteSyncStepCommand::class, 'site.retry_sync_step', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['run_id' => ['type' => 'integer', 'required' => true], 'step_key' => ['type' => 'string', 'required' => true]], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'input_summary' => ['run_id', 'step_key'],
                'output_summary' => ['operation_id', 'step status'],
            ]),
            $this->cap('site.cancel_sync', 'Cancel an in-flight site sync run', \Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand::class, 'site.cancel_sync', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['run_id' => ['type' => 'integer', 'required' => true]], phases: null, confirmation: true, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['run_id'],
                'output_summary' => ['operation_id', 'cancelled status'],
            ]),
            $this->cap('site.reconcile', 'Reconcile site sync drift', \Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand::class, 'site.reconcile', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['mode' => ['type' => 'string', 'required' => false]], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'input_summary' => ['mode (optional)'],
                'output_summary' => ['reconcile summary'],
            ]),
            $this->cap('site.requeue_inbound_event', 'Requeue inbound delta event', \Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand::class, 'site.requeue_inbound_event', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['event_id' => ['type' => 'integer', 'required' => true]], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'visibility' => 'internal',
                'scopes' => ['site:sync'],
                'input_summary' => ['event_id'],
                'output_summary' => ['requeue status'],
            ]),
            $this->cap('site.preview_bootstrap', 'Preview first-time Site Sync bootstrap', \Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand::class, 'site.preview_bootstrap', riskLevel: 'read', idempotencySupport: true, dryRunSupport: true, inputSchema: [], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'read_only' => true,
                'scopes' => ['site:read'],
                'input_summary' => ['site_id'],
                'output_summary' => ['bootstrap preview'],
            ]),
            $this->cap('site.bootstrap', 'First-time Site Sync bootstrap', \Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand::class, 'site.bootstrap', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: ['force' => ['type' => 'boolean', 'required' => false]], phases: null, confirmation: true, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['force (optional)'],
                'output_summary' => ['operation_id', 'bootstrap status'],
            ]),
            $this->cap('site.backfill_v2', 'Backfill legacy data into Site Sync V2', \Omnichannel\Addons\SiteSync\Services\Application\Commands\BackfillSiteSyncV2Command::class, 'site.backfill_v2', riskLevel: 'write', idempotencySupport: true, dryRunSupport: true, inputSchema: ['dry_run' => ['type' => 'boolean', 'required' => false], 'only' => ['type' => 'array', 'required' => false]], phases: null, confirmation: true, presentation: [
                'category' => 'site_sync',
                'visibility' => 'internal',
                'scopes' => ['site:sync'],
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['dry_run', 'only'],
                'output_summary' => ['backfill summary'],
            ]),
            $this->cap('site.validate_handshake', 'Validate Site Sync callback handshake', \Omnichannel\Addons\SiteSync\Services\Application\Commands\ValidateSiteSyncHandshakeCommand::class, 'site.validate_handshake', riskLevel: 'write', idempotencySupport: true, dryRunSupport: false, inputSchema: [], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'scopes' => ['site:sync'],
                'input_summary' => ['site_id'],
                'output_summary' => ['handshake status'],
            ]),
            $this->cap('site.generate_diagnostic', 'Readonly Site Sync diagnostic report', \Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand::class, 'site.generate_diagnostic', riskLevel: 'read', idempotencySupport: true, dryRunSupport: true, inputSchema: [], phases: null, confirmation: false, presentation: [
                'category' => 'site_sync',
                'read_only' => true,
                'scopes' => ['site:read'],
                'input_summary' => ['site_id'],
                'output_summary' => ['diagnostic report'],
            ]),
        ];
    }

    public function get(string $name): ?array
    {
        if ($name === 'content_project.rerun_items') {
            $name = 'content_project.rerun';
        }

        foreach ($this->all() as $cap) {
            if (($cap['name'] ?? '') === $name) {
                return $cap;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jsonSchema(string $name): ?array
    {
        $cap = $this->get($name);
        if ($cap === null) {
            return null;
        }

        return self::buildJsonSchema($cap);
    }

    /**
     * Pure schema builder — shared with capabilities coming from the
     * canonical registry (core + extensions), which follow the same
     * `input_schema` shape but are not owned by this registry.
     *
     * @param  array<string, mixed>  $cap
     * @return array<string, mixed>
     */
    public static function buildJsonSchema(array $cap): array
    {
        $inputSchema = is_array($cap['input_schema'] ?? null) ? $cap['input_schema'] : [];
        $properties = [];
        $required = [];

        foreach ($inputSchema as $field => $def) {
            if (! is_array($def)) {
                continue;
            }

            $type = (string) ($def['type'] ?? 'string');
            $prop = ['type' => $type];

            if ($type === 'array') {
                $prop['items'] = ['type' => 'string'];
            }

            if (isset($def['enum']) && is_array($def['enum'])) {
                $prop['enum'] = array_values($def['enum']);
            }

            if (isset($def['format']) && is_string($def['format'])) {
                $prop['format'] = $def['format'];
            }

            if (isset($def['description']) && is_string($def['description']) && $def['description'] !== '') {
                $prop['description'] = $def['description'];
            }

            $properties[$field] = $prop;

            if (($def['required'] ?? false) === true) {
                $required[] = $field;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Capability names never callable via MCP even when agent-exposed —
     * background/queue-only actions or actions with a dedicated internal
     * trigger surface (cron, workflow engine, ...).
     *
     * @var list<string>
     */
    private const MCP_EXCLUDED_NAMES = [
        'content_project.sync_items',
        'content_project.stop_execution',
        'content_project.resume_execution',
        'content_project.process_scheduled_publish',
        'content_project.rerun_step',
    ];

    /**
     * Whole-namespace write exclusions from MCP (still agent-exposed).
     *
     * @var list<string>
     */
    private const MCP_EXCLUDED_PREFIXES = [
        'serp_intelligence.',
        'gsc_intelligence.',
    ];

    /**
     * Agent write surface — freeze rule: caps without a {@see ContentProjectAgentCommandFactory}
     * match arm must set presentation agent_exposed=false (and usually internal=true).
     */
    public function isAgentWriteExposed(string $name): bool
    {
        $cap = $this->get($name);
        if ($cap === null) {
            return false;
        }

        if ((bool) ($cap['internal'] ?? false)) {
            return false;
        }

        return (bool) ($cap['agent_exposed'] ?? true);
    }

    /**
     * MCP is a stricter subset of the agent-exposed write surface — see
     * class docblock in {@see \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog}.
     */
    public function isMcpWriteExposed(string $name): bool
    {
        if (! $this->isAgentWriteExposed($name)) {
            return false;
        }

        if (in_array($name, self::MCP_EXCLUDED_NAMES, true)) {
            return false;
        }

        foreach (self::MCP_EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        $cap = $this->get($name);

        return ($cap['mcp_exposed'] ?? null) !== false;
    }

    /**
     * @param  list<string>|null  $phases
     * @param  array<string, mixed>  $inputSchema
     * @param  array{
     *     label?: string,
     *     description?: string,
     *     category?: string,
     *     capability_kind?: string,
     *     action_domain?: string,
     *     required_context?: list<string>,
     *     side_effect_level?: string,
     *     read_only?: bool,
     *     scopes?: list<string>,
     *     confirmation_modes?: list<string>,
     *     confirmation_note?: string|null,
     *     input_summary?: list<string>,
     *     output_summary?: list<string>,
     *     examples?: list<string>,
     *     visibility?: string,
     *     internal?: bool,
     *     enabled?: bool,
     *     unhealthy?: bool,
     *     agent_exposed?: bool,
     *     mcp_exposed?: bool|null
     * }|null  $presentation
     * @return array<string, mixed>
     */
    private function cap(
        string $name,
        string $description,
        string $handlerCommand,
        string $permission,
        string $riskLevel,
        bool $idempotencySupport,
        bool $dryRunSupport,
        array $inputSchema,
        ?array $phases,
        bool $confirmation,
        ?array $presentation = null,
    ): array {
        $meta = is_array($presentation) ? $presentation : [];
        $visibility = (string) ($meta['visibility'] ?? 'public');
        $internal = (bool) ($meta['internal'] ?? ($visibility === 'internal'));
        $readOnly = (bool) ($meta['read_only'] ?? ($riskLevel === 'read'));
        $category = (string) ($meta['category'] ?? self::inferPresentationCategory($name));
        $inputSummary = array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            (array) ($meta['input_summary'] ?? array_keys($inputSchema)),
        ));
        $confirmationModes = array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            (array) ($meta['confirmation_modes'] ?? ($confirmation ? ['confirm'] : [])),
        ));
        $requiredContext = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) ($meta['required_context'] ?? self::inferRequiredContext($name, $inputSchema)),
        ), static fn (string $v): bool => $v !== ''));
        $sideEffectLevel = (string) ($meta['side_effect_level'] ?? match (true) {
            $readOnly => 'none',
            $confirmation => 'destructive',
            default => 'write',
        });

        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
            'risk_level' => $riskLevel,
            'idempotency_support' => $idempotencySupport,
            'dry_run_support' => $dryRunSupport,
            'required_permission' => $permission,
            'allowed_lifecycle_phases' => $phases,
            'handler' => $handlerCommand,
            'confirmation_requirement' => $confirmation,
            'label' => (string) ($meta['label'] ?? $name),
            'presentation_description' => (string) ($meta['description'] ?? $description),
            'category' => $category,
            'capability_kind' => (string) ($meta['capability_kind'] ?? CapabilityKind::SYSTEM_ACTION),
            'action_domain' => (string) ($meta['action_domain'] ?? $category),
            'required_context' => $requiredContext,
            'side_effect_level' => $sideEffectLevel,
            'read_only' => $readOnly,
            'scopes' => array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                (array) ($meta['scopes'] ?? [$permission]),
            )),
            'confirmation_modes' => $confirmationModes,
            'confirmation_note' => array_key_exists('confirmation_note', $meta)
                ? (is_string($meta['confirmation_note']) ? $meta['confirmation_note'] : null)
                : ($confirmationModes !== [] ? 'Có' : 'Không'),
            'input_summary' => $inputSummary,
            'output_summary' => array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                (array) ($meta['output_summary'] ?? []),
            )),
            'examples' => array_values(array_map(
                static fn (mixed $v): string => (string) $v,
                (array) ($meta['examples'] ?? []),
            )),
            'visibility' => $internal ? 'internal' : $visibility,
            'internal' => $internal,
            'enabled' => (bool) ($meta['enabled'] ?? true),
            'unhealthy' => (bool) ($meta['unhealthy'] ?? false),
            'agent_exposed' => (bool) ($meta['agent_exposed'] ?? true),
            'mcp_exposed' => array_key_exists('mcp_exposed', $meta) ? $meta['mcp_exposed'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     * @return list<string>
     */
    private static function inferRequiredContext(string $name, array $inputSchema): array
    {
        $ctx = ['site_ref'];

        foreach (['project_ref', 'workspace_ref', 'property_ref', 'item_ref'] as $field) {
            $def = $inputSchema[$field] ?? null;
            if (is_array($def) && ($def['required'] ?? false) === true) {
                $ctx[] = $field;
            }
        }

        if (str_starts_with($name, 'site.') && ! in_array('site_ref', $ctx, true)) {
            $ctx[] = 'site_ref';
        }

        return array_values(array_unique($ctx));
    }

    private static function inferPresentationCategory(string $name): string
    {
        if (str_starts_with($name, 'site.')) {
            return 'site_sync';
        }
        if (str_starts_with($name, 'content_project.')) {
            return 'content_project';
        }
        if (str_starts_with($name, 'keyword_intelligence.') || str_starts_with($name, 'keyword.')) {
            return 'keyword_intelligence';
        }
        if (str_starts_with($name, 'serp_intelligence.') || str_starts_with($name, 'serp.')) {
            return 'serp_intelligence';
        }
        if (str_starts_with($name, 'gsc_intelligence.') || str_starts_with($name, 'gsc.')) {
            return 'gsc_intelligence';
        }

        $dot = strpos($name, '.');

        return $dot === false ? 'general' : substr($name, 0, $dot);
    }
}
