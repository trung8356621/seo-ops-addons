<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscCannibalizationType: string
{
    case CompetingPages = 'competing_pages';
    case AlternatingPage = 'alternating_page';
    case ExpectedMultiPage = 'expected_multi_page';
}
