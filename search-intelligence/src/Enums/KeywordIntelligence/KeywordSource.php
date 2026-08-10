<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Nguồn gốc của một keyword trong workspace.
 */
enum KeywordSource: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case Api = 'api';
    case Agent = 'agent';
    case ContentProject = 'content_project';
    case ExistingContent = 'existing_content';
}
