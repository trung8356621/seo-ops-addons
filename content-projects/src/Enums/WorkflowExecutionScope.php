<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Runtime execution scope for workflow graph runs.
 * FULL RERUN Phase-1 uses OutlineVocabulary — never Content/Image/SEO/body persist.
 */
enum WorkflowExecutionScope: string
{
    case Full = 'full';
    case OutlineVocabulary = 'outline_vocabulary';

    public function isOutlineVocabularyOnly(): bool
    {
        return $this === self::OutlineVocabulary;
    }
}
