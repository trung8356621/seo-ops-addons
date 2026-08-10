<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Phân loại row trong seo_project_run_items theo cột action.
 *
 * - Article: execution item của pipeline bài (SeoProjectRunAction).
 * - WorkflowStep: retry/control step (action LIKE step:%).
 * - Helper: mọi action khác (control/metadata) — không đếm counter, không dispatch.
 */
enum SeoProjectRunItemKind: string
{
    case Article = 'article';
    case WorkflowStep = 'workflow_step';
    case Helper = 'helper';

    public function isArticleExecution(): bool
    {
        return $this === self::Article;
    }

    public function isTerminalNeutralCandidate(): bool
    {
        return $this === self::WorkflowStep || $this === self::Helper;
    }
}
