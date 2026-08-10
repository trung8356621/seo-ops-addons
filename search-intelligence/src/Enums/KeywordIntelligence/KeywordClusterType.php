<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Loại cluster keyword.
 */
enum KeywordClusterType: string
{
    case Pillar = 'pillar';
    case Cluster = 'cluster';
    case Supporting = 'supporting';
    case Faq = 'faq';
    case Comparison = 'comparison';
    case Commercial = 'commercial';
    case Transactional = 'transactional';
    case Local = 'local';
}
