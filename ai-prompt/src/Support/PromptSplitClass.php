<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum PromptSplitClass: string
{
    case DirectFit = 'direct_fit';
    case SemanticSplit = 'semantic_split';
    case Compactable = 'compactable';
    case Unsplittable = 'unsplittable';
    case BusinessSplit = 'business_split';
}
