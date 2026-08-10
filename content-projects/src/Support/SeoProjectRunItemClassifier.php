<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemKind;

/**
 * Single source of truth: phân loại seo_project_run_items theo cột `action`.
 */
final class SeoProjectRunItemClassifier
{
    public const STEP_ACTION_PREFIX = 'step:';

    /**
     * @return list<string>
     */
    public static function articleActionValues(): array
    {
        return SeoProjectRunAction::values();
    }

    public static function classify(?string $action): SeoProjectRunItemKind
    {
        $normalized = trim((string) $action);
        if ($normalized === '') {
            return SeoProjectRunItemKind::Helper;
        }

        if (str_starts_with($normalized, self::STEP_ACTION_PREFIX)) {
            return SeoProjectRunItemKind::WorkflowStep;
        }

        if (in_array($normalized, self::articleActionValues(), true)) {
            return SeoProjectRunItemKind::Article;
        }

        return SeoProjectRunItemKind::Helper;
    }

    public static function isArticleExecution(?string $action): bool
    {
        return self::classify($action)->isArticleExecution();
    }

    public static function isWorkflowStep(?string $action): bool
    {
        return self::classify($action) === SeoProjectRunItemKind::WorkflowStep;
    }

    public static function isHelperOrControl(?string $action): bool
    {
        return self::classify($action)->isTerminalNeutralCandidate();
    }
}
