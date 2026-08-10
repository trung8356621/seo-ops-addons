<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Contracts;

use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;

interface ArticleWritingSourceProvider
{
    public function sourceType(): ArticleWritingSourceType;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolve(
        array $variables,
        ?SeoArticle $article = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ArticleWritingInput;
}
