<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalPlanner;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCredential;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessTransport;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessUrlPolicy;
use Omnichannel\Addons\AgentRuntime\Retrieval\TopicGroupArticleSource;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentConfirmedToolExecutor;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorstArticlesTopicIntersectionTest extends TestCase
{
    #[Test]
    public function confirmed_low_score_articles_are_limited_to_the_topic_group(): void
    {
        config(['agent-runtime.local_tool_router.enabled' => true]);
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('listArticles')->willReturn([
            'items' => [
                ['article_ref' => 'article:1', 'seo_score' => 23],
                ['article_ref' => 'article:9', 'seo_score' => 10],
                ['article_ref' => 'article:2', 'seo_score' => 37],
            ],
            'total' => 3,
            'post_type' => null,
        ]);
        $this->app->instance(TopicGroupArticleSource::class, new class implements TopicGroupArticleSource {
            public function retrieve(int $siteId, string $query): array
            {
                return [
                    'status' => 'ok',
                    'groups' => [[
                        'ref' => 'g-0016',
                        'name' => 'balo học sinh',
                        'keywords' => [
                            ['phrase' => 'balo học sinh', 'article' => ['ref' => 'article:1', 'seo_score' => 23]],
                            ['phrase' => 'cặp học sinh', 'article' => ['ref' => 'article:2', 'seo_score' => 37]],
                        ],
                    ]],
                ];
            }
        });

        $bundle = (new AgentConfirmedToolExecutor($this->retrieval(), $audit))->execute($this->proposal(), 1);
        $articles = $bundle->sources[0];

        self::assertSame(['article:1', 'article:2'], array_column($articles->data['items'], 'article_ref'));
        self::assertSame(2, $articles->data['total']);
        self::assertTrue($articles->data['intersection']['applied']);
        self::assertSame('topic_groups', $bundle->sources[1]->name);
        self::assertStringContainsString('topic_group_intersection=1', $articles->request);
    }

    #[Test]
    public function local_router_off_keeps_the_full_audit_list(): void
    {
        config(['agent-runtime.local_tool_router.enabled' => false]);
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('listArticles')->willReturn([
            'items' => [
                ['article_ref' => 'article:1', 'seo_score' => 23],
                ['article_ref' => 'article:9', 'seo_score' => 10],
            ],
            'total' => 2,
            'post_type' => null,
        ]);

        $bundle = (new AgentConfirmedToolExecutor($this->retrieval(), $audit))->execute($this->proposal(), 1);

        self::assertCount(1, $bundle->sources);
        self::assertSame(2, $bundle->sources[0]->data['total']);
        self::assertArrayNotHasKey('intersection', $bundle->sources[0]->data);
    }

    private function proposal(): AgentToolConfirmationProposal
    {
        return new AgentToolConfirmationProposal(
            'Tìm những bài SEO kém liên quan đến balo học sinh',
            'seo_audit.worst_articles',
            ['seo_audit.worst_articles'],
            ['limit_max' => 50],
            'table',
            'vi',
            ['seo_audit.worst_articles'],
            ['type' => 'site', 'site_id' => 4],
        );
    }

    private function retrieval(): RetrievalExecutor
    {
        return new RetrievalExecutor(
            new RetrievalPlanner(),
            new SeoAccessExecutor(
                new class implements SeoAccessTransport {
                    public function request(string $method, string $url, array $query = [], ?string $bearer = null, ?array $jsonBody = null): array
                    {
                        throw new \RuntimeException('SEO Access must not be called by the intersection adapter.');
                    }
                },
                new class implements SeoAccessCredential {
                    public function bearer(): ?string
                    {
                        return null;
                    }
                },
                new SeoAccessUrlPolicy(),
                'https://app.example.test',
            ),
        );
    }
}
