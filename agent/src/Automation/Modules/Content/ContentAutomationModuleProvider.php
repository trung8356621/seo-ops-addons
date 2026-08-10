<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\Content;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\ArticleGenerateContentHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncKeywordDomainLinkListHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class ContentAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'content';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            [BusinessEventName::ArticleCreated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'post_type' => false, 'status' => false]],
            [BusinessEventName::ArticleContentUpdated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticleSeoMetaUpdated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticleApproved, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'project_id' => false]],
            [BusinessEventName::ArticleCompleted, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'project_id' => false, 'status' => false]],
            [BusinessEventName::ArticleArchived, SeoArticle::class, 'content', ['article_id' => true]],
            [BusinessEventName::ArticleRestored, SeoArticle::class, 'content', ['article_id' => true]],
            [BusinessEventName::ArticleDeleted, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticlePublishRequested, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticleProductReviewsGenerated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'connection_id' => false, 'review_ids' => true, 'review_count' => false]],
            [BusinessEventName::ArticleProductReviewPublishRequested, SeoArticle::class, 'content', ['article_id' => true, 'review_id' => true, 'site_id' => true, 'connection_id' => true, 'publish_intent' => false]],

            [BusinessEventName::ContentProjectTaskCreated, SeoProjectTask::class, 'project', ['task_id' => true, 'project_id' => false, 'site_id' => false]],
            [BusinessEventName::ContentProjectTaskUpdated, SeoProjectTask::class, 'project', ['task_id' => true]],
            [BusinessEventName::ContentProjectTaskCompleted, SeoProjectTask::class, 'project', ['task_id' => true, 'article_id' => false, 'project_id' => false]],
            [BusinessEventName::ContentProjectTaskFailed, SeoProjectTask::class, 'project', ['task_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectTaskArchived, SeoProjectTask::class, 'project', ['task_id' => true]],

            [BusinessEventName::ContentProjectRunStarted, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectRunCompleted, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectRunFailed, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],

            [BusinessEventName::KeywordSaved, Keyword::class, 'keyword', ['keyword_id' => true, 'site_id' => true, 'phrase' => true, 'target_url' => false, 'previous_phrase' => false, 'operation' => false]],
        ] as [$enum, $subject, $module, $fields]) {
            /** @var BusinessEventName $enum */
            $schema = [];
            foreach ($fields as $field => $required) {
                $schema[$field] = ['type' => 'mixed', 'required' => (bool) $required];
            }

            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: $subject,
                payloadSchema: $schema,
                description: $enum->value,
                module: $module,
            ));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleGenerateContent->value,
            handlerClass: ArticleGenerateContentHookAction::class,
            inputRules: [
                'task_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [],
            description: 'Generate article content for a content-project task.',
            isAsyncSafe: true,
            timeout: 180,
            module: 'content',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'ai',
            maxAttemptsPerMinute: 20,
            fieldMeta: [
                'task_id' => ['label' => 'Task ID', 'type' => 'integer', 'source' => 'input'],
            ],
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::KeywordDomainLinkListSync->value,
            handlerClass: SyncKeywordDomainLinkListHookAction::class,
            inputRules: [
                'keyword_id' => ['type' => 'integer', 'required' => false],
                'site_id' => ['type' => 'integer', 'required' => false],
                'phrase' => ['type' => 'string', 'required' => false],
                'target_url' => ['type' => 'string', 'required' => false],
                'previous_phrase' => ['type' => 'string', 'required' => false],
                'operation' => ['type' => 'string', 'required' => false],
            ],
            settingsRules: [],
            description: 'Sync keyword phrase into domain link list (idempotent).',
            isAsyncSafe: true,
            timeout: 60,
            module: 'keyword',
            defaultQueue: AutomationQueueName::Critical->value,
            fieldMeta: [
                'keyword_id' => ['label' => 'Keyword ID', 'type' => 'integer', 'source' => 'payload'],
                'site_id' => ['label' => 'Site ID', 'type' => 'integer', 'source' => 'payload'],
                'phrase' => ['label' => 'Phrase', 'type' => 'string', 'source' => 'payload'],
            ],
        ));
    }
}
