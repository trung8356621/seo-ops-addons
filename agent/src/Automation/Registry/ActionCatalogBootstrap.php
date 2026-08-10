<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Registry;

use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;

/**
 * Phase 2: đăng ký definition catalog (metadata).
 * Business handlers gắn ở Phase 3 — trừ foundation ping.
 */
final class ActionCatalogBootstrap
{
    public function register(ActionRegistry $registry): void
    {
        foreach ($this->definitions() as $definition) {
            $registry->registerDefinition($definition);
        }
    }

    /**
     * @return list<ActionDefinition>
     */
    public function definitions(): array
    {
        return [
            new ActionDefinition(
                key: 'article.create',
                name: 'Create article (local)',
                description: 'Create SeoArticle local-only.',
                module: 'article',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'site_id' => ['type' => 'integer', 'required' => true],
                    'origin_type' => ['type' => 'string', 'required' => false],
                    'origin_id' => ['type' => 'integer', 'required' => false],
                    'post_type' => ['type' => 'string', 'required' => false],
                ],
                outputSchema: [
                    'article_id' => ['type' => 'integer'],
                    'deduplicated' => ['type' => 'boolean'],
                ],
                idempotent: true,
                supportsDryRun: true,
                emittedEvents: ['article.created'],
            ),
            new ActionDefinition(
                key: 'article.content.update',
                name: 'Update article content (local)',
                description: 'Update local article body/title. Must not call WordPress.',
                module: 'article',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                    'content' => ['type' => 'string', 'required' => true],
                    'title' => ['type' => 'string', 'required' => false],
                    'expected_updated_at' => ['type' => 'string', 'required' => false],
                    'expected_content_hash' => ['type' => 'string', 'required' => false],
                ],
                outputSchema: [
                    'article_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string'],
                ],
                idempotent: true,
                lockScope: 'article',
                supportsDryRun: true,
                emittedEvents: ['article.content_updated'],
            ),
            new ActionDefinition(
                key: 'article.seo_meta.update',
                name: 'Update article SEO meta (local)',
                description: 'Update local SEO meta fields.',
                module: 'article',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
                idempotent: true,
                lockScope: 'article',
                supportsDryRun: true,
                emittedEvents: ['article.seo_meta_updated'],
            ),
            new ActionDefinition(
                key: 'article.approve',
                name: 'Approve article (linked project)',
                description: 'Content manager marks staff editing complete → approves linked SeoProject.',
                module: 'article',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
                idempotent: true,
                lockScope: 'article',
                emittedEvents: ['article.approved'],
            ),
            new ActionDefinition(
                key: 'article.review.request',
                name: 'Request article review',
                description: 'BLOCKER Phase 3: no dedicated request-review service. approveLinkedProject approves project — must not map here.',
                module: 'article',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::InternalOnly,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
                emittedEvents: ['article.review_requested'],
            ),
            new ActionDefinition(
                key: 'project.task.create',
                name: 'Create project task',
                description: 'Create SeoProjectTask.',
                module: 'project',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'project_id' => ['type' => 'integer', 'required' => true],
                ],
                emittedEvents: ['project.task_created'],
            ),
            new ActionDefinition(
                key: 'seo.project_task.create_from_issue',
                name: 'Create project task from SEO issue',
                description: 'Assign audit article into content project task.',
                module: 'seo',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'project_id' => ['type' => 'integer', 'required' => true],
                    'article_id' => ['type' => 'integer', 'required' => true],
                    'keyword' => ['type' => 'string', 'required' => false],
                    'title' => ['type' => 'string', 'required' => false],
                ],
                emittedEvents: ['project.task_created'],
            ),
            new ActionDefinition(
                key: 'project.task.attach_article',
                name: 'Attach article to task',
                description: 'Link article_id onto project task.',
                module: 'project',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'task_id' => ['type' => 'integer', 'required' => true],
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
            ),
            new ActionDefinition(
                key: 'project.task.mark_completed',
                name: 'Mark project task completed',
                description: 'Mark SeoProjectTask completed.',
                module: 'project',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'task_id' => ['type' => 'integer', 'required' => true],
                ],
                emittedEvents: ['project.task_completed'],
            ),
            new ActionDefinition(
                key: 'seo.audit.run',
                name: 'Run SEO audit scan',
                description: 'Scan/filter articles by SEO rules (read-heavy).',
                module: 'seo',
                sideEffect: ActionSideEffect::Read,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'site_id' => ['type' => 'integer', 'required' => true],
                ],
                emittedEvents: ['seo.audit_completed'],
            ),
            new ActionDefinition(
                key: 'keyword.assign_to_project',
                name: 'Assign keyword to project',
                description: 'Assign keyword into content project.',
                module: 'keyword',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'project_id' => ['type' => 'integer', 'required' => true],
                ],
                emittedEvents: ['keyword.assigned_to_project'],
            ),
            new ActionDefinition(
                key: 'keyword.vocabulary.save',
                name: 'Save keyword vocabulary',
                description: 'Persist vocabulary research results.',
                module: 'keyword',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                emittedEvents: ['keyword.vocabulary_saved'],
            ),
            new ActionDefinition(
                key: 'keyword.topic_cluster.sync',
                name: 'Sync topic cluster',
                description: 'Sync topic cluster keywords for article/site.',
                module: 'keyword',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Medium,
                selectability: ActionSelectability::Selectable,
                emittedEvents: ['keyword.topic_cluster_synced'],
            ),
            new ActionDefinition(
                key: 'keyword.domain_link_list.sync',
                name: 'Sync keyword domain link list',
                description: 'Upsert/remove keyword in domain link list. Idempotent.',
                module: 'keyword',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::Selectable,
                idempotent: true,
                emittedEvents: ['keyword.domain_link_list_synced'],
            ),
            new ActionDefinition(
                key: 'prompt_result.attach',
                name: 'Attach PromptResult to domain target',
                description: 'Idempotent link PromptResult → article|project_task|project. No WP.',
                module: 'prompt_result',
                sideEffect: ActionSideEffect::InternalWrite,
                riskLevel: ActionRiskLevel::Low,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'prompt_result_id' => ['type' => 'integer', 'required' => true],
                    'target_type' => ['type' => 'string', 'required' => true],
                    'target_id' => ['type' => 'integer', 'required' => true],
                    'site_id' => ['type' => 'integer', 'required' => true],
                    'relation' => ['type' => 'string', 'required' => false],
                    'purpose' => ['type' => 'string', 'required' => false],
                ],
                outputSchema: [
                    'attached' => ['type' => 'boolean'],
                    'deduplicated' => ['type' => 'boolean'],
                    'prompt_result_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                    'target_id' => ['type' => 'integer'],
                ],
                idempotent: true,
                supportsDryRun: true,
            ),
            new ActionDefinition(
                key: 'wordpress.article.publish',
                name: 'Publish article to WordPress',
                description: 'Explicit WordPress publish. Requires PublishIntent. Phase 2: internal_only (chưa expose UI/workflow).',
                module: 'wordpress',
                sideEffect: ActionSideEffect::ExternalWrite,
                riskLevel: ActionRiskLevel::Critical,
                selectability: ActionSelectability::InternalOnly,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
                idempotent: true,
                lockScope: 'article',
                supportsDryRun: true,
                impliesPublishStatus: true,
                emittedEvents: ['wordpress.article_published'],
            ),
            new ActionDefinition(
                key: 'wordpress.article.sync_outbound',
                name: 'WordPress outbound sync (legacy)',
                description: 'Legacy hub outbound. Implies WP status=publish. Not selectable for workflow/rule.',
                module: 'wordpress',
                sideEffect: ActionSideEffect::ExternalWrite,
                riskLevel: ActionRiskLevel::Critical,
                selectability: ActionSelectability::LegacyNotSelectable,
                inputSchema: [
                    'article_id' => ['type' => 'integer', 'required' => true],
                ],
                impliesPublishStatus: true,
                emittedEvents: ['wordpress.article_updated'],
            ),
            new ActionDefinition(
                key: 'wordpress.comment_review.publish',
                name: 'Publish comment review to WordPress',
                description: 'Publish one local product review to WordPress virtual-comments. Idempotent via _omi_review_id.',
                module: 'wordpress',
                sideEffect: ActionSideEffect::ExternalWrite,
                riskLevel: ActionRiskLevel::High,
                selectability: ActionSelectability::Selectable,
                inputSchema: [
                    'site_id' => ['type' => 'integer', 'required' => true],
                    'connection_id' => ['type' => 'integer', 'required' => true],
                    'article_id' => ['type' => 'integer', 'required' => true],
                    'review_id' => ['type' => 'integer', 'required' => true],
                    'wp_post_id' => ['type' => 'integer', 'required' => false],
                    'publish_intent' => ['type' => 'string', 'required' => true],
                ],
                idempotent: true,
                lockScope: 'review',
                supportsDryRun: false,
                emittedEvents: ['wordpress.comment_review_published', 'wordpress.comment_review_publish_failed'],
            ),
        ];
    }
}
