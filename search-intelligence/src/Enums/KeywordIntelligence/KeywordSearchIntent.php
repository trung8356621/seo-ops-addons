<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Search intent phân loại cho keyword.
 */
enum KeywordSearchIntent: string
{
    case Informational = 'informational';
    case Commercial = 'commercial';
    case Transactional = 'transactional';
    case Navigational = 'navigational';
    case Local = 'local';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
