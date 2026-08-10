<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscScopeType: string
{
    case Site = 'site';
    case Page = 'page';
    case Article = 'article';
    case Keyword = 'keyword';
    case Cluster = 'cluster';
    case Topic = 'topic';
    case ContentProject = 'content_project';
    case ProjectItem = 'project_item';
}
