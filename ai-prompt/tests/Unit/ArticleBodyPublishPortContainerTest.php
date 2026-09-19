<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Agent\AgentServiceProvider;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Tests\TestCase;

final class ArticleBodyPublishPortContainerTest extends TestCase
{
    public function test_queue_dependency_graph_resolves_article_body_publisher(): void
    {
        $this->app->register(AgentServiceProvider::class);
        $this->app->register(SeoContentAiServiceProvider::class);

        self::assertTrue($this->app->bound(ArticleBodyPublishPort::class));
        self::assertInstanceOf(
            PromptTestPublishService::class,
            $this->app->make(ArticleBodyPublishPort::class),
        );

        self::assertInstanceOf(
            ArticleWritingExecutionService::class,
            $this->app->make(ArticleWritingExecutionService::class),
        );
        self::assertInstanceOf(
            ContentProjectRunEngine::class,
            $this->app->make(ContentProjectRunEngine::class),
        );
    }
}
