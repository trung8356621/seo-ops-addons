<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Registry;

use Omnichannel\Addons\Agent\Automation\Actions\Article\ApproveArticleAction;
use Omnichannel\Addons\Agent\Automation\Actions\Article\CreateArticleAction;
use Omnichannel\Addons\Agent\Automation\Actions\Article\UpdateArticleContentAction;
use Omnichannel\Addons\Agent\Automation\Actions\Article\UpdateArticleSeoMetaAction;
use Omnichannel\Addons\Agent\Automation\Actions\Foundation\PingAction;
use Omnichannel\Addons\Agent\Automation\Actions\Keyword\AssignKeywordToProjectAction;
use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SaveKeywordVocabularyAction;
use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SyncKeywordDomainLinkListAction;
use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SyncKeywordTopicClusterAction;
use Omnichannel\Addons\Agent\Automation\Actions\Project\AttachArticleToProjectTaskAction;
use Omnichannel\Addons\Agent\Automation\Actions\Project\CreateProjectTaskAction;
use Omnichannel\Addons\Agent\Automation\Actions\Project\MarkProjectTaskCompletedAction;
use Omnichannel\Addons\Agent\Automation\Actions\PromptResult\AttachPromptResultAction;
use Omnichannel\Addons\Agent\Automation\Actions\Seo\CreateProjectTaskFromSeoIssueAction;
use Omnichannel\Addons\Agent\Automation\Actions\Seo\RunSeoAuditAction;
use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;

final class ActionHandlerRegistrar
{
    /**
     * @return list<class-string<BusinessAction>>
     */
    public static function handlers(): array
    {
        return [
            PingAction::class,
            CreateArticleAction::class,
            UpdateArticleContentAction::class,
            UpdateArticleSeoMetaAction::class,
            ApproveArticleAction::class,
            CreateProjectTaskAction::class,
            AttachArticleToProjectTaskAction::class,
            MarkProjectTaskCompletedAction::class,
            RunSeoAuditAction::class,
            CreateProjectTaskFromSeoIssueAction::class,
            AssignKeywordToProjectAction::class,
            SaveKeywordVocabularyAction::class,
            SyncKeywordTopicClusterAction::class,
            SyncKeywordDomainLinkListAction::class,
            AttachPromptResultAction::class,
        ];
    }

    public function register(ActionRegistry $registry): void
    {
        foreach (self::handlers() as $handlerClass) {
            $registry->registerHandler($handlerClass);
        }
    }
}
