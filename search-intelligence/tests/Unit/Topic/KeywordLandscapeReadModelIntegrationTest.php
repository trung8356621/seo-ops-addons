<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use Tests\TestCase;

/**
 * Runtime Keyword Landscape SSOT + Keyword MCP source restore.
 * Requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai Topic tables.
 */
final class KeywordLandscapeReadModelIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! TopicReclusterService::tablesReady()) {
            $this->fail('Topic Core tables missing on omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna')) {
            $this->fail('Missing seo_topic_keyword_dna.');
        }
    }

    public function test_for_site_returns_topics_mcp_and_dna_site_isolated(): void
    {
        $siteA = $this->uniqueSiteId();
        $siteB = $this->uniqueSiteId();
        $topicA = $this->createTopic($siteA, 'Landscape A '.$this->seq, TopicSource::AUTO);
        $this->attachDna($siteA, (int) $topicA->id, 'dna-a-'.$this->seq);
        $this->createTopic($siteB, 'Landscape B '.$this->seq, TopicSource::AUTO);

        $readModel = app(KeywordLandscapeReadModel::class);
        $landscapeA = $readModel->forSite($siteA, true);
        $landscapeB = $readModel->forSite($siteB, true);

        $idsA = array_map(static fn ($t): int => $t->id, $landscapeA->topics);
        $idsB = array_map(static fn ($t): int => $t->id, $landscapeB->topics);

        self::assertContains((int) $topicA->id, $idsA);
        self::assertNotContains((int) $topicA->id, $idsB);

        $found = $landscapeA->findById((int) $topicA->id);
        self::assertNotNull($found);
        self::assertSame('Landscape A '.$this->seq, $found->name);
        self::assertGreaterThanOrEqual(1, $found->dnaCount);
        self::assertSame('dna-a-'.$this->seq, $found->dna[0]['phrase'] ?? null);
        self::assertIsFloat($found->mcp);

        self::assertNull($readModel->findTopic($siteB, (int) $topicA->id));
    }

    public function test_mcp_payload_hash_stable_and_keyword_source_not_empty(): void
    {
        $siteId = $this->uniqueSiteId();
        $topic = $this->createTopic($siteId, 'Hash Topic '.$this->seq, TopicSource::MANUAL);
        $this->attachDna($siteId, (int) $topic->id, 'hash-dna-'.$this->seq);

        $readModel = app(KeywordLandscapeReadModel::class);
        $first = $readModel->forSite($siteId, true)->toMcpPayloadParts();
        $second = $readModel->forSite($siteId, true)->toMcpPayloadParts();
        self::assertSame($first, $second);
        self::assertGreaterThanOrEqual(1, $first['metrics']['topic_count']);
        self::assertNotSame([], $first['context']['topics']);

        $site = new \App\Models\Site;
        $site->id = $siteId;
        $site->domain = 'landscape-test.example';

        $period = new SeoMcpPeriod([
            'year' => 2026,
            'month' => 9,
            'status' => 'open',
        ]);

        $source = app(KeywordMonthlyMcpSource::class);
        self::assertSame('v2', $source->schemaVersion());
        self::assertSame(McpSourceKey::Keywords->value, $source->key());
        self::assertSame('keywords.mcp.v2', McpSourceKey::Keywords->schema());

        $payload = $source->build($site, $period);
        self::assertSame('keywords.mcp.v2', $payload->schema);
        self::assertNotSame([], $payload->metrics);
        self::assertArrayHasKey('topic_count', $payload->metrics);
        self::assertNotSame([], $payload->context['topics'] ?? []);
        self::assertNotSame('', $payload->contentHash);

        $again = $source->build($site, $period);
        self::assertSame($payload->contentHash, $again->contentHash);
        self::assertNotNull($source->sourceUpdatedAt($site));
    }

    public function test_empty_site_yields_empty_landscape(): void
    {
        $siteId = $this->uniqueSiteId();
        $landscape = app(KeywordLandscapeReadModel::class)->forSite($siteId, true);
        self::assertSame(0, $landscape->topicCount());
        self::assertSame([], $landscape->toMcpPayloadParts()['context']['topics']);
    }

    private function uniqueSiteId(): int
    {
        $this->seq++;

        return 9_100_000 + ((int) (microtime(true) * 1000) % 100_000) + $this->seq;
    }

    private function createTopic(int $siteId, string $name, string $source): SeoTopic
    {
        return SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => $name,
            'source' => $source,
            'status' => TopicStatus::ACTIVE,
            'is_locked' => false,
        ]);
    }

    private function attachDna(int $siteId, int $topicId, string $value): void
    {
        SeoTopicKeywordDna::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => 900000 + (++$this->seq),
            'value' => $value,
            'facet_type' => 'branch',
            'placement' => 'after',
            'frequency' => 1,
            'source' => 'test',
        ]);
    }
}
