<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

use Omnichannel\Addons\Agent\Automation\Enums\MigrationMode;

/**
 * Per-caller flags. Full cutover = Action.
 * Emergency: AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true → mọi caller Legacy.
 */
final class AutomationMigrationFlags
{
    public const SEO_ISSUE_ASSIGNMENT = 'seo_issue_assignment';

    public const KEYWORD_PROJECT_ASSIGNMENT = 'keyword_project_assignment';

    public const PROJECT_ARTICLE_ATTACH = 'project_article_attach';

    public const PROJECT_TASK_COMPLETE = 'project_task_complete';

    public const PROJECT_ARTICLE_CREATE = 'project_article_create';

    public const PROJECT_ARTICLE_CONTENT_UPDATE = 'project_article_content_update';

    public const PROJECT_ARTICLE_SEO_META_UPDATE = 'project_article_seo_meta_update';

    /** @var list<string> */
    public const ALL = [
        self::SEO_ISSUE_ASSIGNMENT,
        self::KEYWORD_PROJECT_ASSIGNMENT,
        self::PROJECT_ARTICLE_ATTACH,
        self::PROJECT_TASK_COMPLETE,
        self::PROJECT_ARTICLE_CREATE,
        self::PROJECT_ARTICLE_CONTENT_UPDATE,
        self::PROJECT_ARTICLE_SEO_META_UPDATE,
    ];

    public function mode(string $callerKey): MigrationMode
    {
        unset($callerKey);

        return MigrationMode::fromConfig(null);
    }

    public function isLegacy(string $callerKey): bool
    {
        return $this->mode($callerKey) === MigrationMode::Legacy;
    }
}
