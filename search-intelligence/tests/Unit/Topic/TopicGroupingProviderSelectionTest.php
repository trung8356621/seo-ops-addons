<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\SearchIntelligenceServiceProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\SemanticHttpTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Tests\TestCase;

final class TopicGroupingProviderSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(SearchIntelligenceServiceProvider::class);
    }

    public function test_legacy_config_resolves_legacy_provider(): void
    {
        config(['semantic.topic_provider' => 'legacy']);
        $this->app->forgetInstance(TopicGroupingProvider::class);
        self::assertTrue(TopicGroupingProviderMode::isLegacy());
        self::assertInstanceOf(LegacyTopicGroupingProvider::class, $this->app->make(TopicGroupingProvider::class));
    }

    public function test_semantic_config_resolves_semantic_provider(): void
    {
        config([
            'semantic.topic_provider' => 'semantic_http',
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
        ]);
        $this->app->forgetInstance(TopicGroupingProvider::class);
        $this->app->forgetInstance(SemanticHttpTopicGroupingProvider::class);
        $this->app->forgetInstance(SemanticAnalyticsClient::class);
        self::assertTrue(TopicGroupingProviderMode::isSemanticHttp());
        self::assertInstanceOf(SemanticHttpTopicGroupingProvider::class, $this->app->make(TopicGroupingProvider::class));
    }
}
