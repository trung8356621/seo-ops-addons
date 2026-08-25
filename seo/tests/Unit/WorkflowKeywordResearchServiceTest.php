<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use PHPUnit\Framework\TestCase;

final class WorkflowKeywordResearchServiceTest extends TestCase
{
    private function makeService(): WorkflowKeywordResearchService
    {
        return new WorkflowKeywordResearchService(
            new CtaKeywordBlacklistFilter(
                SeoKeywordSettingsService::withDefaults(),
                new OutlineSkipListMatcher,
            ),
            new KeywordPersistenceService(new KeywordMetaRepository),
            new TagPersistenceService,
            null,
        );
    }

    public function test_should_sync_keywords_for_dedicated_action(): void
    {
        $service = $this->makeService();
        $state = new \Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;

        $this->assertTrue($service->shouldSyncKeywords('save_vocabulary_research', $state));
    }

    public function test_should_sync_when_parsed_groups_exist(): void
    {
        $service = $this->makeService();
        $state = new \Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;
        $state->setParsedKeywords(['Synonyms' => ['xưởng may']]);

        $this->assertTrue($service->shouldSyncKeywords('create_article', $state));
    }

    public function test_partition_keyword_groups_extracts_related_topics(): void
    {
        $service = $this->makeService();

        [$clusterGroups, $relatedTopics, $holonymyPhrases] = $service->partitionKeywordGroups([
            'Synonyms' => ['ba lô học sinh'],
            'Related topics' => [
                'Cách chọn cặp theo phong thủy',
                'Review các loại balo học sinh',
            ],
            'RELATED TOPICS' => ['Top quà tặng cho người cung Cự Giải'],
            'Holonymy' => ['Sản phẩm đóng gói', 'Bao bì'],
        ]);

        $this->assertSame(['ba lô học sinh'], $clusterGroups['Synonyms']);
        $this->assertCount(3, $relatedTopics);
        $this->assertSame('Cách chọn cặp theo phong thủy', $relatedTopics[0]);
        $this->assertArrayNotHasKey('Related topics', $clusterGroups);
        $this->assertArrayNotHasKey('Holonymy', $clusterGroups);
        $this->assertSame(['Sản phẩm đóng gói', 'Bao bì'], $holonymyPhrases);
    }

    public function test_partition_keyword_groups_treats_holonymy_case_insensitively(): void
    {
        $service = $this->makeService();

        [, , $holonymyPhrases] = $service->partitionKeywordGroups([
            '### Holonymy' => ['Ngành bao bì'],
        ]);

        $this->assertSame(['Ngành bao bì'], $holonymyPhrases);
    }
}
